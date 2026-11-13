<?php

namespace App\Mcp\Tools;

use App\Models\Tender;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_tenders')]
#[Title('Listar licitaciones')]
#[Description('Lista las licitaciones y procesos de compra de una empresa con sus fechas criticas: publicacion, cierre de aclaraciones, cierre de presentacion, apertura y resultado.')]
#[IsReadOnly]
class ListTendersTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'empresa_id' => $schema->integer()->description('Empresa. Omitirlo usa la empresa activa.'),
            'search' => $schema->string()->description('Busqueda por referencia, titulo o numero de proceso.'),
            'estado' => $schema->string()->description('Estado de la licitacion.'),
            'cierran_en_dias' => $schema->integer()->description('Solo licitaciones que cierran dentro de estos dias.'),
            'page' => $schema->integer()->description('Pagina solicitada.'),
            'per_page' => $schema->integer()->description('Registros por pagina (maximo 100).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $companyId = $this->resolveCompanyId($request, $request->get('empresa_id') ? (int) $request->get('empresa_id') : null);

        $query = Tender::query()
            ->where('company_id', $companyId)
            ->with('institution:id,name')
            ->orderByDesc('published_at')
            ->orderByDesc('id');

        if ($search = trim((string) $request->get('search', ''))) {
            $query->where(function ($builder) use ($search) {
                $builder->where('reference', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('process_name', 'like', "%{$search}%");
            });
        }

        if ($status = $request->get('estado')) {
            $query->where('status', $status);
        }

        if ($days = (int) $request->get('cierran_en_dias', 0)) {
            $query->whereNotNull('closing_date')
                ->whereBetween('closing_date', [now()->toDateString(), now()->addDays($days)->toDateString()]);
        }

        $result = $this->paginate($query, $request);

        $result['data'] = collect($result['data'])->map(fn (Tender $tender) => [
            'id' => $tender->id,
            'referencia' => $tender->reference,
            'titulo' => $tender->title,
            'institucion' => $tender->institution?->name,
            'estado' => $tender->status,
            'tipo' => $tender->purchase_type,
            'monto_estimado' => (float) $tender->estimated_amount,
            'publicacion' => $tender->published_at?->toDateString(),
            'cierre_aclaraciones' => $tender->clarification_deadline?->toDateTimeString(),
            'cierre_presentacion' => $tender->presentation_deadline?->toDateTimeString(),
            'apertura' => $tender->opening_at?->toDateTimeString(),
            'cierre' => $tender->closing_date?->toDateString(),
            'resultado' => $tender->result,
        ])->all();

        return $this->ok($result);
    }
}
