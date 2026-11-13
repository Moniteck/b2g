<?php

namespace App\Http\Controllers;

use App\Http\Requests\TenderRequest;
use App\Models\ComplianceMatrix;
use App\Models\DocumentExtraction;
use App\Models\DocumentLink;
use App\Models\Quote;
use App\Models\Tender;
use App\Services\AuditLogger;
use App\Services\OpenAIService;
use App\Services\ProcessExportService;
use App\Services\Tenders\TenderAttributes;
use App\Support\Binaries;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Licitaciones: lista con cifras y filtros, ficha con pestañas, alta/edición manual y exportes.
 * La importación de capturas y el análisis del pliego viven en sus propios controladores.
 */
class TenderController extends Controller
{
    /** Filtros de plazo del segmentado de la lista. */
    private const WINDOWS = ['all', 'open', 'week', 'clarifications'];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Tender::class);

        $filters = $this->filters($request);
        $perPage = in_array($request->integer('per_page'), [25, 50, 100], true) ? $request->integer('per_page') : 25;

        $tenders = $this->filtered($filters)
            ->select(['id', 'institution_id', 'reference', 'title', 'purchase_type', 'status', 'estimated_amount', 'clarification_deadline', 'presentation_deadline', 'opening_at', 'honducompras_stage'])
            ->with('institution:id,name,acronym')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Tender $tender) => [
                'id' => $tender->id,
                'reference' => $tender->reference,
                'title' => $tender->title,
                'institution' => $tender->institution?->only(['id', 'name', 'acronym']),
                'purchase_type' => $tender->purchase_type,
                'status' => $tender->status,
                'estimated_amount' => $tender->estimated_amount,
                'clarification_deadline' => $tender->clarification_deadline?->format('Y-m-d\TH:i:s'),
                'presentation_deadline' => $tender->presentation_deadline?->format('Y-m-d\TH:i:s'),
                'opening_at' => $tender->opening_at?->format('Y-m-d\TH:i:s'),
                'honducompras_stage' => $tender->honducompras_stage,
            ]);

        $user = $request->user();

        return Inertia::render('Tenders/Index', [
            'tenders' => $tenders,
            'stats' => $this->stats(),
            'filters' => $filters,
            'statuses' => Tender::STATUSES,
            'modalities' => $this->modalities(),
            'maxImageMb' => (int) (Binaries::maxImageKilobytes() / 1024),
            'can' => [
                'create' => $user->can('create', Tender::class),
                'delete' => $user->hasPermission('delete'),
                'useAi' => Gate::allows('use-ai'),
            ],
        ]);
    }

    public function show(Request $request, Tender $tender, OpenAIService $openAI): Response
    {
        $this->authorize('view', $tender);

        $tender->load(['institution:id,name,acronym', 'owner:id,name']);
        $user = $request->user();

        $opportunity = $tender->opportunities()->oldest('id')->first(['id', 'title', 'stage', 'amount', 'probability', 'expected_close_date']);
        $quotes = Quote::query()->where('tender_id', $tender->id)->latest('id')->limit(20)
            ->get(['id', 'number', 'status', 'outcome', 'total', 'quoted_at']);
        $extractions = DocumentExtraction::query()->where('tender_id', $tender->id)->latest('id')
            ->get(['id', 'tender_id', 'kind', 'status', 'error', 'confidence', 'usage', 'attempts', 'created_at', 'finished_at', 'reviewed_at', 'reviewed_by', 'document_version_id']);
        $matrices = ComplianceMatrix::query()->where('tender_id', $tender->id)->withCount(['items', 'items as completed_count' => fn ($q) => $q->where('completed', true)])->get(['id', 'name']);

        return Inertia::render('Tenders/Show', [
            'tender' => $this->present($tender),
            'opportunity' => $opportunity ? $opportunity->only(['id', 'title', 'stage', 'amount', 'probability']) + ['stage_label' => $opportunity->stageLabel()] : null,
            'quotes' => $quotes,
            'documents' => Inertia::defer(fn () => $this->documents($tender)),
            'analyses' => $extractions->where('kind', DocumentExtraction::KIND_BID)->values()->map(fn ($e) => TenderBidDocumentController::summary($e, $tender)),
            'capture' => $extractions->firstWhere('kind', DocumentExtraction::KIND_CAPTURE)?->only(['id', 'created_at']),
            'matrices' => $matrices,
            'statuses' => Tender::STATUSES,
            'modalities' => $this->modalities(),
            'ai' => ['configured' => $openAI->isConfigured(), 'model' => $openAI->model()],
            'maxPdfMb' => (int) (Binaries::maxPdfKilobytes() / 1024),
            'can' => [
                'update' => $user->can('update', $tender),
                'delete' => $user->can('delete', $tender),
                'useAi' => Gate::allows('use-ai') && $user->can('update', $tender),
                'createQuote' => $user->can('create', Quote::class),
                'createMatrix' => $user->can('create', ComplianceMatrix::class),
            ],
        ]);
    }

    public function store(TenderRequest $request, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('create', Tender::class);

        $tender = Tender::create(TenderAttributes::fromInput($request->validated()) + [
            'company_id' => $request->validated('company_id') ?: null,
            'owner_id' => $request->user()->id,
        ]);

        $audit->record('licitacion.creada', $tender, ['expediente' => $tender->reference]);

        return redirect()->route('tenders.show', $tender)->with('success', "Licitación {$tender->reference} creada.");
    }

    public function update(TenderRequest $request, Tender $tender, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('update', $tender);

        DB::transaction(function () use ($request, $tender) {
            $locked = Tender::query()->whereKey($tender->id)->lockForUpdate()->firstOrFail();
            $locked->fill(TenderAttributes::fromInput($request->safe()->except('company_id')))->save();
        });

        $audit->record('licitacion.actualizada', $tender, ['expediente' => $request->validated('reference')]);

        return back()->with('success', 'Licitación actualizada.');
    }

    public function destroy(Request $request, Tender $tender, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('delete', $tender);

        $reference = $tender->reference;
        $tender->delete();

        $audit->record('licitacion.eliminada', $tender, ['expediente' => $reference]);

        return redirect()->route('tenders.index')->with('success', "Licitación {$reference} eliminada.");
    }

    public function pdf(Request $request, ProcessExportService $exports)
    {
        $this->authorize('viewAny', Tender::class);

        return $exports->pdf('Licitaciones', $this->filtered($this->filters($request))->with('institution:id,name')->limit(2000)->get());
    }

    public function xlsx(Request $request, ProcessExportService $exports)
    {
        $this->authorize('viewAny', Tender::class);

        return $exports->spreadsheet($this->filtered($this->filters($request))->with('institution:id,name')->limit(5000)->get(), 'licitaciones.xlsx');
    }

    /**
     * @return array{search: string, status: string, modality: string, window: string}
     */
    private function filters(Request $request): array
    {
        $window = $request->string('window')->value();

        return [
            'search' => mb_substr($request->string('search')->trim()->value(), 0, 100),
            'status' => in_array($request->input('status'), Tender::STATUSES, true) ? $request->input('status') : '',
            'modality' => mb_substr($request->string('modality')->trim()->value(), 0, 120),
            'window' => in_array($window, self::WINDOWS, true) ? $window : 'all',
        ];
    }

    /**
     * @param  array{search: string, status: string, modality: string, window: string}  $filters
     * @return Builder<Tender>
     */
    private function filtered(array $filters): Builder
    {
        $now = now();

        return Tender::query()
            ->when($filters['search'] !== '', fn (Builder $q) => $q->where(fn (Builder $x) => $x
                ->where('reference', 'like', "%{$filters['search']}%")
                ->orWhere('title', 'like', "%{$filters['search']}%")
                ->orWhereHas('institution', fn (Builder $i) => $i->where('name', 'like', "%{$filters['search']}%")->orWhere('acronym', 'like', "%{$filters['search']}%"))))
            ->when($filters['status'] !== '', fn (Builder $q) => $q->where('status', $filters['status']))
            ->when($filters['modality'] !== '', fn (Builder $q) => $q->where('purchase_type', $filters['modality']))
            ->when($filters['window'] === 'open', fn (Builder $q) => $this->open($q))
            ->when($filters['window'] === 'week', fn (Builder $q) => $q->whereIn('status', Tender::OPEN_STATUSES)->whereBetween('presentation_deadline', [$now, $now->copy()->addDays(7)]))
            ->when($filters['window'] === 'clarifications', fn (Builder $q) => $q->whereIn('status', Tender::OPEN_STATUSES)->whereBetween('clarification_deadline', [$now, $now->copy()->addDays(7)]))
            // Lo que vence antes, primero; lo que no tiene fecha, al final.
            ->orderByRaw('CASE WHEN presentation_deadline IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('CASE WHEN presentation_deadline < ? THEN 1 ELSE 0 END', [$now])
            ->orderBy('presentation_deadline')
            ->orderByDesc('id');
    }

    private function open(Builder $query): Builder
    {
        return $query->whereIn('status', Tender::OPEN_STATUSES)
            ->where(fn (Builder $q) => $q->whereNull('presentation_deadline')->orWhere('presentation_deadline', '>=', now()));
    }

    /**
     * @return array{open: int, week: int, clarifications: int}
     */
    private function stats(): array
    {
        $now = now();
        $week = $now->copy()->addDays(7);

        $row = Tender::query()->whereIn('status', Tender::OPEN_STATUSES)->selectRaw(
            'SUM(CASE WHEN presentation_deadline IS NULL OR presentation_deadline >= ? THEN 1 ELSE 0 END) AS open_count,
             SUM(CASE WHEN presentation_deadline BETWEEN ? AND ? THEN 1 ELSE 0 END) AS week_count,
             SUM(CASE WHEN clarification_deadline BETWEEN ? AND ? THEN 1 ELSE 0 END) AS clarifications_count',
            [$now, $now, $week, $now, $week],
        )->toBase()->first();

        return [
            'open' => (int) ($row->open_count ?? 0),
            'week' => (int) ($row->week_count ?? 0),
            'clarifications' => (int) ($row->clarifications_count ?? 0),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function modalities(): array
    {
        $used = Tender::query()->whereNotNull('purchase_type')->distinct()->orderBy('purchase_type')->limit(50)->pluck('purchase_type')->all();

        return array_values(array_unique([...Tender::MODALITIES, ...$used]));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Tender $tender): array
    {
        $dates = ['started_at', 'clarification_deadline', 'presentation_deadline', 'opening_at'];
        $data = $tender->only([
            'id', 'reference', 'process_name', 'title', 'institution_id', 'purchasing_unit', 'purchase_type', 'status',
            'estimated_amount', 'bid_document_fee', 'offer_validity_days', 'funding_source_type', 'funding_source',
            'honducompras_stage', 'acquisition_type', 'offer_reception_place', 'source_url', 'contact_name',
            'contact_phone', 'contact_email', 'requirements', 'delivery_terms', 'payment_terms', 'documents',
            'observations', 'offer_submitted', 'result', 'notes',
        ]);

        foreach ($dates as $field) {
            $data[$field] = $tender->{$field}?->format('Y-m-d\TH:i');
        }

        $data['published_at'] = $tender->published_at?->toDateString();
        $data['closing_date'] = $tender->closing_date?->toDateString();
        $data['institution'] = $tender->institution?->only(['id', 'name', 'acronym']);
        $data['owner'] = $tender->owner?->name;
        $data['created_at'] = $tender->created_at?->toDateTimeString();
        $data['updated_at'] = $tender->updated_at?->toDateTimeString();

        return $data;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function documents(Tender $tender): array
    {
        return DocumentLink::query()
            ->where('linkable_type', Tender::class)->where('linkable_id', $tender->id)
            ->whereHas('document')
            ->with(['document:id,name,original_filename,mime_type,size,version,category,created_at,uploaded_by', 'document.uploader:id,name'])
            ->latest('id')
            ->get()
            ->map(fn (DocumentLink $link) => [
                'id' => $link->document->id,
                'name' => $link->document->name,
                'fileName' => $link->document->original_filename,
                'mime' => $link->document->mime_type,
                'size' => $link->document->size,
                'context' => $link->context,
                'category' => $link->document->category,
                'version' => $link->document->version,
                'uploadedBy' => $link->document->uploader?->name,
                'uploadedAt' => $link->document->created_at?->toDateTimeString(),
                'previewUrl' => "/documentos/{$link->document->id}/vista",
                'downloadUrl' => "/documentos/{$link->document->id}/download",
            ])
            ->all();
    }
}
