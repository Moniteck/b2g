<?php

namespace App\Services\Tenders;

use App\Support\Binaries;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Lectura de imágenes con Tesseract (idioma `spa`).
 *
 *   $text = app(OcrReader::class)->read($pathToPng);   // RuntimeException si no hay Tesseract
 */
class OcrReader
{
    /** Límite de espera por imagen, en segundos. */
    public const TIMEOUT = 90;

    public function read(string $imagePath, string $psm = '6'): string
    {
        $process = new Process([
            Binaries::tesseract(), $imagePath, 'stdout',
            '-l', (string) config('tools.tesseract.language', 'spa'),
            '--psm', $psm,
        ]);
        $process->setTimeout(self::TIMEOUT);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new RuntimeException('El OCR tardó demasiado en leer la imagen.');
        } catch (Throwable) {
            throw new RuntimeException('Tesseract no está instalado o no se pudo ejecutar en el servidor.');
        }

        if (! $process->isSuccessful()) {
            $error = trim($process->getErrorOutput());

            throw new RuntimeException(match (true) {
                str_contains($error, 'spa') && str_contains(strtolower($error), 'failed loading language') => 'Tesseract no tiene instalado el idioma español (spa).',
                $process->getExitCode() === 127, $error === '',
                (bool) preg_match('/not recognized|no se reconoce|not found|no such file/i', $error) => 'Tesseract no está instalado en el servidor.',
                default => 'Tesseract no pudo leer la imagen.',
            });
        }

        return $process->getOutput();
    }
}
