<?php

namespace App\Mcp\Tools;

use App\Models\Opportunity;
use App\Models\User;
use App\Services\Pipeline\OpportunityStageService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('move_opportunity')]
#[Title('Mover oportunidad en el tablero')]
#[Description('Mueve una oportunidad una etapa adelante o atrás con las mismas reglas del tablero web. Pasar a Oferta Presentada exige nuestra oferta con monto entre los participantes (set_participants) y presenta la cotización vinculada. NO registra resultados: Adjudicado y No Adjudicado solo con register_outcome, y salir de ellos solo con revert_outcome.')]
#[IsDestructive(false)]
#[IsIdempotent]
class MoveOpportunityTool extends BaseTool
{
    protected ?array $record = ['opportunity_id', Opportunity::class];

    public function schema(JsonSchema $schema): array
    {
        return [
            'opportunity_id' => $schema->integer()->description('Id de la oportunidad.')->required(),
            ...$this->companySchema($schema),
            'stage' => $schema->string()->enum(array_keys(Opportunity::STAGES))->description('Etapa de destino.')->required(),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $opportunity = $this->find(Opportunity::class, $request->get('opportunity_id'), 'la oportunidad');
        $this->authorize('move', $opportunity);

        $data = $this->validate(['stage' => $request->get('stage')], ['stage' => ['required', 'string', Rule::in(array_keys(Opportunity::STAGES))]]);
        $result = app(OpportunityStageService::class)->move($opportunity, $data['stage'], $user);
        $moved = $result->opportunity->load(['institution:id,name', 'owner:id,name']);

        return $this->ok([
            'opportunity' => ListOpportunitiesTool::row($moved),
            'from' => $result->from,
            'already_applied' => $result->alreadyApplied,
            'presented_quote' => $result->presentedQuote ? ['id' => $result->presentedQuote->id, 'number' => $result->presentedQuote->number, 'status' => $result->presentedQuote->status] : null,
        ], $result->alreadyApplied ? 'La oportunidad ya estaba en esa etapa.' : "Oportunidad movida a {$moved->stageLabel()}.");
    }
}
