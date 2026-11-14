<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\Base64File;
use App\Mcp\Support\Present;
use App\Models\Document;
use App\Models\User;
use App\Services\Documents\DocumentService;
use App\Services\Documents\FileTypes;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('upload_document')]
#[Title('Subir documento al Directorio')]
#[Description('Sube un documento de la empresa al Directorio Institucional (RTN, solvencias, escritura…) con su categoría, emisión y vencimiento; el vencimiento crea su evento y sus avisos (30, 7 y 1 día). Archivo en base64, máx. 10 MB (PDF, JPG, PNG, WebP, Word o Excel); el servidor revisa el tipo real y que el PDF se pueda abrir. Para adjuntar un archivo a una licitación, cotización o contrato use add_attachment.')]
#[IsDestructive(false)]
class UploadDocumentTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->companySchema($schema),
            'content_base64' => $schema->string()->description('Archivo en base64.')->required(),
            'file_name' => $schema->string()->description('Nombre del archivo con extensión.')->required(),
            'category' => $schema->string()->enum(array_keys(Document::CATEGORIES))->description('Categoría del Directorio.')->required(),
            'name' => $schema->string()->description('Nombre visible (por defecto, el del archivo).'),
            'reference_number' => $schema->string()->description('Número o referencia del documento.'),
            'owner_name' => $schema->string()->description('Titular.'),
            'description' => $schema->string()->description('Descripción.'),
            'issued_at' => $schema->string()->description('Emisión, «AAAA-MM-DD».'),
            'expires_at' => $schema->string()->description('Vencimiento, «AAAA-MM-DD».'),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $this->authorize('create', Document::class);

        $data = $this->validate($request->all(), [
            'category' => ['required', Rule::in(array_keys(Document::CATEGORIES))],
            'name' => ['nullable', 'string', 'max:255'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'issued_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:issued_at'],
        ], ['expires_at.after_or_equal' => 'El vencimiento no puede ser anterior a la emisión.']);

        $file = Base64File::decode($request->get('content_base64'), $request->get('file_name'));

        $document = app(DocumentService::class)->create($data + ['company_id' => $companyId, 'kind' => 'empresa'], $file, $user, null, [
            'field' => 'content_base64',
            'allowed' => FileTypes::ALL,
        ]);

        return $this->ok(['document' => Present::document($document)], 'Documento subido al Directorio Institucional.');
    }
}
