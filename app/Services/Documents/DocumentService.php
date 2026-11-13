<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentLink;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Único camino para guardar archivos de documentos.
 *
 * - Revisa cada archivo con `UploadInspector` (tipo real, tamaño, PDF legible): todo rechazo es 422.
 * - Guarda en el disco privado (`local`, fuera de `public`).
 * - Escribe en una transacción; si algo falla, el archivo recién guardado se borra (sin huérfanos).
 *   Al eliminar, los archivos se borran solo después de confirmar la transacción.
 * - Numera las versiones bajo bloqueo del documento: dos subidas a la vez no repiten número.
 * - El original nunca se sobrescribe: cada carga, renovación o firma crea una versión nueva.
 *
 * Firmas públicas estables (las usa también el módulo de firma):
 *   create(array $data, UploadedFile $file, User $actor, ?Model $attachTo = null, array $link = []): Document
 *   addVersion(Document $document, UploadedFile $file, User $actor, array $attributes = []): DocumentVersion
 *   storeDerivedVersion(Document $document, string $contents, string $originalName, ?User $actor = null, bool $signed = true): DocumentVersion
 *   link(Document $document, Model $linkable, ?DocumentVersion $version = null, ?string $context = null, array $meta = []): DocumentLink
 *   delete(Document $document): void
 */
class DocumentService
{
    public const DISK = 'local';

    public const DIRECTORY = 'documents/private';

    /** @deprecated Usa FileTypes::EXTENSIONS. Se conserva para quien la lea. */
    public const ALLOWED_MIMES = [
        'application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public function __construct(protected AuditLogger $audit, protected UploadInspector $inspector) {}

    /**
     * Crea un documento con su versión 1 y, si se indica, lo enlaza a una entidad.
     *
     * @param  array<string, mixed>  $data  name, kind, category, reference_number, description, owner_name,
     *                                      issued_at, expires_at, company_id, institution_id, required_for
     * @param  array{category?: ?string, description?: ?string, context?: ?string, field?: string, allowed?: array<int, string>}  $link
     *
     * @throws ValidationException
     */
    public function create(array $data, UploadedFile $file, User $actor, ?Model $attachTo = null, array $link = []): Document
    {
        $info = $this->inspector->inspect($file, $link['field'] ?? 'file', $link['allowed'] ?? FileTypes::ALL);
        $path = $this->putFile($file, $info['extension']);

        try {
            $document = DB::transaction(function () use ($data, $info, $path, $actor, $attachTo, $link) {
                DB::afterRollBack(fn () => $this->deleteFiles([[self::DISK, $path]]));

                $document = new Document(Arr::only($data, [
                    'company_id', 'institution_id', 'owner_name', 'required_for', 'reference_number', 'description',
                ]));
                $document->forceFill([
                    'company_id' => $data['company_id'] ?? $attachTo?->getAttribute('company_id'),
                    'name' => $this->documentName($data['name'] ?? null, $info['name']),
                    'kind' => $data['kind'] ?? 'proceso',
                    'category' => $data['category'] ?? null,
                    'issued_at' => $data['issued_at'] ?? null,
                    'expires_at' => $data['expires_at'] ?? null,
                    'version' => 1,
                    'is_current' => true,
                    'uploaded_by' => $actor->id,
                    'renewal_owner_id' => $data['renewal_owner_id'] ?? $actor->id,
                    'path' => $path,
                    'disk' => self::DISK,
                    'original_filename' => $info['name'],
                    'mime_type' => $info['mime'],
                    'size' => $info['size'],
                    'hash' => $info['hash'],
                ])->save();

                $version = DocumentVersion::create([
                    'document_id' => $document->id,
                    'version' => 1,
                    'path' => $path,
                    'disk' => self::DISK,
                    'original_filename' => $info['name'],
                    'mime_type' => $info['mime'],
                    'size' => $info['size'],
                    'hash' => $info['hash'],
                    'issued_at' => $document->issued_at,
                    'expires_at' => $document->expires_at,
                    'uploaded_by' => $actor->id,
                    'created_at' => now(),
                ]);

                if ($attachTo !== null) {
                    $this->link($document, $attachTo, $version, $link['context'] ?? null, [
                        'category' => $link['category'] ?? null,
                        'description' => $link['description'] ?? null,
                        'created_by' => $actor->id,
                    ]);
                }

                return $document;
            });
        } catch (Throwable $exception) {
            $this->deleteFiles([[self::DISK, $path]]);

            throw $exception;
        }

        $this->audit->record('documento.subido', $document, array_filter([
            'nombre' => $document->name,
            'categoria' => $document->category,
            'archivo' => $info['name'],
            'adjunto_a' => $attachTo !== null ? class_basename($attachTo).' #'.$attachTo->getKey() : null,
        ]), $actor);

        return $document->fresh();
    }

    /**
     * Versión nueva con un archivo subido (reemplazar o renovar). El archivo anterior se conserva.
     *
     * Con `issued_at`/`expires_at` en `$attributes` también se actualiza la vigencia del documento.
     *
     * @param  array{issued_at?: mixed, expires_at?: mixed, note?: ?string, field?: string, allowed?: array<int, string>, action?: string}  $attributes
     *
     * @throws ValidationException
     */
    public function addVersion(Document $document, UploadedFile $file, User $actor, array $attributes = []): DocumentVersion
    {
        $info = $this->inspector->inspect($file, $attributes['field'] ?? 'file', $attributes['allowed'] ?? FileTypes::ALL);
        $path = $this->putFile($file, $info['extension']);

        try {
            $version = DB::transaction(function () use ($document, $info, $path, $actor, $attributes) {
                DB::afterRollBack(fn () => $this->deleteFiles([[self::DISK, $path]]));

                $locked = $this->lock($document);
                $dates = Arr::only($attributes, ['issued_at', 'expires_at']);
                $next = $this->nextVersionNumber($locked);

                $version = DocumentVersion::create([
                    'document_id' => $locked->id,
                    'version' => $next,
                    'path' => $path,
                    'disk' => self::DISK,
                    'original_filename' => $info['name'],
                    'mime_type' => $info['mime'],
                    'size' => $info['size'],
                    'hash' => $info['hash'],
                    'issued_at' => array_key_exists('issued_at', $dates) ? $dates['issued_at'] : $locked->issued_at,
                    'expires_at' => array_key_exists('expires_at', $dates) ? $dates['expires_at'] : $locked->expires_at,
                    'note' => $attributes['note'] ?? null,
                    'uploaded_by' => $actor->id,
                    'created_at' => now(),
                ]);

                $locked->forceFill(array_merge($dates, [
                    'version' => $next,
                    'path' => $path,
                    'disk' => self::DISK,
                    'original_filename' => $info['name'],
                    'mime_type' => $info['mime'],
                    'size' => $info['size'],
                    'hash' => $info['hash'],
                    'uploaded_by' => $actor->id,
                    'signed_at' => null,
                    'signed_by' => null,
                ]))->save();

                $document->setRawAttributes($locked->getAttributes(), true);

                return $version;
            });
        } catch (Throwable $exception) {
            $this->deleteFiles([[self::DISK, $path]]);

            throw $exception;
        }

        $this->audit->record($attributes['action'] ?? 'documento.reemplazado', $document, array_filter([
            'version' => $version->version,
            'archivo' => $info['name'],
            'vencimiento' => $document->expires_at?->toDateString(),
        ]), $actor);

        return $version;
    }

    /**
     * Renueva la vigencia; con archivo crea una versión nueva, sin archivo solo cambia las fechas.
     */
    public function renew(Document $document, User $actor, mixed $issuedAt, mixed $expiresAt, ?UploadedFile $file = null): Document
    {
        if ($file !== null) {
            $this->addVersion($document, $file, $actor, [
                'issued_at' => $issuedAt,
                'expires_at' => $expiresAt,
                'note' => 'Renovación',
                'action' => 'documento.renovado',
            ]);

            return $document->fresh();
        }

        DB::transaction(function () use ($document, $issuedAt, $expiresAt) {
            $locked = $this->lock($document);
            $locked->forceFill(['issued_at' => $issuedAt, 'expires_at' => $expiresAt])->save();
            $document->setRawAttributes($locked->getAttributes(), true);
        });

        $this->audit->record('documento.renovado', $document, array_filter([
            'emision' => $document->issued_at?->toDateString(),
            'vencimiento' => $document->expires_at?->toDateString(),
        ]), $actor);

        return $document->fresh();
    }

    /**
     * Enlaza un documento a una entidad (cotización, contrato, licitación…). Idempotente.
     *
     * @param  array{category?: ?string, description?: ?string, created_by?: ?int}  $meta
     */
    public function link(Document $document, object $linkable, ?DocumentVersion $version = null, ?string $context = null, array $meta = []): DocumentLink
    {
        return DocumentLink::updateOrCreate([
            'document_id' => $document->id,
            'linkable_type' => $linkable::class,
            'linkable_id' => $linkable->getKey(),
        ], array_merge([
            'document_version_id' => $version?->id ?? $document->versions()->max('id'),
            'context' => $context,
        ], array_filter($meta, fn ($value) => $value !== null)));
    }

    /**
     * Quita un adjunto de una entidad. Si el documento ya no está enlazado a nada y no es del
     * Directorio Institucional, se elimina con sus archivos.
     *
     * @return bool true si además se eliminó el documento
     */
    public function detach(Document $document, Model $linkable, User $actor): bool
    {
        $removed = DB::transaction(function () use ($document, $linkable) {
            $this->lock($document);

            DocumentLink::query()
                ->where('document_id', $document->id)
                ->where('linkable_type', $linkable::class)
                ->where('linkable_id', $linkable->getKey())
                ->delete();

            return $document->kind !== 'empresa' && ! $document->links()->exists();
        });

        $this->audit->record('documento.adjunto_quitado', $document, [
            'nombre' => $document->name,
            'entidad' => class_basename($linkable).' #'.$linkable->getKey(),
        ], $actor);

        if ($removed) {
            $this->delete($document);
        }

        return $removed;
    }

    /**
     * Elimina el documento, sus versiones y enlaces. Los archivos se borran después de confirmar.
     */
    public function delete(Document $document): void
    {
        $files = DB::transaction(function () use ($document) {
            $this->lock($document);

            $files = $document->versions()->get(['disk', 'path'])
                ->map(fn (DocumentVersion $version) => [$version->disk, $version->path])
                ->push([$document->disk, $document->path])
                ->all();

            $document->delete();

            return $files;
        });

        // Si quien llama tiene su propia transacción abierta, se espera a que la confirme.
        DB::afterCommit(fn () => $this->deleteFiles($files));

        $this->audit->record('documento.eliminado', $document, [
            'nombre' => $document->name,
            'categoria' => $document->category,
        ]);
    }

    /**
     * Versión derivada (p. ej. firmada) generada por el sistema, sin alterar el original.
     */
    public function storeDerivedVersion(Document $document, string $contents, string $originalName, ?User $actor = null, bool $signed = true): DocumentVersion
    {
        $actor ??= request()?->user();
        $name = $this->inspector->cleanName($originalName);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: 'pdf';
        $path = self::DIRECTORY.'/'.Str::uuid().'.'.$extension;
        $mime = FileTypes::CANONICAL_MIMES[$extension] ?? 'application/pdf';
        $hash = hash('sha256', $contents);
        $size = strlen($contents);

        Storage::disk(self::DISK)->put($path, $contents);

        try {
            $version = DB::transaction(function () use ($document, $name, $path, $mime, $hash, $size, $actor, $signed) {
                DB::afterRollBack(fn () => $this->deleteFiles([[self::DISK, $path]]));

                $locked = $this->lock($document);
                $next = $this->nextVersionNumber($locked);
                $signedAt = $signed ? now() : null;

                $version = DocumentVersion::create([
                    'document_id' => $locked->id,
                    'version' => $next,
                    'path' => $path,
                    'disk' => self::DISK,
                    'original_filename' => $name,
                    'mime_type' => $mime,
                    'size' => $size,
                    'hash' => $hash,
                    'issued_at' => $locked->issued_at,
                    'expires_at' => $locked->expires_at,
                    'note' => $signed ? 'Firmado' : null,
                    'signed_at' => $signedAt,
                    'signed_by' => $signed ? $actor?->id : null,
                    'uploaded_by' => $actor?->id,
                    'created_at' => now(),
                ]);

                $locked->forceFill([
                    'version' => $next,
                    'path' => $path,
                    'disk' => self::DISK,
                    'original_filename' => $name,
                    'mime_type' => $mime,
                    'size' => $size,
                    'hash' => $hash,
                    'signed_at' => $signedAt,
                    'signed_by' => $signed ? $actor?->id : null,
                ])->save();

                $document->setRawAttributes($locked->getAttributes(), true);

                return $version;
            });
        } catch (Throwable $exception) {
            $this->deleteFiles([[self::DISK, $path]]);

            throw $exception;
        }

        return $version;
    }

    /**
     * Arma un ZIP con la versión vigente de cada documento. Devuelve la ruta del archivo temporal.
     *
     * @param  iterable<Document>  $documents
     */
    public function zip(iterable $documents, User $actor): string
    {
        $target = tempnam(sys_get_temp_dir(), 'docs-zip-');
        $zip = new \ZipArchive;

        if ($target === false || $zip->open($target, \ZipArchive::OVERWRITE) !== true) {
            throw ValidationException::withMessages(['ids' => 'No se pudo preparar el archivo comprimido. Vuelve a intentarlo.']);
        }

        $used = [];
        $names = [];

        foreach ($documents as $document) {
            $disk = Storage::disk($document->disk ?: self::DISK);

            if (! $document->path || ! $disk->exists($document->path)) {
                continue;
            }

            $extension = strtolower(pathinfo((string) $document->original_filename, PATHINFO_EXTENSION)) ?: 'pdf';
            $base = Str::limit(trim(preg_replace('/[\\\\\/:*?"<>|]+/', '-', ($document->categoryLabel() ? $document->categoryLabel().' - ' : '').$document->name)), 120, '');
            $entry = $base.'.'.$extension;

            for ($copy = 2; isset($used[strtolower($entry)]); $copy++) {
                $entry = "{$base} ({$copy}).{$extension}";
            }

            $used[strtolower($entry)] = true;
            $zip->addFile($disk->path($document->path), $entry);
            $names[] = $document->name;
        }

        $zip->close();

        $this->audit->record('documento.paquete_descargado', null, ['documentos' => $names], $actor);

        return $target;
    }

    protected function lock(Document $document): Document
    {
        return Document::query()->withoutGlobalScopes()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();
    }

    protected function nextVersionNumber(Document $document): int
    {
        $max = (int) DocumentVersion::query()->where('document_id', $document->id)->max('version');

        return max($max, (int) $document->version) + 1;
    }

    protected function putFile(UploadedFile $file, string $extension): string
    {
        $path = $file->storeAs(self::DIRECTORY, Str::uuid().'.'.$extension, self::DISK);

        if ($path === false) {
            throw ValidationException::withMessages(['file' => 'No se pudo guardar el archivo en el servidor. Vuelve a intentarlo.']);
        }

        return $path;
    }

    /**
     * @param  array<int, array{0: ?string, 1: ?string}>  $files  [disco, ruta]
     */
    protected function deleteFiles(array $files): void
    {
        foreach ($files as [$disk, $path]) {
            if ($path) {
                try {
                    Storage::disk($disk ?: self::DISK)->delete($path);
                } catch (Throwable) {
                    // Un archivo que no se pudo borrar no debe deshacer la operación.
                }
            }
        }
    }

    protected function documentName(?string $name, string $filename): string
    {
        $name = trim((string) $name);

        return mb_substr($name !== '' ? $name : pathinfo($filename, PATHINFO_FILENAME), 0, 255);
    }
}
