<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\PresentsContracts;
use App\Models\Contract;
use App\Models\User;
use App\Services\Contracts\ContractStatus;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_contracts')]
#[Title('Contratos de post-adjudicación')]
#[Description('Contratos nacidos de ofertas ganadas, con estado (Pendiente de contrato, En ejecución, Entrega parcial, Producto entregado, Cerrado, Suspendido, Cancelado), monto adjudicado, plazo, inicio y fin. Filtro `group`: activos, pendientes, ejecucion, entregados o finalizados.')]
#[IsReadOnly]
#[IsIdempotent]
class ListContractsTool extends BaseTool
{
    use PresentsContracts;

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->companySchema($schema),
            'status' => $schema->string()->enum(ContractStatus::ALL)->description('Estado.'),
            'group' => $schema->string()->enum(array_keys(ContractStatus::GROUPS))->description('Grupo de estados.'),
            'search' => $schema->string()->description('Número o nombre.'),
            ...$this->pageSchema($schema),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $this->authorize('viewAny', Contract::class);

        $data = $this->validate($request->all(), [
            'status' => ['nullable', 'in:'.implode(',', ContractStatus::ALL)],
            'group' => ['nullable', 'in:'.implode(',', array_keys(ContractStatus::GROUPS))],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $search = trim((string) ($data['search'] ?? ''));

        $query = Contract::query()
            ->with(['manager:id,name', 'institution:id,name'])
            ->when(! empty($data['status']), fn ($query) => $query->where('status', $data['status']))
            ->when(! empty($data['group']), fn ($query) => $query->whereIn('status', ContractStatus::GROUPS[$data['group']]))
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner->where('number', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")))
            ->orderByRaw('end_date IS NULL')
            ->orderBy('end_date')
            ->orderByDesc('id');

        return $this->ok(['company_id' => $companyId, ...$this->paginate($query, $request, fn (Contract $contract) => $this->contractRow($contract))]);
    }
}
