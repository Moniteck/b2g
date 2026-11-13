<?php

namespace App\Mcp\Tools;

use App\Models\Quote;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_quotes')]
#[Title('Listar cotizaciones')]
#[Description('Lista las cotizaciones de una empresa con su numero, institucion, estado documental, resultado comercial, total y fechas. Filtre por busqueda, estado, resultado o tipo de compra.')]
#[IsReadOnly]
class ListQuotesTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'empresa_id' => $schema->integer()->description('Identificador de la empresa. Omitirlo usa la empresa activa de la conexion.'),
            'search' => $schema->string()->description('Texto libre para buscar por numero, institucion o tipo de compra.'),
            'estado' => $schema->string()->enum(Quote::STATUSES)->description('Estado documental de la cotizacion.'),
            'resultado' => $schema->string()->enum(Quote::OUTCOMES)->description('Resultado comercial de la oferta.'),
            'tipo' => $schema->string()->description('Tipo de compra o proceso.'),
            'page' => $schema->integer()->description('Pagina solicitada.'),
            'per_page' => $schema->integer()->description('Cantidad de registros por pagina (maximo 100).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $companyId = $this->resolveCompanyId($request, $request->get('empresa_id') ? (int) $request->get('empresa_id') : null);

        $query = Quote::query()
            ->where('company_id', $companyId)
            ->with(['institution:id,name', 'company:id,name'])
            ->orderByDesc('quoted_at')
            ->orderByDesc('id');

        if ($search = trim((string) $request->get('search', ''))) {
            $query->where(function ($builder) use ($search) {
                $builder->where('number', 'like', "%{$search}%")
                    ->orWhere('purchase_type', 'like', "%{$search}%")
                    ->orWhereHas('institution', fn ($institution) => $institution->where('name', 'like', "%{$search}%"));
            });
        }

        if ($status = $request->get('estado')) {
            $query->where('status', $status);
        }

        if ($outcome = $request->get('resultado')) {
            $query->where('outcome', $outcome);
        }

        if ($type = $request->get('tipo')) {
            $query->where('purchase_type', $type);
        }

        $result = $this->paginate($query, $request);

        $result['data'] = collect($result['data'])->map(fn (Quote $quote) => [
            'id' => $quote->id,
            'numero' => $quote->number,
            'empresa_id' => $quote->company_id,
            'empresa' => $quote->company?->name,
            'institucion' => $quote->institution?->name,
            'fecha' => $quote->quoted_at?->toDateString(),
            'tipo' => $quote->purchase_type,
            'estado' => $quote->status,
            'resultado' => $quote->outcome,
            'total' => (float) $quote->total,
            'impuesto_contenido' => (float) $quote->tax,
            'validez_dias' => $quote->validity_days,
            'adjudicado' => $quote->awarded_at?->toDateString(),
            'monto_adjudicado' => $quote->awarded_amount !== null ? (float) $quote->awarded_amount : null,
        ])->all();

        return $this->ok($result);
    }
}
