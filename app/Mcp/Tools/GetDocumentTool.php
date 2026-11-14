<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\Present;
use App\Models\Document;
use App\Models\DocumentLink;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\Documents\Attachables;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_document')]
#[Title('Detalle de documento')]
#[Description('Ficha de un documento: vigencia, historial de versiones (subidas, renovaciones y versiones firmadas, con quién y cuándo) y a qué registros está adjunto. No devuelve el contenido del archivo.')]
#[IsReadOnly]
#[IsIdempotent]
class GetDocumentTool extends BaseTool
{
    protected ?array $record = ['document_id', Document::class];

    public function schema(JsonSchema $schema): array
    {
        return [
            'document_id' => $schema->integer()->description('Id del documento.')->required(),
            ...$this->companySchema($schema),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $document = $this->find(Document::class, $request->get('document_id'), 'el documento');
        $this->authorize('view', $document);

        $versions = DocumentVersion::query()->where('document_id', $document->id)
            ->with(['uploader:id,name', 'signer:id,name'])->orderByDesc('version')->limit(50)->get()
            ->map(fn (DocumentVersion $version) => [
                'version' => (int) $version->version,
                'size' => $version->size,
                'signed' => $version->signed_at !== null,
                'signed_by' => $version->signer?->name,
                'issued_at' => $version->issued_at?->format('Y-m-d'),
                'expires_at' => $version->expires_at?->format('Y-m-d'),
                'uploaded_by' => $version->uploader?->name,
                'created_at' => Present::dt($version->created_at),
                ...$this->untrusted(['file_name' => $version->original_filename, 'note' => $version->note], 300),
            ])->all();

        $links = DocumentLink::query()->where('document_id', $document->id)->get(['linkable_type', 'linkable_id', 'context'])
            ->map(fn (DocumentLink $link) => [
                'entity_type' => collect(Attachables::TYPES)->search(fn ($definition) => $definition['model'] === $link->linkable_type) ?: class_basename($link->linkable_type),
                'entity_id' => $link->linkable_id,
                'context' => $link->context,
            ])->all();

        return $this->ok([
            'document' => Present::document($document),
            'versions' => $versions,
            'attached_to' => $links,
            'can' => ['update' => $user->can('update', $document), 'sign' => $user->can('sign', $document)],
        ]);
    }
}
