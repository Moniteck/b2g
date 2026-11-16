<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\PresentsContracts;
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

#[Name('get_contract')]
#[Title('Detalle de contrato')]
#[Description('Ficha de un contrato: datos, plazo e inicio/fin, persona de contacto de la institución, oficial que da seguimiento, entregas (con estado y evidencia) y línea de tiempo de seguimientos. Los documentos se listan con list_attachments (entity_type=contract).')]
#[IsReadOnly]
#[IsIdempotent]
class GetContractTool extends BaseTool
{
    use PresentsContracts;

    protected ?array $record = ['contract_id', Contract::class];

    public function schema(JsonSchema $schema): array
    {
        return [
            'contract_id' => $schema->integer()->description('Id del contrato.')->required(),
            ...$this->companySchema($schema),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $contract = $this->find(Contract::query()->with([
            'institution:id,name', 'client:id,name', 'contact:id,name,position,email,phone', 'manager:id,name',
            'deliveries' => fn ($query) => $query->orderByRaw('due_date IS NULL')->orderBy('due_date')->orderBy('id'),
            'followUps' => fn ($query) => $query->with('user:id,name')->latest('occurred_at')->latest('id')->limit(50),
        ]), $request->get('contract_id'), 'el contrato');
        $this->authorize('view', $contract);

        return $this->ok([
            'contract' => $this->contractRow($contract) + [
                'client' => $contract->client?->name,
                'contact' => $contract->contact ? $contract->contact->only(['id', 'name', 'position', 'email', 'phone']) : null,
                ...$this->untrusted(['name' => $contract->name, 'notes' => $contract->notes]),
            ],
            'deliveries' => $contract->deliveries->map(fn ($delivery) => $this->deliveryRow($delivery))->all(),
            'follow_ups' => $contract->followUps->map(fn ($followUp) => $this->followUpRow($followUp))->all(),
            'can' => [
                'update' => $user->can('update', $contract),
                'follow_up' => $user->can('followUp', $contract),
                'close' => $user->can('close', $contract),
                'cancel' => $user->can('cancel', $contract),
            ],
        ]);
    }
}
