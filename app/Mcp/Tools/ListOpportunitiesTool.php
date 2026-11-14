<?php

namespace App\Mcp\Tools;

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
#[Description('Oportunidades del tablero con sus cinco etapas: Prospecto, Oportunidad, Oferta Presentada, Adjudicado y No Adjudicado. Incluye monto, probabilidad, cierre esperado, responsable y vínculos con licitación y cotización.')]
#[IsReadOnly]
#[IsIdempotent]
class ListOpportunitiesTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->companySchema($schema),
            'etapa' => $schema->string()->enum(array_keys(Opportunity::STAGES))->description('Etapa del tablero.'),
            'search' => $schema->string()->description('Título o institución.'),
            ...$this->pageSchema($schema),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $this->authorize('viewAny', Opportunity::class);

        $data = $this->validate($request->all(), [
            'etapa' => ['nullable', 'in:'.implode(',', array_keys(Opportunity::STAGES))],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $search = trim((string) ($data['search'] ?? ''));

        $query = Opportunity::query()
            ->with(['institution:id,name', 'owner:id,name'])
            ->when(! empty($data['etapa']), fn ($query) => $query->where('stage', $data['etapa']))
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner->where('title', 'like', "%{$search}%")
                ->orWhereHas('institution', fn ($institution) => $institution->where('name', 'like', "%{$search}%"))))
            ->orderBy('expected_close_date')
            ->orderBy('id');

        return $this->ok([
            'company_id' => $companyId,
            'etapas' => Opportunity::STAGES,
            ...$this->paginate($query, $request, fn (Opportunity $opportunity) => [
                'id' => $opportunity->id,
                'institucion' => $opportunity->institution?->name,
                'etapa' => $opportunity->stage,
                'etapa_nombre' => $opportunity->stageLabel(),
                'monto' => (float) $opportunity->amount,
                'probabilidad' => $opportunity->probability,
                'cierre_esperado' => $opportunity->expected_close_date?->format('Y-m-d'),
                'responsable' => $opportunity->owner?->name,
                'cotizacion_id' => $opportunity->quote_id,
                'licitacion_id' => $opportunity->tender_id,
                'ganador' => $opportunity->winner_name,
                'monto_adjudicado' => $opportunity->awarded_amount !== null ? (float) $opportunity->awarded_amount : null,
                ...$this->untrusted(['titulo' => $opportunity->title, 'siguiente_accion' => $opportunity->next_action], 500),
            ]),
        ]);
    }
}
