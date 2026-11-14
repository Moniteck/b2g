<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\ToolError;
use App\Mcp\Tools\Concerns\PresentsContracts;
use App\Models\Contract;
use App\Models\User;
use App\Services\Contracts\ContractService;
use App\Services\Contracts\ContractStatus;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('change_contract_status')]
#[Title('Cambiar estado del contrato')]
#[Description('Cambia el estado de un contrato según sus transiciones (ver next_statuses en get_contract). Suspendido y Cancelado exigen motivo. Cerrar exige producto entregado; cerrar, cancelar y reabrir un cerrado son solo del administrador. Cerrar y Cancelar son ACCIONES SENSIBLES en dos pasos: la primera llamada devuelve un resumen y un confirmation_code; muéstralo y, solo si la persona confirma, repite la llamada con los mismos argumentos y el código.')]
#[IsDestructive]
class ChangeContractStatusTool extends BaseTool
{
    use PresentsContracts;

    protected ?array $record = ['contract_id', Contract::class];

    public function schema(JsonSchema $schema): array
    {
        return [
            'contract_id' => $schema->integer()->description('Id del contrato.')->required(),
            ...$this->companySchema($schema),
            'status' => $schema->string()->enum(ContractStatus::ALL)->description('Nuevo estado.')->required(),
            'reason' => $schema->string()->description('Motivo (obligatorio para Suspendido y Cancelado).'),
            ...$this->confirmationSchema($schema),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $contract = $this->find(Contract::query()->with(['institution:id,name', 'manager:id,name']), $request->get('contract_id'), 'el contrato');
        $this->authorize('update', $contract);

        $data = $this->validate($request->all(), [
            'status' => ['required', Rule::in(ContractStatus::ALL)],
            'reason' => ['nullable', 'string', 'max:1000', Rule::requiredIf(fn () => in_array($request->get('status'), [ContractStatus::CANCELLED, ContractStatus::SUSPENDED], true))],
        ], ['reason.required' => 'Indique el motivo.']);
        $to = $data['status'];

        if (in_array($to, ContractStatus::ADMIN_TARGETS, true) || $contract->status === ContractStatus::CLOSED) {
            $this->authorize('cancel', $contract);
        }

        if ($contract->status !== $to && ! ContractStatus::allows($contract->status, $to)) {
            throw new ToolError("Un contrato en «{$contract->status}» no puede pasar a «{$to}». Siguientes posibles: ".implode(', ', ContractStatus::next($contract->status)).'.');
        }

        $apply = function () use ($contract, $to, $user, $data) {
            $updated = app(ContractService::class)->changeStatus($contract, $to, $user, $data['reason'] ?? null);

            return $this->ok(['contract' => $this->contractRow($updated->load(['institution:id,name', 'manager:id,name']))], "El contrato quedó en «{$updated->status}».");
        };

        if (! in_array($to, ContractStatus::ADMIN_TARGETS, true) || $contract->status === $to) {
            return $apply();
        }

        return $this->confirmed($request, 'change_contract_status', $contract, [
            'action' => $to === ContractStatus::CLOSED ? 'Cerrar contrato' : 'Cancelar contrato',
            'contract' => $contract->number,
            'institution' => $contract->institution?->name,
            'from' => $contract->status,
            'to' => $to,
            'effect' => $to === ContractStatus::CLOSED
                ? 'El contrato se cierra (avance al 100 %) y sus avisos se detienen.'
                : 'El contrato se cancela y sus avisos se detienen. No se puede reabrir un contrato cancelado.',
            ...$this->untrusted(['reason' => $data['reason'] ?? null], 500),
        ], $apply);
    }
}
