<?php

namespace App\Mcp\Tools;

use App\Models\Client;
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

#[Name('list_clients')]
#[Title('Listar clientes')]
#[Description('Base de clientes de la empresa. Los clientes SOLO nacen al ganar una oferta (no se crean a mano ni por MCP). Incluye monto acumulado adjudicado y última compra.')]
#[IsReadOnly]
#[IsIdempotent]
class ListClientsTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->companySchema($schema),
            'search' => $schema->string()->description('Nombre, RTN o sector.'),
            'status' => $schema->string()->description('Estado del cliente.'),
            ...$this->pageSchema($schema),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $this->authorize('viewAny', Client::class);

        $data = $this->validate($request->all(), ['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'string', 'max:50']]);
        $term = filled($data['search'] ?? null) ? '%'.str_replace(['%', '_'], ['\%', '\_'], trim($data['search'])).'%' : null;

        $query = Client::query()
            ->with('institution:id,name')
            ->when($term !== null, fn ($query) => $query->where(fn ($inner) => $inner->where('name', 'like', $term)->orWhere('tax_id', 'like', $term)->orWhere('sector', 'like', $term)))
            ->when(filled($data['status'] ?? null), fn ($query) => $query->where('status', $data['status']))
            ->orderByDesc('last_purchase_at')
            ->orderBy('name');

        return $this->ok(['company_id' => $companyId, ...$this->paginate($query, $request, fn (Client $client) => self::row($client))]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(Client $client): array
    {
        return [
            'id' => $client->id,
            'name' => $client->name,
            'tax_id' => $client->tax_id,
            'sector' => $client->sector,
            'status' => $client->status,
            'institution' => $client->institution?->name,
            'accumulated_amount' => (float) $client->accumulated_amount,
            'last_purchase_at' => $client->last_purchase_at?->format('Y-m-d'),
            'origin' => $client->origin,
            'source_quote_id' => $client->source_quote_id,
            'contact_name' => $client->contact_name,
            'phone' => $client->phone,
            'email' => $client->email,
        ];
    }
}
