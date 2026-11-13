<?php

namespace App\Mcp\Tools;

use App\Services\Business\QuoteService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('create_quote')]
#[Title('Crear cotizacion')]
#[Description('Crea una cotizacion en borrador. Los precios de referencia de Blessing ya incluyen impuesto: indique el precio unitario final por linea y no se volvera a sumar ningun impuesto. Cada linea puede referenciar un producto del inventario por `inventory_item_id` o `sku`, en cuyo caso el precio se toma del catalogo.')]
class CreateQuoteTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'empresa_id' => $schema->integer()->description('Empresa emisora. Omitirlo usa la empresa activa.'),
            'institucion_id' => $schema->integer()->description('Institucion destinataria de la oferta.'),
            'contacto_id' => $schema->integer()->description('Contacto de la institucion.'),
            'oportunidad_id' => $schema->integer()->description('Oportunidad del pipeline relacionada.'),
            'licitacion_id' => $schema->integer()->description('Licitacion relacionada.'),
            'tipo' => $schema->string()->description('Tipo de compra o proceso.'),
            'validez_dias' => $schema->integer()->description('Dias de vigencia de la oferta (por defecto 30).'),
            'notas' => $schema->string()->description('Observaciones de la cotizacion.'),
            'items' => $schema->array()->description('Lineas de la cotizacion.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $user = $this->user($request);
        $companyId = $this->resolveCompanyId($request, $request->get('empresa_id') ? (int) $request->get('empresa_id') : null);

        $data = $request->validate([
            'institucion_id' => 'nullable|integer|exists:institutions,id',
            'contacto_id' => 'nullable|integer|exists:contacts,id',
            'oportunidad_id' => 'nullable|integer|exists:opportunities,id',
            'licitacion_id' => 'nullable|integer|exists:tenders,id',
            'tipo' => 'nullable|string|max:100',
            'validez_dias' => 'nullable|integer|min:1|max:365',
            'notas' => 'nullable|string|max:5000',
            'items' => 'required|array|min:1',
            'items.*.descripcion' => 'required|string|max:255',
            'items.*.cantidad' => 'required|numeric|gt:0',
            'items.*.unidad' => 'nullable|string|max:50',
            'items.*.precio_unitario' => 'nullable|numeric|min:0',
            'items.*.costo_unitario' => 'nullable|numeric|min:0',
            'items.*.margen' => 'nullable|numeric|min:0|max:95',
            'items.*.tasa_impuesto' => 'nullable|numeric|min:0|max:100',
            'items.*.inventory_item_id' => 'nullable|integer|exists:inventory_items,id',
            'items.*.sku' => 'nullable|string|max:100',
        ]);

        $items = array_map(function (array $item) {
            return [
                'description' => $item['descripcion'],
                'quantity' => $item['cantidad'],
                'unit' => $item['unidad'] ?? 'Unidad',
                'unit_price' => $item['precio_unitario'] ?? null,
                'unit_cost' => $item['costo_unitario'] ?? null,
                'margin' => $item['margen'] ?? 0,
                'tax_rate' => $item['tasa_impuesto'] ?? null,
                'inventory_item_id' => $item['inventory_item_id'] ?? null,
                'sku' => $item['sku'] ?? null,
            ];
        }, $data['items']);

        $quote = app(QuoteService::class)->create($companyId, $user->id, [
            'institution_id' => $data['institucion_id'] ?? null,
            'contact_id' => $data['contacto_id'] ?? null,
            'opportunity_id' => $data['oportunidad_id'] ?? null,
            'tender_id' => $data['licitacion_id'] ?? null,
            'purchase_type' => $data['tipo'] ?? 'Cotizacion',
            'validity_days' => $data['validez_dias'] ?? 30,
            'notes' => $data['notas'] ?? null,
            'items' => $items,
            'pricing_source' => 'blessing',
        ]);

        return $this->ok([
            'cotizacion_id' => $quote->id,
            'numero' => $quote->number,
            'total' => (float) $quote->total,
            'impuesto_contenido' => (float) $quote->tax,
            'estado' => $quote->status,
        ], 'Cotizacion creada en borrador.');
    }
}
