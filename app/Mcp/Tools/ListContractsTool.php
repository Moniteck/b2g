<?php

namespace App\Mcp\Tools;

use App\Models\Contract;
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

#[Name('list_contracts')]
#[Title('Contratos de post-adjudicación')]
#[Description('Contratos nacidos de ofertas ganadas, con estado, monto adjudicado, institución, responsable y fechas de inicio y fin.')]
#[IsReadOnly]
#[IsIdempotent]
class ListContractsTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->companySchema($schema),
            'estado' => $schema->string()->enum(array_keys(Contract::STATUSES))->description('Estado del contrato.'),
            'search' => $schema->string()->description('Número o nombre.'),
            ...$this->pageSchema($schema),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $this->authorize('viewAny', Contract::class);

        $data = $this->validate($request->all(), [
            'estado' => ['nullable', 'in:'.implode(',', array_keys(Contract::STATUSES))],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $search = trim((string) ($data['search'] ?? ''));

        $query = Contract::query()
            ->with(['manager:id,name', 'institution:id,name'])
            ->when(! empty($data['estado']), fn ($query) => $query->where('status', $data['estado']))
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner->where('number', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")))
            ->orderByDesc('id');

        return $this->ok(['company_id' => $companyId, ...$this->paginate($query, $request, fn (Contract $contract) => [
            'id' => $contract->id,
            'numero' => $contract->number,
            'institucion' => $contract->institution?->name,
            'estado' => $contract->status,
            'monto' => (float) $contract->amount,
            'monto_adjudicado' => $contract->awarded_amount !== null ? (float) $contract->awarded_amount : null,
            'inicio' => $contract->start_date?->format('Y-m-d'),
            'fin' => $contract->end_date?->format('Y-m-d'),
            'responsable' => $contract->manager?->name,
            'cotizacion_id' => $contract->quote_id,
            ...$this->untrusted(['nombre' => $contract->name], 300),
        ])]);
    }
}
