<?php

namespace App\Support;

/**
 * Rutas de los binarios externos (Tesseract y Poppler) según `config/tools.php`.
 *
 * Uso:
 *   Process::run([Binaries::tesseract(), $image, 'stdout', '-l', 'spa']);
 *   Process::run([Binaries::poppler('pdftotext'), '-layout', $pdf, '-']);
 */
final class Binaries
{
    public static function tesseract(): string
    {
        return (string) (config('tools.tesseract.path') ?: 'tesseract');
    }

    /**
     * @param  string  $binary  pdftotext, pdftoppm o pdftocairo
     */
    public static function poppler(string $binary): string
    {
        $directory = trim((string) config('tools.poppler.path'));

        if ($directory === '') {
            return $binary;
        }

        return rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$binary;
    }

    /** Tamaño máximo de PDF en kilobytes, listo para la regla `max:` de validación. */
    public static function maxPdfKilobytes(): int
    {
        return (int) config('tools.uploads.max_pdf_mb', 40) * 1024;
    }

    /** Tamaño máximo de imagen en kilobytes, listo para la regla `max:` de validación. */
    public static function maxImageKilobytes(): int
    {
        return (int) config('tools.uploads.max_image_mb', 10) * 1024;
    }
}
