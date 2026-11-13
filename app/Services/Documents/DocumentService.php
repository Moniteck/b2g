<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentLink;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Ciclo de vida de los documentos: versiones inmutables, vinculos con los
 * expedientes y limpieza de archivos.
 *
 * El archivo original nunca se sobrescribe: cada carga genera una version
 * nueva y la anterior permanece disponible para auditoria.
 */
class DocumentService
{
    public const ALLOWED_MIMES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public function __construct(protected AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, UploadedFile $file, User $actor): Document
    {
        $this->assertAccepted($file);

        $document = DB::transaction(function () use ($data, $file, $actor) {
            $document = Document::create([
                'company_id' => $data['company_id'] ?? null,
                'institution_id' => $data['institution_id'] ?? null,
                'name' => $data['name'],
                'kind' => $data['kind'] ?? 'proceso',
                'owner_name' => $data['owner_name'] ?? null,
                'required_for' => $data['required_for'] ?? null,
                'category' => $data['category'] ?? null,
                'issued_at' => $data['issued_at'] ?? null,
                'expires_at' => $data['expires_at'] ?? null,
                'status' => $data['status'] ?? 'Vigente',
                'version' => 1,
                'is_current' => true,
                'uploaded_by' => $actor->id,
                'renewal_owner_id' => $data['renewal_owner_id'] ?? $actor->id,
            ]);

            $path = $file->store('documents/private', 'local');

            $document->forceFill([
                'path' => $path,
                'disk' => 'local',
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'hash' => hash_file('sha256', $file->getRealPath()),
            ])->save();

            DocumentVersion::create([
                'document_id' => $document->id,
                'version' => 1,
                'path' => $path,
                'disk' => 'local',
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'hash' => $document->hash,
                'uploaded_by' => $actor->id,
                'created_at' => now(),
            ]);

            return $document;
        });

        $this->audit->record('documento.creado', $document, [
            'nombre' => $document->name,
            'categoria' => $document->category,
        ]);

        return $document->fresh();
    }

    /**
     * Agrega una version nueva conservando el archivo anterior.
     */
    public function addVersion(Document $document, UploadedFile $file, User $actor): DocumentVersion
    {
        $this->assertAccepted($file);

        $version = DB::transaction(function () use ($document, $file, $actor) {
            $next = ((int) $document->version) + 1;
            $path = $file->store('documents/private', 'local');
            $hash = hash_file('sha256', $file->getRealPath());

            $version = DocumentVersion::create([
                'document_id' => $document->id,
                'version' => $next,
                'path' => $path,
                'disk' => 'local',
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'hash' => $hash,
                'uploaded_by' => $actor->id,
                'created_at' => now(),
            ]);

            $document->forceFill([
                'version' => $next,
                'path' => $path,
                'disk' => 'local',
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'hash' => $hash,
                'uploaded_by' => $actor->id,
            ])->save();

            return $version;
        });

        $this->audit->record('documento.version', $document, [
            'version' => $version->version,
        ]);

        return $version;
    }

    /**
     * Vincula un documento a un expediente (cotizacion, contrato, entrega).
     */
    public function link(Document $document, object $linkable, ?DocumentVersion $version = null, ?string $context = null): DocumentLink
    {
        return DocumentLink::updateOrCreate([
            'document_id' => $document->id,
            'linkable_type' => $linkable::class,
            'linkable_id' => $linkable->getKey(),
        ], [
            'document_version_id' => $version?->id ?? $document->versions()->max('id'),
            'context' => $context,
        ]);
    }

    /**
     * Elimina el documento y todos sus archivos.
     */
    public function delete(Document $document): void
    {
        DB::transaction(function () use ($document) {
            foreach ($document->versions as $version) {
                Storage::disk($version->disk)->delete($version->path);
            }

            $document->delete();
        });

        $this->audit->record('documento.eliminado', $document, [
            'nombre' => $document->name,
        ]);
    }

    /**
     * Almacena una version firmada derived, sin alterar el original.
     */
    public function storeDerivedVersion(Document $document, string $contents, string $originalName): DocumentVersion
    {
        $next = ((int) $document->version) + 1;
        $path = 'documents/private/'.Str::uuid().'-'.Str::limit(Str::slug(pathinfo($originalName, PATHINFO_FILENAME)), 40).'.pdf';

        Storage::disk('local')->put($path, $contents);

        $version = DocumentVersion::create([
            'document_id' => $document->id,
            'version' => $next,
            'path' => $path,
            'disk' => 'local',
            'original_filename' => $originalName,
            'mime_type' => 'application/pdf',
            'size' => strlen($contents),
            'hash' => hash('sha256', $contents),
            'uploaded_by' => null,
            'created_at' => now(),
        ]);

        $document->forceFill([
            'version' => $next,
            'path' => $path,
            'disk' => 'local',
            'original_filename' => $originalName,
            'mime_type' => 'application/pdf',
            'size' => strlen($contents),
            'hash' => hash('sha256', $contents),
            'signed_at' => now(),
        ])->save();

        return $version;
    }

    protected function assertAccepted(UploadedFile $file): void
    {
        $mime = $file->getMimeType();

        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new RuntimeException("Tipo de archivo no permitido: {$mime}.");
        }

        if ($file->getSize() > 25 * 1024 * 1024) {
            throw new RuntimeException('El archivo supera el limite de 25 MB.');
        }
    }
}
