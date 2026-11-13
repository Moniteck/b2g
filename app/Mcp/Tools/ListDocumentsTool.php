<?php

namespace App\Mcp\Tools;

use App\Models\Document;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_documents')]
#[Title('Listar documentos')]
#[Description('Lista los documentos del expediente: directorio documental de la empresa (RTN, DNI, solvencias), documentos de proceso y contratos. Incluye tipo, categoria, vigencia, version vigente y si esta firmado.')]
#[IsReadOnly]
class ListDocumentsTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'empresa_id' => $schema->integer()->description('Empresa. Omitirlo usa la empresa activa.'),
            'tipo' => $schema->string()->enum(array_keys(Document::KINDS))->description('Filtra por tipo de expediente.'),
            'categoria' => $schema->string()->description('Filtra por categoria (RTN, DNI, Solvencia, etc.).'),
            'search' => $schema->string()->description('Busqueda por nombre.'),
            'vigentes' => $schema->boolean()->description('Si es true, solo documentos no vencidos.'),
            'page' => $schema->integer()->description('Pagina solicitada.'),
            'per_page' => $schema->integer()->description('Registros por pagina (maximo 100).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $companyId = $this->resolveCompanyId($request, $request->get('empresa_id') ? (int) $request->get('empresa_id') : null);

        $query = Document::query()
            ->where('company_id', $companyId)
            ->orderByDesc('id');

        if ($kind = $request->get('tipo')) {
            $query->where('kind', $kind);
        }

        if ($category = $request->get('categoria')) {
            $query->where('category', $category);
        }

        if ($search = trim((string) $request->get('search', ''))) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->boolean('vigentes')) {
            $query->where(function ($builder) {
                $builder->whereNull('expires_at')->orWhere('expires_at', '>=', now()->toDateString());
            });
        }

        $result = $this->paginate($query, $request);

        $result['data'] = collect($result['data'])->map(fn (Document $document) => [
            'id' => $document->id,
            'nombre' => $document->name,
            'tipo' => $document->kind,
            'tipo_nombre' => $document->kindLabel(),
            'categoria' => $document->category,
            'titular' => $document->owner_name,
            'emision' => $document->issued_at?->toDateString(),
            'vencimiento' => $document->expires_at?->toDateString(),
            'vencido' => $document->isExpired(),
            'estado' => $document->status,
            'version' => $document->version,
            'firmado' => $document->signed_at !== null,
            'sello_aplicado' => $document->sealed_at !== null,
            'tamano' => $document->size,
        ])->all();

        return $this->ok($result);
    }
}
