<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\Present;
use App\Mcp\Tools\Concerns\TargetsAttachable;
use App\Models\DocumentLink;
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

#[Name('list_attachments')]
#[Title('Adjuntos de un registro')]
#[Description('Archivos adjuntos a una licitación, cotización, oportunidad, contrato o cliente, con categoría, versión y si están firmados.')]
#[IsReadOnly]
#[IsIdempotent]
class ListAttachmentsTool extends BaseTool
{
    use TargetsAttachable;

    public function schema(JsonSchema $schema): array
    {
        return [...$this->entitySchema($schema), ...$this->companySchema($schema)];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $entity = $this->entity($request);
        $this->authorize('view', $entity);

        $attachments = DocumentLink::query()
            ->where('linkable_type', $entity::class)->where('linkable_id', $entity->getKey())
            ->whereHas('document')
            ->with('document')
            ->latest('id')->limit(100)->get()
            ->map(fn (DocumentLink $link) => [
                'link_category' => $link->category,
                'context' => $link->context,
                ...Present::document($link->document),
            ])->all();

        return $this->ok([
            'entity_type' => $request->get('entity_type'),
            'entity_id' => $entity->getKey(),
            'can_edit' => $user->can(Attachables::editAbility($entity), $entity),
            'attachments' => $attachments,
        ]);
    }
}
