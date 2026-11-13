<?php

namespace App\Mcp\Tools;

use App\Models\Client;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_clients')]
#[Title('Listar clientes')]
#[Description('Lista la base de clientes de una empresa. Los clientes se crean unicamente cuando el usuario gana una oferta, por lo que todos los registros provienen de una adjudicacion.')]
#[IsReadOnly]
class ListClientsTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'empresa_id' => $schema->integer()->description('Empresa. Omitirlo usa la empresa activa.'),
            'search' => $schema->string()->description('Busqueda por nombre, RTN o sector.'),
            'estado' => $schema->string()->description('Estado del cliente.'),
            'page' => $schema->integer()->description('Pagina solicitada.'),
            'per_page' => $schema->integer()->description('Registros por pagina (maximo 100).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $companyId = $this->resolveCompanyId($request, $request->get('empresa_id') ? (int) $request->get('empresa_id') : null);

        $query = Client::query()
            ->where('company_id', $companyId)
            ->with('institution:id,name')
            ->orderByDesc('last_purchase_at')
            ->orderBy('name');

        if ($search = trim((string) $request->get('search', ''))) {
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('tax_id', 'like', "%{$search}%")
                    ->orWhere('sector', 'like', "%{$search}%");
            });
        }

        if ($status = $request->get('estado')) {
            $query->where('status', $status);
        }

        $result = $this->paginate($query, $request);

        $result['data'] = collect($result['data'])->map(fn (Client $client) => [
            'id' => $client->id,
            'nombre' => $client->name,
            'rtn' => $client->tax_id,
            'sector' => $client->sector,
            'contacto' => $client->contact_name,
            'telefono' => $client->phone,
            'correo' => $client->email,
            'estado' => $client->status,
            'monto_acumulado' => (float) $client->accumulated_amount,
            'ultima_compra' => $client->last_purchase_at?->toDateString(),
            'origen' => $client->origin,
            'cotizacion_origen' => $client->source_quote_id,
        ])->all();

        return $this->ok($result);
    }
}
