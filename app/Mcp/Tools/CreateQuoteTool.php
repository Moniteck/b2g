<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ValidatesQuoteItems;
use App\Models\Contact;
use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\Quote;
use App\Models\Tender;
use App\Models\User;
use App\Rules\BelongsToActiveCompany;
use App\Services\Business\QuoteService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('create_quote')]
#[Title('Crear cotización')]
#[Description('Crea una cotización en Borrador. Cada línea puede referenciar un producto del inventario (`inventory_item_id` o `sku`) para tomar su precio con el calculador único; el ISV se calcula según la regla de la empresa. Los ids de licitación, oportunidad e inventario deben ser de la empresa.')]
#[IsDestructive(false)]
class CreateQuoteTool extends BaseTool
{
    use ValidatesQuoteItems;

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->companySchema($schema),
            'institucion_id' => $schema->integer()->description('Institución destinataria.'),
            'contacto_id' => $schema->integer()->description('Contacto de la institución.'),
            'oportunidad_id' => $schema->integer()->description('Oportunidad relacionada.'),
            'licitacion_id' => $schema->integer()->description('Licitación relacionada.'),
            'tipo' => $schema->string()->description('Tipo de compra o proceso.'),
            'validez_dias' => $schema->integer()->description('Días de vigencia de la oferta (por defecto 30).'),
            'notas' => $schema->string()->description('Observaciones.'),
            'items' => $schema->array()->description('Líneas: [{descripcion, cantidad, unidad?, precio_unitario?, inventory_item_id?, sku?, tasa_impuesto?}].')->required(),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $this->authorize('create', Quote::class);

        $data = $this->validate($request->all(), [
            'institucion_id' => ['nullable', 'integer', new BelongsToActiveCompany(Institution::class)],
            'contacto_id' => ['nullable', 'integer', new BelongsToActiveCompany(Contact::class)],
            'oportunidad_id' => ['nullable', 'integer', new BelongsToActiveCompany(Opportunity::class)],
            'licitacion_id' => ['nullable', 'integer', new BelongsToActiveCompany(Tender::class)],
            'tipo' => ['nullable', 'string', 'max:100'],
            'validez_dias' => ['nullable', 'integer', 'min:1', 'max:365'],
            'notas' => ['nullable', 'string', 'max:5000'],
            ...$this->itemRules(true),
        ]);

        $quote = app(QuoteService::class)->create($companyId, $user->id, [
            'institution_id' => $data['institucion_id'] ?? null,
            'contact_id' => $data['contacto_id'] ?? null,
            'opportunity_id' => $data['oportunidad_id'] ?? null,
            'tender_id' => $data['licitacion_id'] ?? null,
            'purchase_type' => $data['tipo'] ?? null,
            'validity_days' => $data['validez_dias'] ?? 30,
            'notes' => $data['notas'] ?? null,
            'items' => $this->mapItems($data['items']),
        ]);

        return $this->ok([
            'cotizacion_id' => $quote->id,
            'numero' => $quote->number,
            'subtotal' => (float) $quote->subtotal,
            'impuesto' => (float) $quote->tax,
            'total' => (float) $quote->total,
            'estado' => $quote->status,
        ], 'Cotización creada en borrador.');
    }
}
