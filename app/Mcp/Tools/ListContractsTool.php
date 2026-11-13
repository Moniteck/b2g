<?php

namespace App\Mcp\Tools;

use App\Models\Contract;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_contracts')]
#[Title('Listar contratos post-adjudicacion')]
#[Description('Lista los expedientes post-adjudicacion con su estado, monto adjudicado, responsables, fechas de inicio y fin, avance y estado de entrega del producto.')]
#[IsReadOnly]
class ListContractsTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'empresa_id' => $schema->integer()->description('Empresa. Omitirlo usa la empresa activa.'),
            'estado' => $schema->string()->enum(array_keys(Contract::STATUSES))->description('Filtra por estado del expediente.'),
            'search' => $schema->string()->description('Busqueda por numero o nombre.'),
            'page' => $schema->integer()->description('Pagina solicitada.'),
            'per_page' => $schema->integer()->description('Registros por pagina (maximo 100).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $companyId = $this->resolveCompanyId($request, $request->get('empresa_id') ? (int) $request->get('empresa_id') : null);

        $query = Contract::query()
            ->where('company_id', $companyId)
            ->with(['manager:id,name', 'institution:id,name', 'deliveries'])
            ->orderByDesc('id');

        if ($status = $request->get('estado')) {
            $query->where('status', $status);
        }

        if ($search = trim((string) $request->get('search', ''))) {
            $query->where(function ($builder) use ($search) {
                $builder->where('number', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        $result = $this->paginate($query, $request);

        $result['data'] = collect($result['data'])->map(fn (Contract $contract) => [
            'id' => $contract->id,
            'numero' => $contract->number,
            'nombre' => $contract->name,
            'institucion' => $contract->institution?->name,
            'estado' => $contract->status,
            'monto' => (float) $contract->amount,
            'monto_adjudicado' => $contract->awarded_amount !== null ? (float) $contract->awarded_amount : null,
            'avance_fisico' => $contract->physical_progress,
            'avance_financiero' => $contract->financial_progress,
            'fecha_firma' => $contract->signed_at?->toDateString(),
            'inicio' => $contract->start_date?->toDateString(),
            'fin' => $contract->end_date?->toDateString(),
            'duracion_dias' => $contract->duration_days,
            'dias_habiles' => $contract->duration_business_days,
            'disparador_inicio' => $contract->start_trigger,
            'producto_entregado' => $contract->product_delivered,
            'responsable' => $contract->manager?->name,
            'oficial_encargado' => $contract->official_in_charge,
            'entregas' => $contract->deliveries->map(fn ($delivery) => [
                'descripcion' => $delivery->description,
                'cantidad' => (float) $delivery->quantity,
                'vencimiento' => $delivery->due_date?->toDateString(),
                'estado' => $delivery->status,
            ])->all(),
        ])->all();

        return $this->ok($result);
    }
}
