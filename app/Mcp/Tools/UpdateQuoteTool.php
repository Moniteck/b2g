<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ValidatesQuoteItems;
use App\Models\Contact;
use App\Models\Institution;
use App\Models\Quote;
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
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('update_quote')]
#[Title('Actualizar cotización')]
#[Description('Actualiza una cotización editable (Borrador o Emitida, sin resultado). Una cotización Presentada no se modifica: se sustituye por una revisión. Si se envían `items`, reemplazan todas las líneas y los importes se recalculan.')]
#[IsDestructive(false)]
#[IsIdempotent]
class UpdateQuoteTool extends BaseTool
{
    use ValidatesQuoteItems;

    protected ?array $record = ['quote_id', Quote::class];

    public function schema(JsonSchema $schema): array
    {
        return [
            'quote_id' => $schema->integer()->description('Id de la cotización.')->required(),
            ...$this->companySchema($schema),
            'institucion_id' => $schema->integer()->description('Institución destinataria.'),
            'contacto_id' => $schema->integer()->description('Contacto.'),
            'tipo' => $schema->string()->description('Tipo de compra o proceso.'),
            'validez_dias' => $schema->integer()->description('Días de vigencia.'),
            'notas' => $schema->string()->description('Observaciones.'),
            'items' => $schema->array()->description('Líneas completas (reemplazan las actuales).'),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $quote = $this->find(Quote::class, $request->get('quote_id'), 'la cotización');
        $this->authorize('update', $quote);

        $data = $this->validate($request->all(), [
            'institucion_id' => ['nullable', 'integer', new BelongsToActiveCompany(Institution::class)],
            'contacto_id' => ['nullable', 'integer', new BelongsToActiveCompany(Contact::class)],
            'tipo' => ['nullable', 'string', 'max:100'],
            'validez_dias' => ['nullable', 'integer', 'min:1', 'max:365'],
            'notas' => ['nullable', 'string', 'max:5000'],
            ...$this->itemRules(false),
        ]);

        $payload = array_filter([
            'institution_id' => $data['institucion_id'] ?? null,
            'contact_id' => $data['contacto_id'] ?? null,
            'purchase_type' => $data['tipo'] ?? null,
            'validity_days' => $data['validez_dias'] ?? null,
            'notes' => $data['notas'] ?? null,
        ], fn ($value) => $value !== null);

        if (isset($data['items'])) {
            $payload['items'] = $this->mapItems($data['items']);
        }

        $updated = app(QuoteService::class)->update($quote, $payload);

        return $this->ok([
            'cotizacion_id' => $updated->id,
            'numero' => $updated->number,
            'estado' => $updated->status,
            'total' => (float) $updated->total,
        ], 'Cotización actualizada.');
    }
}
