<?php

namespace App\Mcp\Tools;

use App\Models\Institution;
use App\Models\User;
use App\Services\Tenders\InstitutionMatcher;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('search_institutions')]
#[Title('Buscar instituciones')]
#[Description('Busca en el catálogo de instituciones compradoras (compartido por el grupo) por nombre o siglas. Con `fuzzy=true` empareja un nombre aproximado (p. ej. leído de un pliego) y devuelve la similitud (score de 0 a 1). Antes de crear una institución, búsquela.')]
#[IsReadOnly]
#[IsIdempotent]
class SearchInstitutionsTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->companySchema($schema),
            'search' => $schema->string()->description('Nombre o siglas.')->required(),
            'fuzzy' => $schema->boolean()->description('Emparejamiento aproximado por nombre.'),
            'limit' => $schema->integer()->description('Máximo de resultados (1 a 50, por defecto 20).'),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $this->authorize('viewAny', Institution::class);

        $data = $this->validate($request->all(), [
            'search' => ['required', 'string', 'min:2', 'max:255'],
            'fuzzy' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $limit = (int) ($data['limit'] ?? 20);

        if ($data['fuzzy'] ?? false) {
            return $this->ok(['institutions' => array_map(fn (array $candidate) => [
                'id' => $candidate['id'] ?? null,
                'score' => $candidate['score'] ?? null,
                'acronym' => $candidate['acronym'] ?? null,
                'name' => $candidate['name'] ?? null,
            ], app(InstitutionMatcher::class)->candidates($data['search'], min($limit, 10)))]);
        }

        $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($data['search'])).'%';

        return $this->ok([
            'institutions' => Institution::query()
                ->where(fn ($query) => $query->where('name', 'like', $term)->orWhere('acronym', 'like', $term))
                ->orderBy('name')->limit($limit)
                ->get(['id', 'name', 'acronym', 'type', 'status', 'location'])
                ->map(fn (Institution $institution) => $institution->only(['id', 'name', 'acronym', 'type', 'status', 'location']))
                ->all(),
        ]);
    }
}
