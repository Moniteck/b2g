<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\Present;
use App\Models\Document;
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

#[Name('list_documents')]
#[Title('Directorio Institucional')]
#[Description('Documentos de la empresa del Directorio Institucional (RTN, DNI del representante, solvencias SAR y municipal, IHSS, INFOP, RAP, ONCAE, escritura, poder, permiso de operación…) con emisión, vencimiento y estado (Vigente, Por vencer, Vencido, Sin vencimiento). Ordenados por el vencimiento más próximo.')]
#[IsReadOnly]
#[IsIdempotent]
class ListDocumentsTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->companySchema($schema),
            'search' => $schema->string()->description('Texto en nombre, número, titular o archivo.'),
            'category' => $schema->string()->enum(array_keys(Document::CATEGORIES))->description('Categoría.'),
            'status' => $schema->string()->enum(array_keys(Document::STATUSES))->description('Estado: vigente, por_vencer, vencido o sin_vencimiento.'),
            ...$this->pageSchema($schema),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $this->authorize('viewAny', Document::class);

        $data = $this->validate($request->all(), [
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'in:'.implode(',', array_keys(Document::CATEGORIES))],
            'status' => ['nullable', 'in:'.implode(',', array_keys(Document::STATUSES))],
        ]);

        $query = Document::query()->directory()
            ->when(filled($data['search'] ?? null), function ($query) use ($data) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($data['search'])).'%';
                $query->where(fn ($inner) => $inner->where('name', 'like', $term)->orWhere('reference_number', 'like', $term)
                    ->orWhere('owner_name', 'like', $term)->orWhere('original_filename', 'like', $term));
            })
            ->when(! empty($data['category']), fn ($query) => $query->where('category', $data['category']))
            ->when(! empty($data['status']), fn ($query) => $query->withStatus($data['status']))
            ->orderByRaw('case when expires_at is null then 1 else 0 end')
            ->orderBy('expires_at')
            ->orderBy('name');

        return $this->ok(['company_id' => $companyId, ...$this->paginate($query, $request, fn (Document $document) => Present::document($document))]);
    }
}
