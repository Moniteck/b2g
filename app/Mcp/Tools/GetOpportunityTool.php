<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\Present;
use App\Mcp\Tools\Concerns\TargetsAdjudicable;
use App\Models\Opportunity;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_opportunity')]
#[Title('Detalle de oportunidad')]
#[Description('Ficha de una oportunidad del tablero: datos, cotizaciones, participantes con la posición de nuestra oferta y, en No Adjudicado, quién ganó y por cuánto.')]
#[IsReadOnly]
#[IsIdempotent]
class GetOpportunityTool extends BaseTool
{
    use TargetsAdjudicable;

    protected ?array $record = ['opportunity_id', Opportunity::class];

    public function schema(JsonSchema $schema): array
    {
        return [
            'opportunity_id' => $schema->integer()->description('Id de la oportunidad.')->required(),
            ...$this->companySchema($schema),
        ];
    }

    protected function recordFor(Request $request): ?array
    {
        return $this->record;
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $opportunity = $this->find(Opportunity::query()->with(['institution:id,name', 'owner:id,name']), $request->get('opportunity_id'), 'la oportunidad');
        $this->authorize('view', $opportunity);

        return $this->ok([
            'opportunity' => ListOpportunitiesTool::row($opportunity) + [
                'lost_reason' => $opportunity->lost_reason,
                ...$this->untrusted(['title' => $opportunity->title, 'next_action' => $opportunity->next_action, 'notes' => $opportunity->notes]),
            ],
            'quotes' => Quote::query()->where('opportunity_id', $opportunity->id)->latest('id')->limit(20)
                ->get(['id', 'number', 'status', 'outcome', 'total'])
                ->map(fn (Quote $quote) => $quote->only(['id', 'number', 'status', 'outcome']) + ['total' => (float) $quote->total])->all(),
            'participants' => Present::participants($opportunity, $this->outcomeOf($opportunity)),
        ]);
    }
}
