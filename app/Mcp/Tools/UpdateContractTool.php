<?php

namespace App\Mcp\Tools;

use App\Http\Requests\Contracts\ContractRequest;
use App\Mcp\Support\ToolError;
use App\Mcp\Tools\Concerns\PresentsContracts;
use App\Models\Contract;
use App\Models\User;
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
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('update_contract')]
#[Title('Actualizar contrato')]
#[Description('Cambia los datos de un contrato (solo los campos enviados, con las reglas del formulario web): número, nombre, montos, fecha de firma, plazo en días (calendario o hábiles sin feriados) y hito de inicio, inicio, fin manual, contacto, oficial de seguimiento (manager_id, usuario de la empresa) y notas. La fecha fin se recalcula desde el plazo salvo que is_end_date_manual=true. El estado se cambia con change_contract_status.')]
#[IsDestructive(false)]
#[IsIdempotent]
class UpdateContractTool extends BaseTool
{
    use PresentsContracts;

    private const FIELDS = [
        'client_id', 'institution_id', 'contact_id', 'manager_id', 'number', 'name', 'awarded_amount', 'amount',
        'signed_at', 'duration_days', 'duration_business_days', 'start_trigger', 'start_date', 'is_end_date_manual',
        'end_date', 'product_delivered', 'notes',
    ];

    protected ?array $record = ['contract_id', Contract::class];

    public function schema(JsonSchema $schema): array
    {
        return [
            'contract_id' => $schema->integer()->description('Id del contrato.')->required(),
            ...$this->companySchema($schema),
            'number' => $schema->string()->description('Número de contrato (único por empresa).'),
            'name' => $schema->string()->description('Nombre.'),
            'awarded_amount' => $schema->number()->description('Monto adjudicado.'),
            'amount' => $schema->number()->description('Monto del contrato.'),
            'signed_at' => $schema->string()->description('Fecha de firma «AAAA-MM-DD».'),
            'duration_days' => $schema->integer()->description('Plazo en días.'),
            'duration_business_days' => $schema->boolean()->description('El plazo es en días hábiles.'),
            'start_trigger' => $schema->string()->enum(Contract::START_TRIGGERS)->description('Hito desde el que corre el plazo.'),
            'start_date' => $schema->string()->description('Inicio «AAAA-MM-DD».'),
            'is_end_date_manual' => $schema->boolean()->description('Fijar la fecha fin a mano.'),
            'end_date' => $schema->string()->description('Fin «AAAA-MM-DD» (con is_end_date_manual).'),
            'institution_id' => $schema->integer()->description('Institución.'),
            'contact_id' => $schema->integer()->description('Persona responsable del contacto (de la institución).'),
            'manager_id' => $schema->integer()->description('Oficial que da seguimiento (usuario de la empresa).'),
            'notes' => $schema->string()->description('Notas.'),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $contract = $this->find(Contract::class, $request->get('contract_id'), 'el contrato');
        $this->authorize('update', $contract);

        $changes = Arr::only($request->all(), self::FIELDS);
        if ($changes === []) {
            throw new ToolError('Indica al menos un campo para actualizar.');
        }

        $current = $contract->only(self::FIELDS);
        foreach (['signed_at', 'start_date', 'end_date'] as $date) {
            $current[$date] = $contract->{$date}?->format('Y-m-d');
        }

        $input = $changes + $current;
        $form = ContractRequest::create('/', 'POST', $input);
        $rules = Arr::except($form->rules(), ['company_id', 'status']);
        $rules['number'] = ['required', 'string', 'max:100', Rule::unique('contracts', 'number')->where('company_id', $contract->company_id)->ignore($contract->id)];

        $data = $this->validate($input, $rules, $form->messages(), $form->attributes());
        $saved = app(ContractService::class)->save($contract, Arr::only($data, array_keys($changes)), $user);

        return $this->ok(['contract' => $this->contractRow($saved->load(['institution:id,name', 'manager:id,name']))], 'Datos del contrato guardados.');
    }
}
