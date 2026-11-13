<?php

namespace App\Mcp\Tools;

use App\Models\Opportunity;
use App\Services\Notifications\CalendarSyncService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('update_opportunity_stage')]
#[Title('Mover oportunidad en el tablero')]
#[Description('Cambia la etapa de una oportunidad del tablero comercial entre Prospecto, Oportunidad, Oferta Presentada, Adjudicado y No Adjudicado. Para las etapas de resultado use preferiblemente la herramienta de registro de resultado de la cotizacion, que ademas crea el cliente y el expediente post-adjudicacion.')]
class UpdateOpportunityStageTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'opportunity_id' => $schema->integer()->description('Identificador de la oportunidad.')->required(),
            'etapa' => $schema->string()->enum(array_keys(Opportunity::STAGES))->description('Nueva etapa.')->required(),
            'siguiente_accion' => $schema->string()->description('Proxima accion acordada.'),
            'monto' => $schema->number()->description('Monto estimado de la oportunidad.'),
            'probabilidad' => $schema->integer()->description('Probabilidad de cierre, de 0 a 100.'),
            'fecha_cierre' => $schema->string()->description('Fecha esperada de cierre en formato YYYY-MM-DD.'),
            'ganador' => $schema->string()->description('Adjudicatario, cuando la etapa lo es.'),
            'monto_adjudicado' => $schema->number()->description('Monto adjudicado, cuando la etapa lo es.'),
            'motivo' => $schema->string()->description('Motivo del resultado en la etapa No Adjudicado.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $user = $this->user($request);
        $opportunity = Opportunity::query()->find((int) $request->get('opportunity_id'));

        if ($opportunity === null) {
            return $this->fail('La oportunidad indicada no existe.');
        }

        abort_unless($user->canAccessCompany($opportunity->company_id), 403, 'No tiene acceso a esta oportunidad.');

        if (! $user->can('update', $opportunity)) {
            return $this->fail('No tiene permisos para modificar esta oportunidad.');
        }

        $data = $request->validate([
            'etapa' => 'required|in:'.implode(',', array_keys(Opportunity::STAGES)),
            'siguiente_accion' => 'nullable|string|max:255',
            'monto' => 'nullable|numeric|min:0',
            'probabilidad' => 'nullable|integer|between:0,100',
            'fecha_cierre' => 'nullable|date',
            'ganador' => 'nullable|string|max:255',
            'monto_adjudicado' => 'nullable|numeric|min:0',
            'motivo' => 'nullable|string|max:5000',
        ]);

        $payload = ['stage' => $data['etapa']];

        foreach (['siguiente_accion' => 'next_action', 'monto' => 'amount', 'probabilidad' => 'probability', 'fecha_cierre' => 'expected_close_date'] as $input => $column) {
            if (isset($data[$input])) {
                $payload[$column] = $data[$input];
            }
        }

        if ($data['etapa'] === 'no_adjudicado') {
            $payload['winner_name'] = $data['ganador'] ?? null;
            $payload['awarded_amount'] = $data['monto_adjudicado'] ?? null;
            $payload['lost_reason'] = $data['motivo'] ?? null;
        }

        if ($data['etapa'] === 'adjudicado') {
            $payload['winner_name'] = $data['ganador'] ?? $opportunity->owner?->name;
            $payload['awarded_amount'] = $data['monto_adjudicado'] ?? $opportunity->amount;
        }

        $opportunity->forceFill($payload)->save();

        app(CalendarSyncService::class)->syncForOpportunity($opportunity);

        $this->audit($request, 'oportunidad.etapa', $opportunity, ['etapa' => $data['etapa']]);

        return $this->ok([
            'opportunity_id' => $opportunity->id,
            'etapa' => $opportunity->stage,
            'etapa_nombre' => $opportunity->stageLabel(),
        ], 'Etapa actualizada.');
    }
}
