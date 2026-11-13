<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentLink;
use App\Models\DocumentVersion;

/**
 * Forma JSON de documentos, versiones y adjuntos para la interfaz (claves en camelCase).
 * Fechas «AAAA-MM-DD» y fecha-hora «AAAA-MM-DD HH:MM:SS» en hora de Honduras.
 */
final class DocumentPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function directoryRow(Document $document): array
    {
        return [
            'id' => $document->id,
            'name' => $document->name,
            'category' => $document->category,
            'categoryLabel' => $document->categoryLabel(),
            'referenceNumber' => $document->reference_number,
            'ownerName' => $document->owner_name,
            'description' => $document->description,
            'issuedAt' => $document->issued_at?->toDateString(),
            'expiresAt' => $document->expires_at?->toDateString(),
            'status' => $document->computedStatus(),
            'daysToExpire' => $document->daysToExpire(),
            'version' => (int) $document->version,
            'fileName' => $document->original_filename,
            'mime' => $document->mime_type,
            'size' => $document->size,
            'signed' => $document->signed_at !== null,
            'uploadedBy' => $document->uploader?->name,
            'updatedAt' => $document->updated_at?->toDateTimeString(),
            'previewUrl' => route('documents.preview', $document),
            'downloadUrl' => route('documents.download', $document),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function version(DocumentVersion $version, Document $document): array
    {
        return [
            'id' => $version->id,
            'version' => $version->version,
            'current' => (int) $version->version === (int) $document->version,
            'fileName' => $version->original_filename,
            'mime' => $version->mime_type,
            'size' => $version->size,
            'issuedAt' => $version->issued_at?->toDateString(),
            'expiresAt' => $version->expires_at?->toDateString(),
            'note' => $version->note,
            'signed' => $version->signed_at !== null,
            'signedAt' => $version->signed_at?->toDateTimeString(),
            'signedBy' => $version->signer?->name,
            'uploadedBy' => $version->uploader?->name,
            'uploadedAt' => $version->created_at?->toDateTimeString(),
            'previewUrl' => route('documents.versions.preview', [$document, $version]),
            'downloadUrl' => route('documents.versions.download', [$document, $version]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function attachment(DocumentLink $link): array
    {
        $document = $link->document;
        $current = $document->currentVersion;

        return [
            'id' => $document->id,
            'linkId' => $link->id,
            'name' => $document->name,
            'fileName' => $document->original_filename,
            'extension' => strtolower(pathinfo((string) $document->original_filename, PATHINFO_EXTENSION)),
            'category' => $link->category ?? $document->category,
            'description' => $link->description ?? $document->description,
            'mime' => $document->mime_type,
            'size' => $document->size,
            'version' => (int) $document->version,
            'signed' => $current?->signed_at !== null,
            'signedAt' => $current?->signed_at?->toDateTimeString(),
            'signedBy' => $current?->signer?->name,
            'uploadedBy' => $link->creator?->name ?? $document->uploader?->name,
            'uploadedAt' => ($link->created_at ?? $document->created_at)?->toDateTimeString(),
            'previewUrl' => route('documents.preview', $document),
            'downloadUrl' => route('documents.download', $document),
            'versionsUrl' => route('documents.versions', $document),
        ];
    }
}
