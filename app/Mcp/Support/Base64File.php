<?php

namespace App\Mcp\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Archivo recibido por MCP en base64 → `UploadedFile` temporal para los servicios de dominio
 * (`DocumentService`, `CaptureImportService`, `BidReviewService`), que vuelven a revisar el tipo
 * real, el tamaño y que el PDF se pueda abrir.
 *
 * El tope se verifica sobre el texto ANTES de decodificar (nunca se decodifica algo mayor) y el
 * temporal se borra al terminar la petición.
 */
final class Base64File
{
    public static function maxKilobytes(): int
    {
        return (int) config('mcp.crm.upload_max_kilobytes', 10 * 1024);
    }

    public static function decode(mixed $content, mixed $filename, ?int $maxKilobytes = null): UploadedFile
    {
        $maxBytes = ($maxKilobytes ?? self::maxKilobytes()) * 1024;

        if (! is_string($content) || $content === '') {
            throw new ToolError('Falta el contenido del archivo en base64 (`content_base64`).');
        }

        // Tope sobre el texto: 4 caracteres por cada 3 bytes, más margen para saltos de línea y prefijo «data:».
        if (strlen($content) > (int) ceil($maxBytes / 3) * 4 + 4096) {
            throw new ToolError('El archivo supera el máximo de '.self::megabytes($maxBytes).' MB que se acepta por MCP. Súbelo desde el CRM web.');
        }

        if (str_starts_with($content, 'data:') && ($comma = strpos($content, ',')) !== false) {
            $content = substr($content, $comma + 1);
        }

        $binary = base64_decode(preg_replace('/\s+/', '', $content) ?? '', true);

        if ($binary === false || $binary === '') {
            throw new ToolError('El contenido no es base64 válido.');
        }

        if (strlen($binary) > $maxBytes) {
            throw new ToolError('El archivo supera el máximo de '.self::megabytes($maxBytes).' MB que se acepta por MCP. Súbelo desde el CRM web.');
        }

        $name = self::cleanName($filename);
        $directory = rtrim(sys_get_temp_dir(), '\\/').DIRECTORY_SEPARATOR.'crm-mcp-uploads';

        if (! is_dir($directory) && ! @mkdir($directory, 0770, true) && ! is_dir($directory)) {
            throw new ToolError('No se pudo preparar el archivo en el servidor.');
        }

        $path = $directory.DIRECTORY_SEPARATOR.Str::uuid().'.'.(pathinfo($name, PATHINFO_EXTENSION) ?: 'bin');

        if (file_put_contents($path, $binary) === false) {
            throw new ToolError('No se pudo preparar el archivo en el servidor.');
        }

        app()->terminating(fn () => @unlink($path));

        return new UploadedFile($path, $name, null, null, true);
    }

    public static function cleanName(mixed $filename): string
    {
        $name = is_string($filename) ? basename(str_replace('\\', '/', $filename)) : '';
        $name = preg_replace('/[\x00-\x1F\x7F<>:"|?*]/u', '', $name) ?? '';
        $name = trim(mb_substr($name, -150));

        if ($name === '' || ! str_contains($name, '.')) {
            throw new ToolError('Indica el nombre del archivo con su extensión (`file_name`, p. ej. «pliego.pdf»).');
        }

        return $name;
    }

    private static function megabytes(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1048576, 1, '.', ''), '0'), '.');
    }
}
