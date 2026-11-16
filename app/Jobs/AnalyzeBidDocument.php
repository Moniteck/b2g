<?php

namespace App\Jobs;

use App\Models\DocumentExtraction;
use App\Services\Ai\BidDocumentAnalyzer;
use App\Services\Tenders\BidEvidenceVerifier;
use App\Services\Tenders\PdfTextExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Analiza un pliego en cola: texto por página → IA por tramos → verificación de citas.
 *
 * Estados de la extracción: queued → processing → review | failed. Un fallo deja el motivo
 * en `error` y la pantalla ofrece reintentar (que vuelve a encolar este trabajo).
 * Si el texto ya se extrajo en un intento anterior, no se vuelve a extraer.
 */
class AnalyzeBidDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Un solo intento: el reintento lo decide la persona desde la pantalla. */
    public int $tries = 1;

    /** El análisis de un pliego grande con IA puede tardar varios minutos. */
    public int $timeout = 1200;

    public function __construct(public int $extractionId) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("bid-analysis-{$this->extractionId}"))->dontRelease()->expireAfter($this->timeout)];
    }

    public function handle(PdfTextExtractor $extractor, BidDocumentAnalyzer $analyzer, BidEvidenceVerifier $verifier): void
    {
        $extraction = DocumentExtraction::query()->with('version')->find($this->extractionId);

        if ($extraction === null || ! in_array($extraction->status, [DocumentExtraction::QUEUED, DocumentExtraction::PROCESSING], true)) {
            return;
        }

        $extraction->forceFill([
            'status' => DocumentExtraction::PROCESSING,
            'started_at' => now(),
            'attempts' => $extraction->attempts + 1,
            'error' => null,
        ])->save();

        $startedAt = microtime(true);

        try {
            $pages = $extraction->pages;

            if (! is_array($pages) || $pages === []) {
                $version = $extraction->version ?? throw new RuntimeException('No se encontró el archivo del pliego.');
                $disk = Storage::disk($version->disk);

                if (! $disk->exists($version->path)) {
                    throw new RuntimeException('El archivo del pliego ya no existe en el servidor.');
                }

                $text = $extractor->pages($disk->path($version->path));
                $pages = $text['pages'];
                $extraction->forceFill([
                    'pages' => $pages,
                    'method' => $text['ocr_pages'] === [] ? 'pdftotext' : 'pdftotext+tesseract',
                    'evidence' => ['ocr_pages' => $text['ocr_pages']],
                ])->save();
            }

            $analysis = $analyzer->analyze($pages, $extraction->company_id);
            $verified = $verifier->verify($analysis, $pages);

            $fields = array_filter($verified['fields']);
            $confidence = $fields === [] ? 0 : round(100 * count(array_filter($fields, fn ($f) => $f['status'] === BidEvidenceVerifier::VERIFIED)) / count($fields), 2);

            $extraction->forceFill([
                'status' => DocumentExtraction::REVIEW,
                'extracted' => $verified,
                'confidence' => $confidence,
                'usage' => $analysis['usage'] + [
                    'model' => $analysis['model'],
                    'chunks' => $analysis['chunks'],
                    'seconds' => round(microtime(true) - $startedAt, 1),
                    'pages' => count($pages),
                ],
                'finished_at' => now(),
            ])->save();
        } catch (RuntimeException $exception) {
            $this->markFailed($extraction, $exception->getMessage());
        } catch (Throwable $exception) {
            Log::error('Fallo inesperado al analizar un pliego.', ['extraction' => $extraction->id, 'error' => $exception->getMessage()]);
            $this->markFailed($extraction, 'Ocurrió un fallo inesperado al analizar el pliego. Reintenta; si se repite, avisa a soporte (análisis #'.$extraction->id.').');
        }
    }

    /** Si el trabajo muere por tiempo o por el worker, la pantalla no debe quedar «Procesando» para siempre. */
    public function failed(?Throwable $exception): void
    {
        $extraction = DocumentExtraction::query()->find($this->extractionId);

        if ($extraction !== null && $extraction->isPending()) {
            $this->markFailed($extraction, 'El análisis se interrumpió (tiempo agotado o reinicio del servidor). Reintenta.');
        }
    }

    private function markFailed(DocumentExtraction $extraction, string $message): void
    {
        $extraction->forceFill([
            'status' => DocumentExtraction::FAILED,
            'error' => mb_substr($message, 0, 1000),
            'finished_at' => now(),
        ])->save();
    }
}
