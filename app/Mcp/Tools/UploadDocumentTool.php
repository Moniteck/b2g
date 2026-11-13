<?php

namespace App\Mcp\Tools;

use App\Models\Document;
use App\Models\Quote;
use App\Services\Documents\DocumentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\UploadedFile;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('upload_document')]
#[Title('Adjuntar documento')]
#[Description('Sube un archivo al expediente y lo vincula a una cotizacion, un contrato o el directorio documental de la empresa. El contenido se envia en base64; los originales nunca se sobrescriben, cada carga crea una version nueva.')]
class UploadDocumentTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'nombre' => $schema->string()->description('Nombre del documento.')->required(),
            'nombre_archivo' => $schema->string()->description('Nombre original del archivo, con extension.')->required(),
            'contenido_base64' => $schema->string()->description('Contenido del archivo codificado en base64.')->required(),
            'tipo' => $schema->string()->enum(array_keys(Document::KINDS))->description('Tipo de expediente: empresa, institucion, proceso o contrato.'),
            'categoria' => $schema->string()->description('Categoria del documento (RTN, DNI, Solvencia, etc.).'),
            'titular' => $schema->string()->description('Titular del documento, por ejemplo el nombre del representante.'),
            'emision' => $schema->string()->description('Fecha de emision en formato YYYY-MM-DD.'),
            'vencimiento' => $schema->string()->description('Fecha de vencimiento en formato YYYY-MM-DD.'),
            'cotizacion_id' => $schema->integer()->description('Cotizacion a la que se vincula el documento.'),
            'contrato_id' => $schema->integer()->description('Contrato al que se vincula el documento.'),
            'empresa_id' => $schema->integer()->description('Empresa propietaria. Omitirlo usa la empresa activa.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $user = $this->user($request);
        $companyId = $this->resolveCompanyId($request, $request->get('empresa_id') ? (int) $request->get('empresa_id') : null);

        $data = $request->validate([
            'nombre' => 'required|string|max:255',
            'nombre_archivo' => 'required|string|max:255',
            'contenido_base64' => 'required|string',
            'tipo' => 'nullable|in:'.implode(',', array_keys(Document::KINDS)),
            'categoria' => 'nullable|string|max:100',
            'titular' => 'nullable|string|max:255',
            'emision' => 'nullable|date',
            'vencimiento' => 'nullable|date|after_or_equal:emision',
            'cotizacion_id' => 'nullable|integer|exists:quotes,id',
            'contrato_id' => 'nullable|integer|exists:contracts,id',
        ]);

        $contents = base64_decode($data['contenido_base64'], true);

        if ($contents === false || $contents === '') {
            return $this->fail('El contenido enviado no es base64 valido.');
        }

        $temporary = tempnam(sys_get_temp_dir(), 'mcp-upload-');
        file_put_contents($temporary, $contents);

        $file = new UploadedFile($temporary, $data['nombre_archivo'], null, null, true);

        $document = app(DocumentService::class)->create([
            'company_id' => $companyId,
            'name' => $data['nombre'],
            'kind' => $data['tipo'] ?? 'proceso',
            'owner_name' => $data['titular'] ?? null,
            'category' => $data['categoria'] ?? null,
            'issued_at' => $data['emision'] ?? null,
            'expires_at' => $data['vencimiento'] ?? null,
            'status' => 'Vigente',
        ], $file, $user);

        @unlink($temporary);

        $linked = null;

        if (! empty($data['cotizacion_id'])) {
            $quote = Quote::query()->find($data['cotizacion_id']);
            abort_unless($quote && $user->canAccessCompany($quote->company_id), 403, 'No tiene acceso a esa cotizacion.');
            app(DocumentService::class)->link($document, $quote, null, 'cotizacion');
            $linked = 'cotizacion';
        }

        if (! empty($data['contrato_id'])) {
            $contract = \App\Models\Contract::query()->find($data['contrato_id']);
            abort_unless($contract && $user->canAccessCompany($contract->company_id), 403, 'No tiene acceso a ese contrato.');
            app(DocumentService::class)->link($document, $contract, null, 'contrato');
            $linked = 'contrato';
        }

        return $this->ok([
            'documento_id' => $document->id,
            'nombre' => $document->name,
            'version' => $document->version,
            'tamano' => $document->size,
            'vinculado_a' => $linked,
        ], 'Documento cargado.');
    }
}
