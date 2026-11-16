<?php

namespace App\Services\Tenders;

use App\Support\Binaries;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Texto de un PDF, página por página.
 *
 * - Primario: `pdftotext -layout` (Poppler) sobre todo el documento; las páginas vienen
 *   separadas por salto de página (\f).
 * - Una página con casi nada de texto (escaneo sin capa de texto) se convierte a imagen con
 *   `pdftoppm` y se lee con Tesseract.
 *
 *   $pages = app(PdfTextExtractor::class)->pages($path);   // ['texto pág. 1', 'texto pág. 2', …]
 */
class PdfTextExtractor
{
    /** Por debajo de estos caracteres útiles la página se considera sin texto. */
    public const MIN_PAGE_CHARS = 40;

    /** Páginas que se extraen como máximo (hallazgo A1): un PDF más largo se rechaza. */
    public const MAX_PAGES = 300;

    public function __construct(protected OcrReader $ocr) {}

    /**
     * @return array{pages: array<int, string>, ocr_pages: array<int, int>}
     */
    public function pages(string $pdfPath): array
    {
        // Se lee una página de más para saber si el PDF supera el tope sin extraerlo entero.
        $output = $this->run([Binaries::poppler('pdftotext'), '-layout', '-enc', 'UTF-8', '-l', (string) (self::MAX_PAGES + 1), $pdfPath, '-'], 'pdftotext');

        $pages = explode("\f", $output);

        // pdftotext deja un salto de página final: la última «página» vacía no existe.
        if (count($pages) > 1 && trim((string) end($pages)) === '') {
            array_pop($pages);
        }

        if (count($pages) > self::MAX_PAGES) {
            throw new RuntimeException('El PDF tiene más de '.self::MAX_PAGES.' páginas: es demasiado extenso para analizarlo. Sube solo la parte del pliego con los datos del proceso o completa los datos a mano.');
        }

        $pages = array_map(fn (string $page) => rtrim($this->clean($page)), $pages);
        $ocrPages = [];

        foreach ($pages as $index => $text) {
            if (mb_strlen((string) preg_replace('/\s+/u', '', $text)) >= self::MIN_PAGE_CHARS) {
                continue;
            }

            $ocrText = $this->ocrPage($pdfPath, $index + 1);

            if ($ocrText !== null && mb_strlen(trim($ocrText)) > mb_strlen(trim($text))) {
                $pages[$index] = rtrim($ocrText);
                $ocrPages[] = $index + 1;
            }
        }

        if (trim(implode('', $pages)) === '') {
            throw new RuntimeException('No se pudo obtener texto del PDF, ni con OCR.');
        }

        return ['pages' => $pages, 'ocr_pages' => $ocrPages];
    }

    private function ocrPage(string $pdfPath, int $page): ?string
    {
        $directory = storage_path('app/private/tmp/ocr-'.Str::uuid());
        File::ensureDirectoryExists($directory);
        $prefix = $directory.DIRECTORY_SEPARATOR.'pagina';

        try {
            $this->run([Binaries::poppler('pdftoppm'), '-f', (string) $page, '-l', (string) $page, '-r', '200', '-gray', '-png', $pdfPath, $prefix], 'pdftoppm');
            $image = collect(File::files($directory))->first(fn ($file) => $file->getExtension() === 'png');

            return $image ? $this->ocr->read($image->getPathname(), '3') : null;
        } catch (RuntimeException) {
            // Sin pdftoppm o sin Tesseract la página queda con lo poco que tenía.
            return null;
        } finally {
            File::deleteDirectory($directory);
        }
    }

    /**
     * @param  array<int, string>  $command
     */
    private function run(array $command, string $name): string
    {
        $process = new Process($command);
        $process->setTimeout((int) config('tools.timeout', 120) * 2);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new RuntimeException("{$name} tardó demasiado en procesar el PDF.");
        } catch (Throwable) {
            throw new RuntimeException("{$name} (Poppler) no está instalado o no se pudo ejecutar en el servidor.");
        }

        // pdftotext avisa errores de flujo comprimido por stderr y aun así extrae el texto:
        // solo se falla si no hubo salida.
        if (! $process->isSuccessful() && trim($process->getOutput()) === '') {
            $error = trim($process->getErrorOutput());

            throw new RuntimeException(preg_match('/not recognized|no se reconoce|not found|no such file/i', $error)
                ? "{$name} (Poppler) no está instalado en el servidor."
                : "{$name} no pudo leer el PDF (¿archivo dañado o cifrado?).");
        }

        return $process->getOutput();
    }

    /** Quita caracteres de control que no aportan (dejando \n). */
    private function clean(string $text): string
    {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        return (string) preg_replace('/[\x00-\x08\x0B\x0E-\x1F\x7F]/u', '', $text);
    }
}
