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

#[Name('list_expiring_documents')]
#[Title('Documentos por vencer')]
#[Description('Documentos de la empresa ya vencidos o que vencen en los próximos N días (por defecto 30), del más urgente al menos urgente. Útil antes de armar una oferta: renuévelos con renew_document.')]
#[IsReadOnly]
#[IsIdempotent]
class ListExpiringDocumentsTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->companySchema($schema),
            'days' => $schema->integer()->description('Ventana en días (1 a 365, por defecto 30).'),
            'include_expired' => $schema->boolean()->description('Incluir los ya vencidos (por defecto true).'),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $this->authorize('viewAny', Document::class);

        $data = $this->validate($request->all(), ['days' => ['nullable', 'integer', 'min:1', 'max:365'], 'include_expired' => ['nullable', 'boolean']]);
        $days = (int) ($data['days'] ?? 30);
        $today = today();

        $documents = Document::query()->directory()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $today->copy()->addDays($days)->toDateString())
            ->when(! ($data['include_expired'] ?? true), fn ($query) => $query->where('expires_at', '>=', $today->toDateString()))
            ->orderBy('expires_at')
            ->limit(200)
            ->get();

        return $this->ok([
            'company_id' => $companyId,
            'days' => $days,
            'count' => $documents->count(),
            'documents' => $documents->map(fn (Document $document) => Present::document($document))->all(),
        ]);
    }
}
