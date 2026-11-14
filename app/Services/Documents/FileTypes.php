<?php

namespace App\Services\Documents;

use App\Support\Binaries;

/**
 * Lista única de archivos que acepta el sistema: extensión => grupo y tipos MIME reales
 * (detectados por el contenido, no por lo que dice el navegador).
 *
 * Grupos y límites:
 *   pdf     Binaries::maxPdfKilobytes()   (40 MB por defecto)
 *   image   Binaries::maxImageKilobytes() (10 MB por defecto)
 *   office  Binaries::maxPdfKilobytes()   (Word y Excel)
 */
final class FileTypes
{
    public const EXTENSIONS = [
        'pdf' => ['group' => 'pdf', 'mimes' => ['application/pdf', 'application/x-pdf']],
        'jpg' => ['group' => 'image', 'mimes' => ['image/jpeg', 'image/pjpeg']],
        'jpeg' => ['group' => 'image', 'mimes' => ['image/jpeg', 'image/pjpeg']],
        'png' => ['group' => 'image', 'mimes' => ['image/png']],
        'webp' => ['group' => 'image', 'mimes' => ['image/webp']],
        'doc' => ['group' => 'office', 'mimes' => ['application/msword', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/CDFV2', 'application/cdfv2']],
        'docx' => ['group' => 'office', 'mimes' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream']],
        'xls' => ['group' => 'office', 'mimes' => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/CDFV2', 'application/cdfv2']],
        'xlsx' => ['group' => 'office', 'mimes' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream']],
    ];

    /** Tipo MIME con el que se guarda y se sirve cada extensión. */
    public const CANONICAL_MIMES = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /** Lo que se acepta cuando la pantalla no restringe nada. */
    public const ALL = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx', 'xls', 'xlsx'];

    /** Solo PDF (adjuntos por defecto). */
    public const PDF_ONLY = ['pdf'];

    public static function groupOf(string $extension): ?string
    {
        return self::EXTENSIONS[strtolower($extension)]['group'] ?? null;
    }

    public static function maxKilobytesFor(string $extension): int
    {
        return self::groupOf($extension) === 'image' ? Binaries::maxImageKilobytes() : Binaries::maxPdfKilobytes();
    }

    /** Atributo `accept` de un `<input type="file">`: «.pdf,.jpg,…». */
    public static function acceptAttribute(array $extensions = self::ALL): string
    {
        return implode(',', array_map(fn (string $extension) => '.'.$extension, $extensions));
    }

    /** Texto para mensajes: «PDF, JPG, PNG…». */
    public static function label(array $extensions = self::ALL): string
    {
        return implode(', ', array_unique(array_map(fn (string $extension) => strtoupper($extension === 'jpeg' ? 'jpg' : $extension), $extensions)));
    }

    /**
     * Límites para la interfaz (MB enteros).
     *
     * @return array{pdfMb: int, imageMb: int}
     */
    public static function limits(): array
    {
        return [
            'pdfMb' => intdiv(Binaries::maxPdfKilobytes(), 1024),
            'imageMb' => intdiv(Binaries::maxImageKilobytes(), 1024),
        ];
    }
}
