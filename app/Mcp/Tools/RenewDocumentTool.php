<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\Base64File;
use App\Mcp\Support\Present;
use App\Models\Document;
use App\Models\User;
use App\Services\Documents\DocumentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('renew_document')]
#[Title('Renovar documento')]
#[Description('Renueva un documento: nueva emisión y vencimiento y, opcionalmente, el archivo renovado (base64, máx. 10 MB), que queda como versión nueva sin borrar la anterior. Sus avisos de vencimiento se reprograman.')]
#[IsDestructive(false)]
class RenewDocumentTool extends BaseTool
{
    protected ?array $record = ['document_id', Document::class];

    public function schema(JsonSchema $schema): array
    {
        return [
            'document_id' => $schema->integer()->description('Id del documento.')->required(),
            ...$this->companySchema($schema),
            'issued_at' => $schema->string()->description('Nueva emisión, «AAAA-MM-DD».')->required(),
            'expires_at' => $schema->string()->description('Nuevo vencimiento, «AAAA-MM-DD» (no anterior a hoy).'),
            'content_base64' => $schema->string()->description('Archivo renovado en base64 (opcional).'),
            'file_name' => $schema->string()->description('Nombre del archivo renovado (obligatorio si se envía el archivo).'),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $document = $this->find(Document::class, $request->get('document_id'), 'el documento');
        $this->authorize('update', $document);

        $data = $this->validate($request->all(), [
            'issued_at' => ['required', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:issued_at', 'after_or_equal:today'],
        ], [
            'issued_at.required' => 'Indica la nueva fecha de emisión.',
            'expires_at.after_or_equal' => 'El nuevo vencimiento no puede ser anterior a la emisión ni a hoy.',
        ]);

        $file = filled($request->get('content_base64')) ? Base64File::decode($request->get('content_base64'), $request->get('file_name')) : null;
        $renewed = app(DocumentService::class)->renew($document, $user, $data['issued_at'], $data['expires_at'] ?? null, $file);

        return $this->ok(['document' => Present::document($renewed)], 'Documento renovado.');
    }
}
