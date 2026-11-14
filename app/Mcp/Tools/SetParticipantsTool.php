<?php

namespace App\Mcp\Tools;

use App\Models\Quote;
use App\Models\User;
use App\Services\Business\AdjudicationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('set_quote_participants')]
#[Title('Registrar participantes de la oferta')]
#[Description('Registra los participantes de una oferta con el monto de cada uno; marque la propia con `nuestra_oferta`. Envíe la lista completa en una sola llamada.')]
#[IsDestructive(false)]
#[IsIdempotent]
class SetParticipantsTool extends BaseTool
{
    protected ?array $record = ['quote_id', Quote::class];

    public function schema(JsonSchema $schema): array
    {
        return [
            'quote_id' => $schema->integer()->description('Id de la cotización.')->required(),
            ...$this->companySchema($schema),
            'participantes' => $schema->array()->description('[{nombre, rtn?, monto, moneda?, incluye_impuesto?, nuestra_oferta?, posicion?, notas?}].')->required(),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $quote = $this->find(Quote::class, $request->get('quote_id'), 'la cotización');
        $this->authorize('update', $quote);

        $data = $this->validate($request->all(), [
            'participantes' => ['required', 'array', 'min:1', 'max:50'],
            'participantes.*.nombre' => ['required', 'string', 'max:255'],
            'participantes.*.rtn' => ['nullable', 'string', 'max:100'],
            'participantes.*.monto' => ['required', 'numeric', 'min:0'],
            'participantes.*.moneda' => ['nullable', 'string', 'max:3'],
            'participantes.*.incluye_impuesto' => ['nullable', 'boolean'],
            'participantes.*.nuestra_oferta' => ['nullable', 'boolean'],
            'participantes.*.posicion' => ['nullable', 'integer', 'min:1', 'max:100'],
            'participantes.*.notas' => ['nullable', 'string', 'max:2000'],
        ]);

        $updated = app(AdjudicationService::class)->syncParticipants($quote, array_map(fn (array $participant) => [
            'name' => $participant['nombre'],
            'tax_id' => $participant['rtn'] ?? null,
            'amount' => $participant['monto'],
            'currency' => $participant['moneda'] ?? 'HNL',
            'includes_tax' => $participant['incluye_impuesto'] ?? true,
            'is_ours' => $participant['nuestra_oferta'] ?? false,
            'position' => $participant['posicion'] ?? null,
            'notes' => $participant['notas'] ?? null,
        ], $data['participantes']));

        return $this->ok([
            'cotizacion_id' => $updated->id,
            'participantes' => $updated->participants->map(fn ($participant) => [
                'nombre' => $participant->name,
                'monto' => (float) $participant->amount,
                'nuestra_oferta' => (bool) $participant->is_ours,
            ])->all(),
        ], 'Participantes registrados.');
    }
}
