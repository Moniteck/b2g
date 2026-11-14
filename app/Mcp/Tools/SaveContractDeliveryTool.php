<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\ToolError;
use App\Mcp\Tools\Concerns\PresentsContracts;
use App\Models\Contract;
use App\Models\ContractDelivery;
use App\Models\Document;
use App\Models\User;
use App\Rules\BelongsToActiveCompany;
use App\Services\Contracts\ContractService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('save_contract_delivery')]
#[Title('Registrar o editar entrega del contrato')]
#[Description('Registra una entrega comprometida del contrato (descripción, cantidad, unidad, fecha comprometida) o, con delivery_id, la edita. Cada entrega genera su plazo en el calendario con avisos. Para marcarla como entregada con evidencia use mark_delivery_delivered.')]
#[IsDestructive(false)]
class SaveContractDeliveryTool extends BaseTool
{
    use PresentsContracts;

    protected ?array $record = ['contract_id', Contract::class];

    public function schema(JsonSchema $schema): array
    {
        return [
            'contract_id' => $schema->integer()->description('Id del contrato.')->required(),
            'delivery_id' => $schema->integer()->description('Entrega a editar (omitir para crear).'),
            ...$this->companySchema($schema),
            'description' => $schema->string()->description('Qué se entrega.'),
            'quantity' => $schema->number()->description('Cantidad.'),
            'unit' => $schema->string()->description('Unidad.'),
            'due_date' => $schema->string()->description('Fecha comprometida «AAAA-MM-DD».'),
            'status' => $schema->string()->enum(ContractDelivery::STATUSES)->description('Estado.'),
            'notes' => $schema->string()->description('Notas.'),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $contract = $this->find(Contract::class, $request->get('contract_id'), 'el contrato');
        $this->authorize('followUp', $contract);

        $delivery = null;
        if ($request->get('delivery_id') !== null) {
            $delivery = ContractDelivery::query()->where('contract_id', $contract->id)->find((int) $request->get('delivery_id'))
                ?? throw new ToolError('Esa entrega no pertenece al contrato.');
        }

        $fields = ['description', 'quantity', 'unit', 'due_date', 'status', 'notes'];
        $input = Arr::only($request->all(), $fields);
        if ($delivery !== null) {
            $input += ['description' => $delivery->description, 'quantity' => (float) $delivery->quantity];
        }

        $data = $this->validate($input, [
            'description' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'unit' => ['nullable', 'string', 'max:50'],
            'due_date' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(ContractDelivery::STATUSES)],
            'evidence_document_id' => ['nullable', 'integer', new BelongsToActiveCompany(Document::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $saved = app(ContractService::class)->saveDelivery($contract, $delivery, $delivery ? Arr::only($data, array_keys(Arr::only($request->all(), $fields))) : $data, $user);

        return $this->ok(['delivery' => $this->deliveryRow($saved)], $delivery ? 'Entrega actualizada.' : 'Entrega registrada.');
    }
}
