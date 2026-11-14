<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\Base64File;
use App\Mcp\Support\Present;
use App\Mcp\Tools\Concerns\TargetsAttachable;
use App\Models\User;
use App\Services\Documents\Attachables;
use App\Services\Documents\DocumentService;
use App\Services\Documents\FileTypes;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('add_attachment')]
#[Title('Adjuntar archivo a un registro')]
#[Description('Adjunta un archivo (base64, máx. 10 MB; PDF, imagen, Word o Excel) a una licitación, cotización, oportunidad, contrato o cliente, con categoría y descripción opcionales. El servidor revisa el tipo real y que el PDF se pueda abrir; el original nunca se sobrescribe.')]
#[IsDestructive(false)]
class AddAttachmentTool extends BaseTool
{
    use TargetsAttachable;

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->entitySchema($schema),
            ...$this->companySchema($schema),
            'content_base64' => $schema->string()->description('Archivo en base64.')->required(),
            'file_name' => $schema->string()->description('Nombre del archivo con extensión.')->required(),
            'category' => $schema->string()->description('Categoría (p. ej. Contrato, Orden de compra, Factura, Otro).'),
            'description' => $schema->string()->description('Descripción.'),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $entity = $this->entity($request);
        $this->authorize(Attachables::editAbility($entity), $entity);

        $data = $this->validate($request->all(), [
            'category' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $type = (string) $request->get('entity_type');
        $file = Base64File::decode($request->get('content_base64'), $request->get('file_name'));

        $document = app(DocumentService::class)->create([
            'company_id' => $entity->getAttribute('company_id'),
            'kind' => Attachables::kindOf($type),
            'category' => $data['category'] ?? null,
            'description' => $data['description'] ?? null,
        ], $file, $user, $entity, [
            'field' => 'content_base64',
            'context' => $type,
            'category' => $data['category'] ?? null,
            'description' => $data['description'] ?? null,
            'allowed' => FileTypes::ALL,
        ]);

        return $this->ok(['entity_type' => $type, 'entity_id' => $entity->getKey(), 'document' => Present::document($document)], 'Archivo adjuntado.');
    }
}
