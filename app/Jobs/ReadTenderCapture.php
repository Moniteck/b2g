<?php

namespace App\Jobs;

use App\Services\Tenders\CaptureImportService;
use App\Services\Tenders\OcrReader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lee en cola la captura de HonduCompras (OCR con Tesseract y análisis de la ficha), fuera de
 * la petición web. La pantalla de revisión sondea el estado hasta que el borrador está listo.
 *
 *   ReadTenderCapture::dispatch($token);
 */
class ReadTenderCapture implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Un solo intento: si falla, la revisión se abre para completar los datos a mano. */
    public int $tries = 1;

    public int $timeout = OcrReader::TIMEOUT + 30;

    public function __construct(public string $token) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("tender-capture-{$this->token}"))->dontRelease()->expireAfter($this->timeout)];
    }

    public function handle(CaptureImportService $imports): void
    {
        try {
            $imports->process($this->token);
        } catch (Throwable $exception) {
            Log::error('Fallo inesperado al leer una captura de HonduCompras.', ['token' => $this->token, 'error' => $exception->getMessage()]);
            $imports->markFailed($this->token, 'Ocurrió un fallo inesperado al leer la captura. Completa el formulario a mano.');
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(CaptureImportService::class)->markFailed($this->token, 'La lectura de la captura se interrumpió (tiempo agotado o reinicio del servidor). Completa el formulario a mano.');
    }
}
