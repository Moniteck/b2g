<?php

namespace App\Services\Documents;

use App\Support\Binaries;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use Throwable;
use ZipArchive;

/**
 * Revisa un archivo subido antes de guardarlo: que llegó completo, que su tipo REAL (por
 * contenido) es uno de los permitidos y coincide con la extensión, que no supera el límite y,
 * si es PDF, que se puede abrir (ni truncado, ni dañado, ni cifrado).
 *
 * Todo rechazo es un `ValidationException` (422) con el mensaje bajo el campo indicado.
 */
class UploadInspector
{
    /** Bytes que se leen al inicio y al final de un PDF para las comprobaciones rápidas. */
    private const PDF_PROBE_BYTES = 65536;

    /**
     * @param  array<int, string>  $allowed  extensiones permitidas (FileTypes::ALL por defecto)
     * @return array{extension: string, group: string, mime: string, size: int, hash: string, name: string}
     *
     * @throws ValidationException
     */
    public function inspect(UploadedFile $file, string $field = 'file', array $allowed = FileTypes::ALL): array
    {
        $name = $this->cleanName($file->getClientOriginalName());

        if (! $file->isValid()) {
            $this->fail($field, $this->uploadErrorMessage($file, $name));
        }

        $size = (int) $file->getSize();

        if ($size <= 0) {
            $this->fail($field, "«{$name}» está vacío.");
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (! in_array($extension, $allowed, true) || FileTypes::groupOf($extension) === null) {
            $this->fail($field, "«{$name}» no es un tipo permitido. Se aceptan: ".FileTypes::label($allowed).'.');
        }

        $path = (string) $file->getRealPath();
        $detected = $this->detectMime($path);

        if (! $this->contentMatches($extension, $detected, $path)) {
            $this->fail($field, "El contenido de «{$name}» no corresponde a un archivo ".strtoupper($extension).'. Ábrelo y vuelve a guardarlo, o sube otro.');
        }

        $maxKilobytes = FileTypes::maxKilobytesFor($extension);

        if ($size > $maxKilobytes * 1024) {
            $this->fail($field, "«{$name}» pesa ".$this->megabytes($size).' MB; el máximo es '.intdiv($maxKilobytes, 1024).' MB.');
        }

        if ($extension === 'pdf') {
            $this->assertReadablePdf($path, $name, $field);
        }

        return [
            'extension' => $extension,
            'group' => (string) FileTypes::groupOf($extension),
            'mime' => FileTypes::CANONICAL_MIMES[$extension],
            'size' => $size,
            'hash' => (string) hash_file('sha256', $path),
            'name' => $name,
        ];
    }

    /**
     * El PDF se puede abrir: cabecera, final de archivo, sin cifrado y, con Poppler, `pdfinfo`
     * lo lee y tiene páginas.
     */
    public function assertReadablePdf(string $path, string $name, string $field = 'file'): void
    {
        $size = (int) filesize($path);
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            $this->fail($field, "No se pudo leer «{$name}». Vuelve a subirlo.");
        }

        $head = (string) fread($handle, self::PDF_PROBE_BYTES);
        fseek($handle, max(0, $size - self::PDF_PROBE_BYTES));
        $tail = (string) fread($handle, self::PDF_PROBE_BYTES);
        fclose($handle);

        if (! str_contains(substr($head, 0, 1024), '%PDF-')) {
            $this->fail($field, "«{$name}» no es un PDF válido.");
        }

        if (! str_contains(substr($tail, -2048), '%%EOF')) {
            $this->fail($field, "«{$name}» está incompleto o dañado (el archivo se cortó). Vuelve a descargarlo o a exportarlo y súbelo de nuevo.");
        }

        if (preg_match('/\/Encrypt\s/', $head.$tail) === 1) {
            $this->fail($field, "«{$name}» está protegido con contraseña o cifrado. Guárdalo sin protección y vuelve a subirlo.");
        }

        $info = $this->pdfinfo($path);

        if ($info === null) {
            return; // Sin Poppler: bastan las comprobaciones anteriores.
        }

        if ($info['encrypted']) {
            $this->fail($field, "«{$name}» está protegido con contraseña o cifrado. Guárdalo sin protección y vuelve a subirlo.");
        }

        if (! $info['ok'] || $info['pages'] < 1) {
            $this->fail($field, "«{$name}» está dañado y no se puede abrir. Vuelve a exportarlo y súbelo de nuevo.");
        }
    }

    /**
     * Resultado de `pdfinfo`, o null si Poppler no está disponible.
     *
     * @return array{ok: bool, encrypted: bool, pages: int}|null
     */
    protected function pdfinfo(string $path): ?array
    {
        try {
            $result = Process::timeout(30)->run([Binaries::poppler('pdfinfo'), $path]);
        } catch (Throwable) {
            return null;
        }

        $output = $result->output();
        $errors = strtolower($result->errorOutput());

        // Si falla sin un mensaje propio de Poppler, el binario no está (127, «no se reconoce»…).
        if (! $result->successful() && preg_match('/(syntax|internal|i\/o|command line)\s+(error|warning)|password/', $errors) !== 1) {
            return null;
        }

        preg_match('/^Pages:\s+(\d+)/mi', $output, $pages);

        return [
            'ok' => $result->successful(),
            'encrypted' => preg_match('/^Encrypted:\s+yes/mi', $output) === 1 || str_contains($errors, 'password'),
            'pages' => (int) ($pages[1] ?? 0),
        ];
    }

    protected function detectMime(string $path): string
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);

        return is_string($mime) ? $mime : 'application/octet-stream';
    }

    protected function contentMatches(string $extension, string $mime, string $path): bool
    {
        if (! in_array($mime, FileTypes::EXTENSIONS[$extension]['mimes'], true)) {
            return false;
        }

        // Los Office modernos son ZIP: se comprueba que lleven la carpeta propia de Word o Excel.
        if (in_array($extension, ['docx', 'xlsx'], true) && ! str_starts_with($mime, 'application/vnd.openxmlformats')) {
            return $this->zipContains($path, $extension === 'docx' ? 'word/' : 'xl/');
        }

        return true;
    }

    protected function zipContains(string $path, string $prefix): bool
    {
        if (! class_exists(ZipArchive::class)) {
            return false;
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return false;
        }

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                if (str_starts_with((string) $zip->getNameIndex($index), $prefix)) {
                    return true;
                }
            }

            return false;
        } finally {
            $zip->close();
        }
    }

    protected function uploadErrorMessage(UploadedFile $file, string $name): string
    {
        return match ($file->getError()) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => "«{$name}» supera el tamaño que acepta el servidor (".ini_get('upload_max_filesize').'). Pide al administrador que suba el límite de PHP.',
            UPLOAD_ERR_PARTIAL => "«{$name}» llegó incompleto. Vuelve a subirlo.",
            UPLOAD_ERR_NO_FILE => 'Elige un archivo.',
            default => "No se pudo recibir «{$name}». Vuelve a intentarlo.",
        };
    }

    /** Nombre seguro para guardar y mostrar: sin rutas ni caracteres de control. */
    public function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F"]+/u', '', $name);
        $name = trim($name);

        return mb_substr($name === '' ? 'archivo' : $name, 0, 200);
    }

    protected function megabytes(int $bytes): string
    {
        return number_format($bytes / 1048576, 1);
    }

    /**
     * @throws ValidationException
     */
    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
