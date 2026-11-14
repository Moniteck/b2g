<?php

namespace App\Mcp\Tools;

use App\Models\Company;
use App\Models\McpConnection;
use App\Models\User;
use App\Support\Permissions;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_companies')]
#[Title('Empresas de la conexión')]
#[Description('Empresas a las que tiene acceso esta conexión, con el rol del usuario en cada una, la empresa predeterminada y el nivel de la conexión (read o write). Úsela primero: el `id` de cada empresa es el `empresa_id` del resto de herramientas.')]
#[IsReadOnly]
#[IsIdempotent]
class ListCompaniesTool extends BaseTool
{
    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $session = $this->session();

        return $this->ok([
            'default_company_id' => $companyId,
            'connection_level' => $session->connection->level(),
            'connection_level_label' => McpConnection::LEVELS[$session->connection->level()],
            'companies' => Company::query()
                ->whereIn('id', $session->companyIds)
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'tax_id', 'is_active'])
                ->map(fn (Company $company) => [
                    'id' => $company->id,
                    'name' => $company->name,
                    'code' => $company->code,
                    'tax_id' => $company->tax_id,
                    'is_active' => (bool) $company->is_active,
                    'role' => $user->companyRole($company->id),
                    'role_label' => Permissions::ROLE_LABELS[$user->companyRole($company->id)] ?? null,
                ])->all(),
        ]);
    }
}
