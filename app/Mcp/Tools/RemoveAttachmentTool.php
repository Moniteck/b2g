<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\ToolError;
use App\Mcp\Support\Untrusted;
use App\Mcp\Tools\Concerns\TargetsAttachable;
use App\Models\Document;
use App\Models\DocumentLink;
use App\Models\User;
use App\Services\Documents\Attachables;
use App\Services\Documents\DocumentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('remove_attachment')]
#[Title('Quitar adjunto')]
#[Description('Quita un archivo adjunto de un registro; si el documento no queda adjunto a nada más (y no es del Directorio), se elimina con sus versiones. ACCIÓN SENSIBLE en dos pasos: la primera llamada devuelve un resumen y un confirmation_code; muéstralo a la persona y, solo si confirma, repite la llamada con los mismos argumentos y el código.')]
#[IsDestructive]
class RemoveAttachmentTool extends BaseTool
{
    use TargetsAttachable;

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->entitySchema($schema),
            'document_id' => $schema->integer()->description('Id del documento adjunto.')->required(),
            ...$this->companySchema($schema),
            ...$this->confirmationSchema($schema),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $entity = $this->entity($request);
        $this->authorize(Attachables::editAbility($entity), $entity);

        $link = DocumentLink::query()
            ->where('linkable_type', $entity::class)->where('linkable_id', $entity->getKey())
            ->where('document_id', (int) $request->get('document_id'))
            ->whereHas('document')
            ->first() ?? throw new ToolError('Ese documento no está adjunto al registro indicado.');

        $document = Document::query()->findOrFail($link->document_id);
        $willDelete = $document->kind !== 'empresa' && DocumentLink::query()->where('document_id', $document->id)->count() === 1;

        return $this->confirmed($request, 'remove_attachment', $document, [
            'action' => 'Quitar adjunto',
            'entity' => Attachables::TYPES[$request->get('entity_type')]['label'].' #'.$entity->getKey(),
            'deletes_document' => $willDelete,
            'effect' => $willDelete ? 'El documento y todas sus versiones se eliminarán: no se puede deshacer.' : 'Solo se quita el vínculo; el documento se conserva.',
            ...Untrusted::of(['document' => $document->name, 'file_name' => $document->original_filename], 255),
        ], function () use ($document, $entity, $user) {
            $deleted = app(DocumentService::class)->detach($document, $entity, $user);

            return $this->ok(['document_id' => $document->id, 'document_deleted' => $deleted], "Se quitó el adjunto {$document->id}.");
        });
    }
}
