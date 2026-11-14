<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\PresentsContracts;
use App\Models\Contract;
use App\Models\ContractFollowUp;
use App\Models\User;
use App\Services\Contracts\ContractService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('add_contract_follow_up')]
#[Title('Registrar seguimiento del contrato')]
#[Description('Agrega un seguimiento a la línea de tiempo del contrato (llamada, reunión, correo, visita, oficio…) con nota y, opcionalmente, la próxima acción con su fecha, que entra al calendario con aviso ese día.')]
#[IsDestructive(false)]
class AddContractFollowUpTool extends BaseTool
{
    use PresentsContracts;

    protected ?array $record = ['contract_id', Contract::class];

    public function schema(JsonSchema $schema): array
    {
        return [
            'contract_id' => $schema->integer()->description('Id del contrato.')->required(),
            ...$this->companySchema($schema),
            'type' => $schema->string()->enum(ContractFollowUp::TYPES)->description('Tipo (por defecto Seguimiento).'),
            'description' => $schema->string()->description('Nota del seguimiento.')->required(),
            'occurred_at' => $schema->string()->description('Cuándo ocurrió «AAAA-MM-DD HH:MM» (por defecto ahora).'),
            'next_action' => $schema->string()->description('Próxima acción.'),
            'next_action_at' => $schema->string()->description('Fecha de la próxima acción «AAAA-MM-DD».'),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $contract = $this->find(Contract::class, $request->get('contract_id'), 'el contrato');
        $this->authorize('followUp', $contract);

        $data = $this->validate($request->all(), [
            'type' => ['nullable', Rule::in(ContractFollowUp::TYPES)],
            'description' => ['required', 'string', 'max:2000'],
            'occurred_at' => ['nullable', 'date'],
            'next_action' => ['nullable', 'string', 'max:255', 'required_with:next_action_at'],
            'next_action_at' => ['nullable', 'date', 'required_with:next_action'],
        ], [
            'next_action.required_with' => 'Describa la próxima acción.',
            'next_action_at.required_with' => 'Indique la fecha de la próxima acción.',
        ]);

        $followUp = app(ContractService::class)->addFollowUp($contract, $data, $user);

        return $this->ok(['follow_up' => $this->followUpRow($followUp->load('user:id,name'))], 'Seguimiento registrado.');
    }
}
