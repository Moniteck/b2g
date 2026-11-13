<?php

namespace App\Mcp\Tools;

use App\Models\Quote;
use App\Services\Business\QuoteService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('update_quote')]
#[Title('Actualizar cotizacion')]
#[Description('Actualiza una cotizacion que aun sea editable (Borrador o Emitida). Una cotizacion Presentada no se modifica: debe sustituirse por una revision nueva. Los importes se recalculan con la misma regla de precios con impuesto incluido.')]
class UpdateQuoteTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'quote_id' => $schema->integer()->description('Identificador de la cotizacion.')->required(),
            'institucion_id' => $schema->integer()->description('Nueva institucion destinataria.'),
            'contacto_id' => $schema->integer()->description('Nuevo contacto.'),
            'tipo' => $schema->string()->description('Tipo de compra o proceso.'),
            'validez_dias' => $schema->integer()->description('Dias de vigencia.'),
            'estado' => $schema->string()->enum(['Borrador', 'Emitida'])->description('Nuevo estado documental.'),
            'notas' => $schema->string()->description('Observaciones.'),
            'items' => $schema->array()->description('Lineas completas de la cotizacion.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $user = $this->user($request);
        $quote = Quote::query()->find((int) $request->get('quote_id'));

        if ($quote === null) {
            return $this->fail('La cotizacion indicada no existe.');
        }

        abort_unless($user->canAccessCompany($quote->company_id), 403, 'No tiene acceso a esta cotizacion.');

        if (! $quote->isEditable()) {
            return $this->fail('La cotizacion esta PRESENTADA y ya no admite ediciones. Cree una revision nueva.');
        }

        $data = $request->validate([
            'institucion_id' => 'nullable|integer|exists:institutions,id',
            'contacto_id' => 'nullable|integer|exists:contacts,id',
            'tipo' => 'nullable|string|max:100',
            'validez_dias' => 'nullable|integer|min:1|max:365',
            'estado' => 'nullable|in:Borrador,Emitida',
            'notas' => 'nullable|string|max:5000',
            'items' => 'nullable|array|min:1',
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

        $payload = array_filter([
            'institution_id' => $data['institucion_id'] ?? null,
            'contact_id' => $data['contacto_id'] ?? null,
            'purchase_type' => $data['tipo'] ?? null,
            'validity_days' => $data['validez_dias'] ?? null,
            'status' => $data['estado'] ?? null,
            'notes' => $data['notas'] ?? null,
        ], fn ($value) => $value !== null, ARRAY_FILTER_USE_BOTH);

        if (isset($data['items'])) {
            $payload['items'] = array_map(fn (array $item) => [
                'description' => $item['descripcion'],
                'quantity' => $item['cantidad'],
                'unit' => $item['unidad'] ?? 'Unidad',
                'unit_price' => $item['precio_unitario'] ?? null,
                'unit_cost' => $item['costo_unitario'] ?? null,
                'margin' => $item['margen'] ?? 0,
                'tax_rate' => $item['tasa_impuesto'] ?? null,
                'inventory_item_id' => $item['inventory_item_id'] ?? null,
                'sku' => $item['sku'] ?? null,
            ], $data['items']);
        }

        $updated = app(QuoteService::class)->update($quote, $payload);

        return $this->ok([
            'cotizacion_id' => $updated->id,
            'numero' => $updated->number,
            'estado' => $updated->status,
            'total' => (float) $updated->total,
        ], 'Cotizacion actualizada.');
    }
}
