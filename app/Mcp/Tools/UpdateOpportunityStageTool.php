<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\ToolError;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Notifications\CalendarSyncService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('update_opportunity_stage')]
#[Title('Mover oportunidad en el tablero')]
#[Description('Mueve una oportunidad entre Prospecto, Oportunidad y Oferta Presentada, y actualiza su próxima acción, monto, probabilidad o cierre esperado. NO registra resultados: Adjudicado y No Adjudicado solo se alcanzan con register_outcome (que crea cliente y contrato), y salir de ellos exige revertir el resultado (revert_outcome).')]
#[IsDestructive(false)]
#[IsIdempotent]
class UpdateOpportunityStageTool extends BaseTool
{
    /** Etapas de resultado: solo `AdjudicationService` las fija o las revierte. */
    private const OUTCOME_STAGES = ['adjudicado', 'no_adjudicado'];

    protected ?array $record = ['opportunity_id', Opportunity::class];

    public function schema(JsonSchema $schema): array
    {
        return [
            'opportunity_id' => $schema->integer()->description('Id de la oportunidad.')->required(),
            ...$this->companySchema($schema),
            'stage' => $schema->string()->enum(array_values(array_diff(array_keys(Opportunity::STAGES), self::OUTCOME_STAGES)))->description('Nueva etapa.')->required(),
            'next_action' => $schema->string()->description('Próxima acción acordada.'),
            'amount' => $schema->number()->description('Monto estimado.'),
            'probability' => $schema->integer()->description('Probabilidad de cierre, 0 a 100.'),
            'expected_close_date' => $schema->string()->description('Cierre esperado «AAAA-MM-DD».'),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $opportunity = $this->find(Opportunity::class, $request->get('opportunity_id'), 'la oportunidad');
        $this->authorize('update', $opportunity);

        $data = $this->validate($request->all(), [
            'stage' => ['required', 'in:'.implode(',', array_keys(Opportunity::STAGES))],
            'next_action' => ['nullable', 'string', 'max:255'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'probability' => ['nullable', 'integer', 'between:0,100'],
            'expected_close_date' => ['nullable', 'date'],
        ]);

        if (in_array($data['stage'], self::OUTCOME_STAGES, true)) {
            throw new ToolError('Adjudicado y No Adjudicado son resultados: regístralos con register_outcome (con confirmación de la persona).');
        }

        if (in_array($opportunity->stage, self::OUTCOME_STAGES, true)) {
            throw new ToolError('La oportunidad ya tiene resultado ('.$opportunity->stageLabel().'). Para moverla hay que revertir el resultado (revert_outcome).');
        }

        $payload = ['stage' => $data['stage']];
        foreach (['next_action' => 'next_action', 'amount' => 'amount', 'probability' => 'probability', 'expected_close_date' => 'expected_close_date'] as $input => $column) {
            if (array_key_exists($input, $data) && $data[$input] !== null) {
                $payload[$column] = $data[$input];
            }
        }

        DB::transaction(fn () => Opportunity::query()->whereKey($opportunity->id)->lockForUpdate()->firstOrFail()->forceFill($payload)->save());
        $opportunity->refresh();
        app(CalendarSyncService::class)->syncForOpportunity($opportunity);
        app(AuditLogger::class)->record('oportunidad.etapa', $opportunity, ['stage' => $data['stage']]);

        return $this->ok([
            'opportunity_id' => $opportunity->id,
            'stage' => $opportunity->stage,
            'stage_label' => $opportunity->stageLabel(),
        ], 'Etapa actualizada.');
    }
}
