<?php

namespace App\Mcp\Tools;

use App\Http\Requests\Pipeline\OpportunityRequest;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\Notifications\CalendarSyncService;
use App\Services\System\AuditLogger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('create_opportunity')]
#[Title('Crear oportunidad')]
#[Description('Crea una oportunidad en el tablero (Prospecto u Oportunidad) con las reglas del formulario web; opcionalmente vinculada a una licitación de la empresa (una sola oportunidad por licitación). Para avanzarla use move_opportunity. Los resultados (Adjudicado, No Adjudicado) solo se registran con register_outcome.')]
#[IsDestructive(false)]
class CreateOpportunityTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->companySchema($schema),
            'title' => $schema->string()->description('Título.')->required(),
            'institution_id' => $schema->integer()->description('Institución compradora.')->required(),
            'contact_id' => $schema->integer()->description('Contacto.'),
            'tender_id' => $schema->integer()->description('Licitación de la empresa.'),
            'opportunity_type' => $schema->string()->description('Tipo o modalidad.'),
            'amount' => $schema->number()->description('Monto estimado (por defecto 0).'),
            'probability' => $schema->integer()->description('Probabilidad 0 a 100 (por defecto 10).'),
            'stage' => $schema->string()->enum(Opportunity::CREATABLE_STAGES)->description('Etapa inicial (por defecto prospecto).'),
            'owner_id' => $schema->integer()->description('Responsable (usuario de la empresa; por defecto, tú).'),
            'next_action' => $schema->string()->description('Próxima acción.'),
            'expected_close_date' => $schema->string()->description('Cierre esperado «AAAA-MM-DD».'),
            'notes' => $schema->string()->description('Notas.'),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $this->authorize('create', Opportunity::class);

        $input = array_intersect_key($request->all(), array_flip(['title', 'institution_id', 'contact_id', 'tender_id', 'owner_id', 'opportunity_type', 'amount', 'probability', 'stage', 'next_action', 'expected_close_date', 'notes']));
        $input += ['amount' => 0, 'probability' => 10, 'stage' => 'prospecto'];
        $form = OpportunityRequest::create('/', 'POST', $input);
        $data = $this->validate($input, Arr::except($form->rules(), ['company_id']), $form->messages(), $form->attributes());

        $opportunity = new Opportunity;
        $opportunity->forceFill(array_merge($data, ['company_id' => $companyId, 'owner_id' => $data['owner_id'] ?? $user->id]))->save();
        app(CalendarSyncService::class)->syncForOpportunity($opportunity);
        app(AuditLogger::class)->record('oportunidad.creada', $opportunity, ['titulo' => $opportunity->title]);

        return $this->ok(['opportunity' => ListOpportunitiesTool::row($opportunity->load(['institution:id,name', 'owner:id,name']))], 'Oportunidad creada.');
    }
}
