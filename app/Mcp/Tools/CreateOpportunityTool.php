<?php

namespace App\Mcp\Tools;

use App\Models\Contact;
use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\Tender;
use App\Models\User;
use App\Rules\BelongsToActiveCompany;
use App\Services\AuditLogger;
use App\Services\Notifications\CalendarSyncService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('create_opportunity')]
#[Title('Crear oportunidad')]
#[Description('Crea una oportunidad en el tablero (Prospecto, Oportunidad u Oferta Presentada), opcionalmente vinculada a una licitación de la empresa. Los resultados (Adjudicado, No Adjudicado) solo se registran con register_outcome.')]
#[IsDestructive(false)]
class CreateOpportunityTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->companySchema($schema),
            'title' => $schema->string()->description('Título.')->required(),
            'institution_id' => $schema->integer()->description('Institución.'),
            'contact_id' => $schema->integer()->description('Contacto.'),
            'tender_id' => $schema->integer()->description('Licitación de la empresa.'),
            'opportunity_type' => $schema->string()->description('Tipo o modalidad.'),
            'amount' => $schema->number()->description('Monto estimado (por defecto 0).'),
            'probability' => $schema->integer()->description('Probabilidad 0 a 100 (por defecto 10).'),
            'stage' => $schema->string()->enum(Opportunity::ACTIVE_STAGES)->description('Etapa (por defecto prospecto).'),
            'next_action' => $schema->string()->description('Próxima acción.'),
            'expected_close_date' => $schema->string()->description('Cierre esperado «AAAA-MM-DD».'),
            'notes' => $schema->string()->description('Notas.'),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $this->authorize('create', Opportunity::class);

        $data = $this->validate($request->all() + ['amount' => 0, 'probability' => 10, 'stage' => 'prospecto'], [
            'institution_id' => ['nullable', 'integer', new BelongsToActiveCompany(Institution::class)],
            'contact_id' => ['nullable', 'integer', new BelongsToActiveCompany(Contact::class)],
            'tender_id' => ['nullable', 'integer', new BelongsToActiveCompany(Tender::class)],
            'title' => ['required', 'string', 'max:255'],
            'opportunity_type' => ['nullable', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:0'],
            'probability' => ['required', 'integer', 'between:0,100'],
            'stage' => ['required', 'in:'.implode(',', Opportunity::ACTIVE_STAGES)],
            'next_action' => ['nullable', 'string', 'max:255'],
            'expected_close_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $opportunity = Opportunity::create($data + ['company_id' => $companyId, 'owner_id' => $user->id]);
        app(CalendarSyncService::class)->syncForOpportunity($opportunity);
        app(AuditLogger::class)->record('oportunidad.creada', $opportunity, ['titulo' => $opportunity->title]);

        return $this->ok(['opportunity' => ListOpportunitiesTool::row($opportunity->load(['institution:id,name', 'owner:id,name']))], 'Oportunidad creada.');
    }
}
