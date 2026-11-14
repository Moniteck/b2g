<?php

namespace App\Http\Controllers;

use App\Http\Requests\Tenders\BidReviewRequest;
use App\Models\ComplianceMatrix;
use App\Models\DocumentExtraction;
use App\Models\Quote;
use App\Models\Tender;
use App\Services\Ai\BidDocumentAnalyzer;
use App\Services\Documents\FileTypes;
use App\Services\Documents\UploadInspector;
use App\Services\OpenAIService;
use App\Services\Tenders\BidReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pliego de condiciones de una licitación: subir y analizar con IA (en cola), consultar el
 * estado, reintentar, revisar con evidencia y confirmar; y desde la revisión, crear la
 * matriz documental o una cotización borrador.
 */
class TenderBidDocumentController extends Controller
{
    public function __construct(protected BidReviewService $reviews) {}

    public function store(Request $request, Tender $tender, OpenAIService $openAI, UploadInspector $inspector): RedirectResponse
    {
        $this->authorize('update', $tender);
        $this->authorize('use-ai');

        // Tipo real, tamaño, PDF legible y límites de PHP, con los mismos mensajes que Documentos.
        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['file' => 'Elige el PDF del pliego.']);
        }
        $inspector->inspect($file, 'file', FileTypes::PDF_ONLY);

        if (! $openAI->isConfigured()) {
            return back()->with('error', 'El análisis con IA no está configurado (falta OPENAI_API_KEY). Sube el pliego como documento y completa los datos a mano.');
        }

        $this->reviews->start($tender, $request->file('file'), $request->user());

        return redirect()->route('tenders.show', ['tender' => $tender, 'tab' => 'analisis'])
            ->with('success', 'Pliego recibido. El análisis está en cola; esta pantalla se actualizará sola.');
    }

    public function status(Tender $tender, DocumentExtraction $extraction): JsonResponse
    {
        $this->authorize('view', $tender);
        $this->ensureBelongs($tender, $extraction);

        return response()->json(self::summary($extraction, $tender));
    }

    public function retry(Request $request, Tender $tender, DocumentExtraction $extraction): RedirectResponse
    {
        $this->authorize('update', $tender);
        $this->authorize('use-ai');
        $this->ensureBelongs($tender, $extraction);

        $this->reviews->retry($extraction, $request->user());

        return back()->with('success', 'Análisis en cola de nuevo.');
    }

    public function review(Request $request, Tender $tender, DocumentExtraction $extraction): Response|RedirectResponse
    {
        $this->authorize('view', $tender);
        $this->ensureBelongs($tender, $extraction);

        if (! in_array($extraction->status, [DocumentExtraction::REVIEW, DocumentExtraction::CONFIRMED], true)) {
            return redirect()->route('tenders.show', ['tender' => $tender, 'tab' => 'analisis'])
                ->with('warning', 'El análisis aún no está listo para revisar.');
        }

        $tender->loadMissing('institution:id,name');
        $user = $request->user();

        return Inertia::render('Tenders/BidReview', [
            'tender' => $tender->only(['id', 'reference', 'title']) + ['institution' => $tender->institution?->name],
            'analysis' => self::summary($extraction, $tender),
            'rows' => $this->reviews->proposal($extraction, $tender),
            'lists' => $extraction->extracted['lists'] ?? array_fill_keys(array_keys(BidDocumentAnalyzer::LISTS), []),
            'applied' => $extraction->extracted['applied'] ?? [],
            'pdfUrl' => route('tenders.bid.file', [$tender, $extraction]),
            'matrixId' => $extraction->evidence['matrix_id'] ?? null,
            'quoteId' => $extraction->evidence['quote_id'] ?? null,
            'can' => [
                'confirm' => $user->can('update', $tender) && $extraction->status === DocumentExtraction::REVIEW,
                'createMatrix' => $user->can('create', ComplianceMatrix::class),
                'createQuote' => $user->can('create', Quote::class),
            ],
        ]);
    }

    public function file(Tender $tender, DocumentExtraction $extraction): StreamedResponse
    {
        $this->authorize('view', $tender);
        $this->ensureBelongs($tender, $extraction);

        $version = $extraction->version ?? abort(404);
        $disk = Storage::disk($version->disk);
        abort_unless($disk->exists($version->path), 404);

        return $disk->response($version->path, $version->original_filename ?? 'pliego.pdf', [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    public function confirm(BidReviewRequest $request, Tender $tender, DocumentExtraction $extraction): RedirectResponse
    {
        $this->authorize('update', $tender);
        $this->ensureBelongs($tender, $extraction);

        $done = $this->reviews->confirm($extraction, $tender, $request->validated('fields', []), $request->validated('lists', []), $request->user());

        return redirect()->route('tenders.show', ['tender' => $tender, 'tab' => 'fechas'])
            ->with($done ? 'success' : 'warning', $done ? 'Datos del pliego aplicados a la licitación.' : 'Este análisis ya se había confirmado.');
    }

    public function matrix(Request $request, Tender $tender, DocumentExtraction $extraction): RedirectResponse
    {
        $this->authorize('view', $tender);
        $this->authorize('create', ComplianceMatrix::class);
        $this->ensureBelongs($tender, $extraction);

        $matrix = $this->reviews->createMatrix($extraction, $tender, $request->user());

        return back()->with('success', "Matriz documental lista con {$matrix->items()->count()} requisitos.");
    }

    public function quote(Request $request, Tender $tender, DocumentExtraction $extraction): RedirectResponse
    {
        $this->authorize('view', $tender);
        $this->authorize('create', Quote::class);
        $this->ensureBelongs($tender, $extraction);

        $data = $request->validate(['items' => ['nullable', 'array'], 'items.*' => ['integer', 'min:0']]);
        $quote = $this->reviews->createQuote($extraction, $tender, $request->user(), $data['items'] ?? null);

        return back()->with('success', "Cotización borrador {$quote->number} creada con los productos del pliego.");
    }

    /**
     * Estado del análisis para la ficha y el sondeo.
     *
     * @return array<string, mixed>
     */
    public static function summary(DocumentExtraction $extraction, Tender $tender): array
    {
        $usage = $extraction->usage ?? [];

        return [
            'id' => $extraction->id,
            'status' => $extraction->status,
            'statusLabel' => $extraction->statusLabel(),
            'error' => $extraction->error,
            'attempts' => $extraction->attempts,
            'confidence' => $extraction->confidence !== null ? (float) $extraction->confidence : null,
            'tokens' => $usage['total_tokens'] ?? null,
            'seconds' => $usage['seconds'] ?? null,
            'pages' => $usage['pages'] ?? null,
            'createdAt' => $extraction->created_at?->toDateTimeString(),
            'finishedAt' => $extraction->finished_at?->toDateTimeString(),
            'reviewedAt' => $extraction->reviewed_at?->toDateTimeString(),
            'pending' => $extraction->isPending(),
            'statusUrl' => route('tenders.bid.status', [$tender, $extraction]),
            'reviewUrl' => route('tenders.bid.review', [$tender, $extraction]),
            'retryUrl' => route('tenders.bid.retry', [$tender, $extraction]),
        ];
    }

    private function ensureBelongs(Tender $tender, DocumentExtraction $extraction): void
    {
        abort_unless((int) $extraction->tender_id === (int) $tender->id && $extraction->kind === DocumentExtraction::KIND_BID, 404);
    }
}
