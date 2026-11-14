<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\Base64File;
use App\Mcp\Support\ToolError;
use App\Mcp\Tools\Concerns\PresentsContracts;
use App\Models\Contract;
use App\Models\ContractDelivery;
use App\Models\User;
use App\Services\Contracts\ContractService;
use App\Services\Contracts\ContractStatus;
use App\Services\Documents\UploadInspector;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('mark_delivery_delivered')]
#[Title('Marcar entrega como entregada')]
#[Description('Marca una entrega del contrato como entregada con su fecha real y, opcionalmente, la evidencia (acta de recepción en PDF, JPG o PNG en base64, máx. 10 MB), que queda en los documentos del contrato. Avisa si con eso todas las entregas están completas.')]
#[IsDestructive(false)]
#[IsIdempotent]
class MarkDeliveryDeliveredTool extends BaseTool
{
    use PresentsContracts;

    protected ?array $record = ['contract_id', Contract::class];

    public function schema(JsonSchema $schema): array
    {
        return [
            'contract_id' => $schema->integer()->description('Id del contrato.')->required(),
            'delivery_id' => $schema->integer()->description('Id de la entrega.')->required(),
            ...$this->companySchema($schema),
            'delivered_at' => $schema->string()->description('Fecha real de entrega «AAAA-MM-DD» (no futura).')->required(),
            'notes' => $schema->string()->description('Notas.'),
            'evidence_base64' => $schema->string()->description('Evidencia en base64 (opcional).'),
            'evidence_file_name' => $schema->string()->description('Nombre del archivo de evidencia (obligatorio si se envía).'),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $contract = $this->find(Contract::class, $request->get('contract_id'), 'el contrato');
        $this->authorize('followUp', $contract);

        $delivery = ContractDelivery::query()->where('contract_id', $contract->id)->find((int) $request->get('delivery_id'))
            ?? throw new ToolError('Esa entrega no pertenece al contrato.');

        $data = $this->validate($request->all(), [
            'delivered_at' => ['required', 'date', 'before_or_equal:tomorrow'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['delivered_at.before_or_equal' => 'La fecha real de entrega no puede ser futura.']);

        $evidence = null;
        if (filled($request->get('evidence_base64'))) {
            $evidence = Base64File::decode($request->get('evidence_base64'), $request->get('evidence_file_name'));
            app(UploadInspector::class)->inspect($evidence, 'evidence', ['pdf', 'jpg', 'jpeg', 'png']);
        }

        $saved = app(ContractService::class)->markDelivered($contract, $delivery, $data['delivered_at'], $data['notes'] ?? null, $evidence, $user);
        $allDone = ContractService::allDelivered($contract) && ! in_array($contract->fresh()->status, [ContractStatus::DELIVERED, ...ContractStatus::FINAL], true);

        return $this->ok([
            'delivery' => $this->deliveryRow($saved),
            'all_delivered' => $allDone,
        ], 'Entrega marcada como entregada.'.($allDone ? ' Todas las entregas están completas: se puede pasar el contrato a «Producto entregado».' : ''));
    }
}
