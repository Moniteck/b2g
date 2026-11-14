<?php

namespace App\Mcp\Tools;

use App\Http\Requests\InstitutionRequest;
use App\Models\Institution;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('create_institution')]
#[Title('Crear institución')]
#[Description('Alta rápida de una institución compradora en el catálogo del grupo (nombre único). Búsquela antes con search_institutions para no duplicarla.')]
#[IsDestructive(false)]
class CreateInstitutionTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->companySchema($schema),
            'name' => $schema->string()->description('Nombre oficial.')->required(),
            'acronym' => $schema->string()->description('Siglas.'),
            'type' => $schema->string()->enum(InstitutionRequest::TYPES)->description('Tipo.'),
            'location' => $schema->string()->description('Ubicación.'),
            'phone' => $schema->string()->description('Teléfono.'),
            'email' => $schema->string()->description('Correo.'),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $this->authorize('create', Institution::class);

        $input = $request->all();
        $input['name'] = is_string($input['name'] ?? null) ? trim(preg_replace('/\s+/u', ' ', $input['name'])) : null;

        $form = new InstitutionRequest;
        $data = $this->validate($input + ['status' => 'Activa'], [
            'name' => ['required', 'string', 'max:255', Rule::unique('institutions', 'name')],
            'acronym' => ['nullable', 'string', 'max:50'],
            'type' => ['nullable', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'status' => ['required', Rule::in(InstitutionRequest::STATUSES)],
        ], $form->messages(), $form->attributes());

        $institution = Institution::create($data + ['source' => 'MCP']);
        app(AuditLogger::class)->record('institucion.creada', $institution, ['name' => $institution->name], null, $companyId);

        return $this->ok(['institution' => $institution->only(['id', 'name', 'acronym', 'type', 'status', 'location'])], "Institución {$institution->name} creada.");
    }
}
