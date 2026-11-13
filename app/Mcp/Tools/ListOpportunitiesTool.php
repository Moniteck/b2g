<?php

namespace App\Mcp\Tools;

use App\Models\Opportunity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_opportunities')]
#[Title('Listar oportunidades del pipeline')]
#[Description('Lista el tablero comercial con las cinco etapas: Prospecto, Oportunidad, Oferta Presentada, Adjudicado y No Adjudicado. Incluye monto, probabilidad, responsable, fecha de cierre esperada y el resultado cuando ya existe.')]
#[IsReadOnly]
class ListOpportunitiesTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'empresa_id' => $schema->integer()->description('Empresa. Omitirlo usa la empresa activa.'),
            'etapa' => $schema->string()->enum(array_keys(Opportunity::STAGES))->description('Filtra por etapa del tablero.'),
            'search' => $schema->string()->description('Busqueda por titulo o institucion.'),
            'page' => $schema->integer()->description('Pagina solicitada.'),
            'per_page' => $schema->integer()->description('Registros por pagina (maximo 100).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $companyId = $this->resolveCompanyId($request, $request->get('empresa_id') ? (int) $request->get('empresa_id') : null);

        $query = Opportunity::query()
            ->where('company_id', $companyId)
            ->with(['institution:id,name', 'owner:id,name'])
            ->orderBy('expected_close_date')
            ->orderBy('id');

        if ($stage = $request->get('etapa')) {
            $query->where('stage', $stage);
        }

        if ($search = trim((string) $request->get('search', ''))) {
            $query->where(function ($builder) use ($search) {
                $builder->where('title', 'like', "%{$search}%")
                    ->orWhereHas('institution', fn ($institution) => $institution->where('name', 'like', "%{$search}%"));
            });
        }

        $result = $this->paginate($query, $request);

        $result['data'] = collect($result['data'])->map(fn (Opportunity $opportunity) => [
            'id' => $opportunity->id,
            'titulo' => $opportunity->title,
            'institucion' => $opportunity->institution?->name,
            'etapa' => $opportunity->stage,
            'etapa_nombre' => $opportunity->stageLabel(),
            'monto' => (float) $opportunity->amount,
            'probabilidad' => $opportunity->probability,
            'cierre_esperado' => $opportunity->expected_close_date?->toDateString(),
            'responsable' => $opportunity->owner?->name,
            'siguiente_accion' => $opportunity->next_action,
            'cotizacion_id' => $opportunity->quote_id,
            'licitacion_id' => $opportunity->tender_id,
            'ganador' => $opportunity->winner_name,
            'monto_adjudicado' => $opportunity->awarded_amount !== null ? (float) $opportunity->awarded_amount : null,
        ])->all();

        $result['etapas'] = Opportunity::STAGES;

        return $this->ok($result);
    }
}
