<?php

namespace App\Services\Tenders;

use App\Support\Binaries;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Lectura de imágenes con Tesseract (idioma `spa`).
 *
 *   $text = app(OcrReader::class)->read($pathToPng);   // RuntimeException si no hay Tesseract
 *   $layout = $ocr->lastLayout();                       // cajas de cada línea leída (o null)
 *
 * Una sola pasada de Tesseract produce el texto (`txt`) y la disposición (`tsv`): cada línea no
 * vacía del texto corresponde, en el mismo orden, a una línea del TSV con su caja en píxeles. La
 * revisión de la captura la usa para resaltar dónde se leyó cada campo.
 */
class OcrReader
{
    /** Límite de espera por imagen, en segundos. */
    public const TIMEOUT = 90;

    /** @var array{lines: array<int, array{text: string, x: int, y: int, w: int, h: int, conf: float|null}>}|null */
    protected ?array $layout = null;

    public function read(string $imagePath, string $psm = '6'): string
    {
        $this->layout = null;
        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'crm-ocr-'.Str::random(16);

        $process = new Process([
            Binaries::tesseract(), $imagePath, $base,
            '-l', (string) config('tools.tesseract.language', 'spa'),
            '--psm', $psm,
            'txt', 'tsv',
        ]);
        $process->setTimeout(self::TIMEOUT);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $this->cleanup($base);

            throw new RuntimeException('El OCR tardó demasiado en leer la imagen.');
        } catch (Throwable) {
            $this->cleanup($base);

            throw new RuntimeException('Tesseract no está instalado o no se pudo ejecutar en el servidor.');
        }

        if (! $process->isSuccessful() || ! is_file("{$base}.txt")) {
            $this->cleanup($base);
            $error = trim($process->getErrorOutput());

            throw new RuntimeException(match (true) {
                str_contains($error, 'spa') && str_contains(strtolower($error), 'failed loading language') => 'Tesseract no tiene instalado el idioma español (spa).',
                $process->getExitCode() === 127, $error === '',
                (bool) preg_match('/not recognized|no se reconoce|not found|no such file/i', $error) => 'Tesseract no está instalado en el servidor.',
                default => 'Tesseract no pudo leer la imagen.',
            });
        }

        $text = (string) file_get_contents("{$base}.txt");

        if (is_file("{$base}.tsv")) {
            $this->layout = ['lines' => self::linesFromTsv((string) file_get_contents("{$base}.tsv"))];
        }

        $this->cleanup($base);

        return $text;
    }

    /**
     * Disposición de la última lectura: una entrada por línea no vacía, en el orden del texto.
     *
     * @return array{lines: array<int, array{text: string, x: int, y: int, w: int, h: int, conf: float|null}>}|null
     */
    public function lastLayout(): ?array
    {
        return $this->layout;
    }

    /**
     * Agrupa las palabras del TSV de Tesseract por línea (bloque, párrafo, línea) con la caja que
     * las contiene y la confianza media.
     *
     * @return array<int, array{text: string, x: int, y: int, w: int, h: int, conf: float|null}>
     */
    public static function linesFromTsv(string $tsv): array
    {
        $lines = [];

        foreach (preg_split('/\R/', $tsv) as $index => $row) {
            $cells = explode("\t", $row);

            if ($index === 0 || count($cells) < 12 || $cells[0] !== '5' || trim($cells[11]) === '') {
                continue;
            }

            [$left, $top, $width, $height, $conf] = [(int) $cells[6], (int) $cells[7], (int) $cells[8], (int) $cells[9], (float) $cells[10]];
            $key = "{$cells[1]}-{$cells[2]}-{$cells[3]}-{$cells[4]}";
            $line = $lines[$key] ?? ['words' => [], 'x1' => PHP_INT_MAX, 'y1' => PHP_INT_MAX, 'x2' => 0, 'y2' => 0, 'conf' => []];

            $line['words'][] = trim($cells[11]);
            $line['x1'] = min($line['x1'], $left);
            $line['y1'] = min($line['y1'], $top);
            $line['x2'] = max($line['x2'], $left + $width);
            $line['y2'] = max($line['y2'], $top + $height);
            if ($conf >= 0) {
                $line['conf'][] = $conf;
            }
            $lines[$key] = $line;
        }

        return array_values(array_map(fn (array $line) => [
            'text' => implode(' ', $line['words']),
            'x' => $line['x1'],
            'y' => $line['y1'],
            'w' => $line['x2'] - $line['x1'],
            'h' => $line['y2'] - $line['y1'],
            'conf' => $line['conf'] ? round(array_sum($line['conf']) / count($line['conf']), 1) : null,
        ], $lines));
    }

    private function cleanup(string $base): void
    {
        foreach (['txt', 'tsv'] as $extension) {
            if (is_file("{$base}.{$extension}")) {
                @unlink("{$base}.{$extension}");
            }
        }
    }
}
