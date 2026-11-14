<?php

namespace App\Mcp\Tools;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Quote;
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

#[Name('get_client')]
#[Title('Detalle de cliente')]
#[Description('Ficha de un cliente: datos de contacto, cotizaciones ganadas (con monto adjudicado) y contratos de post-adjudicación.')]
#[IsReadOnly]
#[IsIdempotent]
class GetClientTool extends BaseTool
{
    protected ?array $record = ['client_id', Client::class];

    public function schema(JsonSchema $schema): array
    {
        return [
            'client_id' => $schema->integer()->description('Id del cliente.')->required(),
            ...$this->companySchema($schema),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $client = $this->find(Client::query()->with('institution:id,name'), $request->get('client_id'), 'el cliente');
        $this->authorize('view', $client);

        return $this->ok([
            'client' => ListClientsTool::row($client) + ['address' => $client->address, ...$this->untrusted(['notes' => $client->notes])],
            'won_quotes' => Quote::query()->where('client_id', $client->id)->where('outcome', 'Ganada')->latest('awarded_at')->limit(50)
                ->get(['id', 'number', 'total', 'awarded_amount', 'awarded_at', 'status', 'tender_id'])
                ->map(fn (Quote $quote) => [
                    'id' => $quote->id,
                    'number' => $quote->number,
                    'total' => (float) $quote->total,
                    'awarded_amount' => $quote->awarded_amount !== null ? (float) $quote->awarded_amount : null,
                    'awarded_at' => $quote->awarded_at?->format('Y-m-d'),
                    'tender_id' => $quote->tender_id,
                ])->all(),
            'contracts' => $client->contractsQuery()->latest('id')->limit(50)
                ->get(['id', 'number', 'name', 'amount', 'awarded_amount', 'status', 'start_date', 'end_date'])
                ->map(fn (Contract $contract) => [
                    'id' => $contract->id,
                    'number' => $contract->number,
                    'amount' => (float) ($contract->awarded_amount ?? $contract->amount),
                    'status' => $contract->status,
                    'start_date' => $contract->start_date?->format('Y-m-d'),
                    'end_date' => $contract->end_date?->format('Y-m-d'),
                    ...$this->untrusted(['name' => $contract->name], 300),
                ])->all(),
        ]);
    }
}
