<?php

namespace App\Mcp\Tools;

use App\Models\Company;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_companies')]
#[Title('Listar empresas del grupo')]
#[Description('Lista las empresas del grupo a las que tiene acceso la conexion, con su identificador, razon social, RTN y estado. Use esta herramienta antes de cualquier operacion de escritura para conocer el `company_id` correcto.')]
#[IsReadOnly]
class ListCompaniesTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $user = $this->user($request);
        $context = $this->companyContext();

        $companies = Company::query()
            ->whereIn('id', $context->allowedCompanyIds())
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'tax_id', 'representative', 'contact_email', 'is_active']);

        return $this->ok([
            'empresa_actual' => $context->currentCompanyId(),
            'empresas' => $companies->map(fn (Company $company) => [
                'id' => $company->id,
                'nombre' => $company->name,
                'codigo' => $company->code,
                'rtn' => $company->tax_id,
                'representante' => $company->representative,
                'correo' => $company->contact_email,
                'activa' => $company->is_active,
                'rol' => $user->companyRole($company->id),
            ])->all(),
        ]);
    }
}
