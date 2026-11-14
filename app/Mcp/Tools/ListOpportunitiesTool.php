<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\Untrusted;
use App\Models\Opportunity;
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

#[Name('list_opportunities')]
#[Title('Tablero comercial (pipeline)')]
#[Description('Oportunidades del tablero con sus cinco etapas (prospecto, oportunidad, oferta_presentada, adjudicado, no_adjudicado), monto, probabilidad, cierre esperado, responsable y vínculos con licitación y cotización.')]
#[IsReadOnly]
#[IsIdempotent]
class ListOpportunitiesTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->companySchema($schema),
            'stage' => $schema->string()->enum(array_keys(Opportunity::STAGES))->description('Etapa.'),
            'search' => $schema->string()->description('Título o institución.'),
            ...$this->pageSchema($schema),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $this->authorize('viewAny', Opportunity::class);

        $data = $this->validate($request->all(), [
            'stage' => ['nullable', 'in:'.implode(',', array_keys(Opportunity::STAGES))],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $search = trim((string) ($data['search'] ?? ''));

        $query = Opportunity::query()
            ->with(['institution:id,name', 'owner:id,name'])
            ->when(! empty($data['stage']), fn ($query) => $query->where('stage', $data['stage']))
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner->where('title', 'like', "%{$search}%")
                ->orWhereHas('institution', fn ($institution) => $institution->where('name', 'like', "%{$search}%"))))
            ->orderBy('expected_close_date')
            ->orderBy('id');

        return $this->ok([
            'company_id' => $companyId,
            'stages' => Opportunity::STAGES,
            ...$this->paginate($query, $request, fn (Opportunity $opportunity) => self::row($opportunity)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(Opportunity $opportunity): array
    {
        return [
            'id' => $opportunity->id,
            'company_id' => $opportunity->company_id,
            'stage' => $opportunity->stage,
            'stage_label' => $opportunity->stageLabel(),
            'institution' => $opportunity->institution?->name,
            'amount' => (float) $opportunity->amount,
            'probability' => $opportunity->probability,
            'expected_close_date' => $opportunity->expected_close_date?->format('Y-m-d'),
            'owner' => $opportunity->owner?->name,
            'quote_id' => $opportunity->quote_id,
            'tender_id' => $opportunity->tender_id,
            'winner_name' => $opportunity->winner_name,
            'awarded_amount' => $opportunity->awarded_amount !== null ? (float) $opportunity->awarded_amount : null,
            ...Untrusted::of(['title' => $opportunity->title, 'next_action' => $opportunity->next_action], 500),
        ];
    }
}
