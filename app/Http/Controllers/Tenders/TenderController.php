<?php

namespace App\Http\Controllers\Tenders;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenders\TenderRequest;
use App\Models\AuditLog;
use App\Models\ComplianceMatrix;
use App\Models\DocumentExtraction;
use App\Models\DocumentLink;
use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\Quote;
use App\Models\Tender;
use App\Models\User;
use App\Services\Ai\BidDocumentAnalyzer;
use App\Services\Ai\OpenAIService;
use App\Services\Finance\FinanceExport;
use App\Services\System\AuditLogger;
use App\Services\Tenancy\CompanyContext;
use App\Services\Tenders\TenderAttributes;
use App\Services\Tenders\TenderFlow;
use App\Services\Tenders\TenderListQuery;
use App\Services\Tenders\TenderOpportunityLinker;
use App\Services\Tenders\TenderStage;
use App\Services\Tenders\TenderText;
use App\Support\Binaries;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Licitaciones: lista (tabla, kanban por etapa y calendario de plazos) con cifras y filtros, ficha
 * con el proceso y el siguiente paso, alta/edición manual, cambio de etapa y exportes. La
 * importación de capturas y el análisis del pliego viven en sus propios controladores.
 */
class TenderController extends Controller
{
    public function __construct(protected TenderListQuery $list) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Tender::class);

        $user = $request->user();
        $filters = $this->list->filters($request);
        $perPage = in_array($request->integer('per_page'), [25, 50, 100], true) ? $request->integer('per_page') : 25;
        $now = CarbonImmutable::now();

        $view = $filters['view'];

        return Inertia::render('Tenders/Index', [
            'tenders' => $view === 'tabla'
                ? $this->list->sorted($this->list->forRows($this->list->filtered($filters, $user)), $filters)
                    ->paginate($perPage)->withQueryString()
                    ->through(fn (Tender $tender) => $this->list->row($tender, $now))
                : null,
            'board' => $view === 'kanban' ? $this->list->board($filters, $user) : null,
            'calendar' => $view === 'calendario' ? $this->list->calendar($filters, $user) : null,
            'stats' => $this->list->stats(),
            'filters' => $filters + ['active' => TenderListQuery::activeCount($filters)],
            'options' => [
                'stages' => TenderStage::options(),
                'statuses' => Tender::STATUSES,
                'modalities' => $this->modalities(),
                'owners' => $this->members(),
                'institution' => $filters['institution_id'] ? Institution::query()->whereKey($filters['institution_id'])->first(['id', 'name', 'acronym']) : null,
            ],
            // Atajo de la PWA (`?accion=importar`) y del Dashboard (`?importar=1`).
            'openImport' => $request->query('accion') === 'importar' || $request->boolean('importar'),
            'statuses' => Tender::STATUSES,
            'modalities' => $this->modalities(),
            'maxImageMb' => (int) (Binaries::maxImageKilobytes() / 1024),
            'can' => [
                'create' => $user->can('create', Tender::class),
                'update' => $user->can('create', Tender::class),
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
        $now = CarbonImmutable::now();
        $stage = TenderStage::of($tender, $now);

        $opportunity = Opportunity::query()->where('tender_id', $tender->id)->oldest('id')
            ->withCount('participants')
            ->first(['id', 'title', 'stage', 'amount', 'probability', 'expected_close_date', 'tender_id']);
        $quotes = Quote::query()->where('tender_id', $tender->id)->latest('id')->limit(20)
            ->get(['id', 'number', 'status', 'outcome', 'total', 'quoted_at']);
        $latestBid = DocumentExtraction::query()->where('tender_id', $tender->id)->where('kind', DocumentExtraction::KIND_BID)->latest('id')
            ->first(['id', 'tender_id', 'kind', 'status', 'extracted', 'evidence', 'created_at']);
        $hasCapture = DocumentExtraction::query()->where('tender_id', $tender->id)->where('kind', DocumentExtraction::KIND_CAPTURE)->exists();

        $can = [
            'update' => $user->can('update', $tender),
            'delete' => $user->can('delete', $tender),
            'useAi' => Gate::allows('use-ai') && $user->can('update', $tender),
            'createQuote' => $user->can('create', Quote::class),
            'createMatrix' => $user->can('create', ComplianceMatrix::class),
            'createOpportunity' => $user->can('create', Opportunity::class),
        ];
        $ai = ['configured' => $openAI->isConfigured(), 'model' => $openAI->model()];

        return Inertia::render('Tenders/Show', [
            'tender' => $this->present($tender, $stage, $now),
            'flow' => TenderFlow::for($tender, $stage, $latestBid, $quotes->count(), $opportunity, (int) ($opportunity?->participants_count ?? 0), $hasCapture, $can, $ai['configured']),
            'opportunity' => $opportunity ? $opportunity->only(['id', 'title', 'stage', 'amount', 'probability']) + ['stage_label' => $opportunity->stageLabel(), 'participants' => (int) $opportunity->participants_count] : null,
            'quotes' => $quotes,
            'analysisState' => $latestBid ? ['id' => $latestBid->id, 'status' => $latestBid->status, 'label' => $latestBid->statusLabel()] : null,
            'capture' => $hasCapture,
            // Diferidas: no retrasan la cabecera ni el stepper.
            'documents' => Inertia::defer(fn () => $this->documents($tender)),
            'analyses' => Inertia::defer(fn () => DocumentExtraction::query()->where('tender_id', $tender->id)->where('kind', DocumentExtraction::KIND_BID)->latest('id')
                ->get(['id', 'tender_id', 'kind', 'status', 'error', 'confidence', 'usage', 'attempts', 'created_at', 'finished_at', 'reviewed_at', 'reviewed_by', 'document_version_id'])
                ->map(fn ($e) => TenderBidDocumentController::summary($e, $tender))->values()->all(), 'detalle'),
            'matrices' => Inertia::defer(fn () => ComplianceMatrix::query()->where('tender_id', $tender->id)
                ->withCount(['items', 'items as completed_count' => fn ($q) => $q->where('completed', true)])->get(['id', 'name'])->all(), 'detalle'),
            'history' => Inertia::defer(fn () => $this->history($tender), 'historial'),
            'statuses' => Tender::STATUSES,
            'modalities' => $this->modalities(),
            'ai' => $ai,
            'maxPdfMb' => (int) (Binaries::maxPdfKilobytes() / 1024),
            'maxPdfPages' => BidDocumentAnalyzer::MAX_PAGES,
            'can' => $can,
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

    /**
     * Mover la licitación de etapa (kanban). El resultado (adjudicada / no adjudicada) se registra
     * en la oportunidad, que también actualiza la cotización: aquí no se permite.
     */
    public function stage(Request $request, Tender $tender, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('update', $tender);

        $data = $request->validate(['stage' => ['required', 'string', 'in:'.implode(',', array_keys(TenderStage::STAGES))]], [
            'stage.in' => 'Elige una etapa válida.',
        ]);
        $to = $data['stage'];

        if (in_array($to, ['adjudicada', 'no_adjudicada'], true)) {
            throw ValidationException::withMessages(['stage' => 'El resultado se registra desde la ficha de la licitación (oportunidad del pipeline), para que la cotización y el pipeline queden iguales.']);
        }

        $result = DB::transaction(function () use ($tender, $to) {
            $locked = Tender::query()->whereKey($tender->id)->lockForUpdate()->firstOrFail();
            $from = TenderStage::of($locked)['key'];

            if ($from === $to) {
                return null;
            }
            if (in_array($from, ['adjudicada', 'no_adjudicada'], true)) {
                throw ValidationException::withMessages(['stage' => 'Esta licitación ya tiene resultado. Para cambiarlo, revierte el resultado en su oportunidad.']);
            }
            if ($to === 'vencida' || ($from === 'vencida' && ! in_array($to, ['presentada', 'desierta'], true))) {
                throw ValidationException::withMessages(['stage' => 'Una licitación vencida solo puede pasar a «Presentada» (si sí se entregó la oferta) o a «Desierta o cancelada». Para reabrirla, corrige la fecha de recepción en «Editar».']);
            }

            $locked->status = TenderStage::STATUS_FOR_STAGE[$to];
            $locked->offer_submitted = $to === 'presentada' ? true : ($to === 'desierta' ? $locked->offer_submitted : false);
            $locked->save();

            return [$from, $to];
        });

        if ($result === null) {
            return back();
        }

        $audit->record('licitacion.etapa', $tender, ['expediente' => $tender->reference, 'de' => $result[0], 'a' => $result[1]]);

        return back()->with('success', "{$tender->reference} pasó a «".TenderStage::STAGES[$to]['label'].'».');
    }

    /** Crea (o reutiliza) la oportunidad del pipeline de la licitación (B-18). */
    public function opportunity(Request $request, Tender $tender, TenderOpportunityLinker $linker): RedirectResponse
    {
        $this->authorize('update', $tender);
        $this->authorize('create', Opportunity::class);

        $result = $linker->ensure($tender, $request->user());

        return redirect("/pipeline/{$result['opportunity']->id}")->with('success', $result['created']
            ? 'Oportunidad creada en el pipeline. Registra aquí los participantes y el resultado.'
            : 'La licitación ya tenía oportunidad: se abrió la existente en lugar de crear otra.');
    }

    public function destroy(Request $request, Tender $tender, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('delete', $tender);

        $reference = $tender->reference;
        $tender->delete();

        $audit->record('licitacion.eliminada', $tender, ['expediente' => $reference]);

        return redirect()->route('tenders.index')->with('success', "Licitación {$reference} eliminada.");
    }

    public function pdf(Request $request, FinanceExport $export)
    {
        $this->authorize('viewAny', Tender::class);

        $filters = $this->list->filters($request);

        return $export->pdf('pdf.tenders-list', [
            'rows' => $this->list->exportRows($filters, $request->user(), 2000),
            'filterLabel' => $this->filterLabel($filters),
            'now' => CarbonImmutable::now(),
        ], 'Licitaciones', 'licitaciones', landscape: true);
    }

    public function xlsx(Request $request, FinanceExport $export)
    {
        $this->authorize('viewAny', Tender::class);

        $filters = $this->list->filters($request);
        $rows = $this->list->exportRows($filters, $request->user(), 5000)->map(fn (array $row) => [
            $row['reference'],
            $row['title'],
            $row['institution']['name'] ?? null,
            $row['purchase_type'],
            $row['stage']['status_label'],
            $row['next_deadline'] ? $row['next_deadline']['label'] : null,
            $row['next_deadline'] ? CarbonImmutable::parse($row['next_deadline']['at'])->format('d/m/Y H:i') : null,
            self::excelDate($row['clarification_deadline']),
            self::excelDate($row['presentation_deadline']),
            self::excelDate($row['opening_at']),
            $row['owner']['name'] ?? null,
            (float) $row['estimated_amount'],
            $row['result'],
        ]);

        return $export->xlsx('Licitaciones', [
            'Expediente', 'Objeto', 'Institución', 'Modalidad', 'Etapa', 'Próximo plazo', 'Fecha del próximo plazo',
            'Cierre de aclaraciones', 'Recepción de ofertas', 'Apertura', 'Responsable', 'Monto estimado (L)', 'Resultado',
        ], $rows, 'licitaciones', ['L'], [$this->filterLabel($filters)]);
    }

    private static function excelDate(?string $value): ?string
    {
        return $value ? CarbonImmutable::parse($value)->format('d/m/Y H:i') : null;
    }

    private function filterLabel(array $filters): string
    {
        $parts = array_filter([
            $filters['search'] !== '' ? "Búsqueda «{$filters['search']}»" : null,
            $filters['stages'] ? 'Etapa: '.implode(', ', array_map(fn ($k) => TenderStage::STAGES[$k]['label'], $filters['stages'])) : null,
            $filters['modality'] ?: null,
            $filters['from'] || $filters['to'] ? 'Recepción '.($filters['from'] ?? '…').' – '.($filters['to'] ?? '…') : null,
            $filters['mine'] ? 'Solo mías' : null,
            ! $filters['expired'] ? 'Sin vencidas' : null,
        ]);

        return $parts ? 'Filtros: '.implode(' · ', $parts) : 'Todas las licitaciones';
    }

    /**
     * @return array<int, string>
     */
    private function modalities(): array
    {
        return once(function () {
            $used = Tender::query()->whereNotNull('purchase_type')->distinct()->orderBy('purchase_type')->limit(50)->pluck('purchase_type')->all();

            return array_values(array_unique([...Tender::MODALITIES, ...$used]));
        });
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    private function members(): array
    {
        $companyId = app(CompanyContext::class)->currentCompanyId();

        return User::query()
            ->whereExists(fn ($members) => $members->from('company_user')
                ->whereColumn('company_user.user_id', 'users.id')
                ->where('company_user.company_id', $companyId))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Tender $tender, array $stage, CarbonImmutable $now): array
    {
        $dates = ['started_at', 'clarification_deadline', 'presentation_deadline', 'opening_at'];
        $data = $tender->only([
            'id', 'reference', 'process_name', 'title', 'institution_id', 'owner_id', 'purchasing_unit', 'purchase_type', 'status',
            'estimated_amount', 'bid_document_fee', 'offer_validity_days', 'funding_source_type', 'funding_source',
            'honducompras_stage', 'acquisition_type', 'offer_reception_place', 'source_url', 'contact_name',
            'contact_phone', 'contact_email', 'requirements', 'delivery_terms', 'payment_terms', 'documents',
            'observations', 'offer_submitted', 'result', 'notes',
        ]);

        foreach ($dates as $field) {
            $data[$field] = $tender->{$field}?->format('Y-m-d\TH:i');
        }

        $data['display_title'] = TenderText::display($tender->title);
        $data['display_unit'] = TenderText::display($tender->purchasing_unit);
        $data['display_contact'] = TenderText::display($tender->contact_name);
        $data['published_at'] = $tender->published_at?->toDateString();
        $data['closing_date'] = $tender->closing_date?->toDateString();
        $data['institution'] = $tender->institution?->only(['id', 'name', 'acronym']);
        $data['owner'] = $tender->owner?->name;
        $data['stage'] = $stage;
        $data['next_deadline'] = in_array($stage['key'], TenderStage::LIVE, true) ? TenderStage::nextDeadline($tender, $now) : null;
        $data['created_at'] = $tender->created_at?->toDateTimeString();
        $data['updated_at'] = $tender->updated_at?->toDateTimeString();

        return $data;
    }

    /**
     * Historial de la licitación (bitácora), lo más reciente arriba.
     *
     * @return array<int, array<string, mixed>>
     */
    private function history(Tender $tender): array
    {
        return AuditLog::query()
            ->where('resource_type', 'Tender')->where('resource_id', $tender->id)
            ->latest('created_at')->latest('id')->limit(40)
            ->get(['id', 'action', 'actor_name', 'channel', 'changes', 'created_at'])
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'title' => self::HISTORY_LABELS[$log->action] ?? ucfirst(str_replace(['licitacion.', '_'], ['', ' '], $log->action)),
                'actor' => $log->actor_name ?? ($log->channel === 'sistema' ? 'Sistema' : null),
                'detail' => $this->historyDetail($log),
                'at' => $log->created_at?->toDateTimeString(),
            ])
            ->all();
    }

    private const HISTORY_LABELS = [
        'licitacion.creada' => 'Licitación creada',
        'licitacion.importada' => 'Importada desde una captura de HonduCompras',
        'licitacion.actualizada' => 'Datos actualizados',
        'licitacion.actualizada_por_captura' => 'Datos completados con una captura',
        'licitacion.etapa' => 'Cambio de etapa',
        'licitacion.vencida' => 'Pasó a «Vencida» (recepción de ofertas sin oferta)',
        'licitacion.recomendacion_ia' => 'Recomendación de la IA consultada',
        'licitacion.pliego_subido' => 'Pliego subido para análisis',
        'licitacion.pliego_reintento' => 'Análisis del pliego reintentado',
        'licitacion.pliego_confirmado' => 'Datos del pliego aplicados',
        'licitacion.matriz_desde_pliego' => 'Matriz documental creada desde el pliego',
    ];

    private function historyDetail(AuditLog $log): ?string
    {
        $changes = $log->changes ?? [];

        return match ($log->action) {
            'licitacion.etapa' => isset($changes['de'], $changes['a'])
                ? (TenderStage::STAGES[$changes['de']]['label'] ?? $changes['de']).' → '.(TenderStage::STAGES[$changes['a']]['label'] ?? $changes['a'])
                : null,
            'licitacion.importada', 'licitacion.actualizada_por_captura' => match ($changes['oportunidad'] ?? null) {
                'creada', true => 'Con oportunidad nueva en el pipeline',
                'reutilizada' => 'Se reutilizó la oportunidad existente',
                default => null,
            },
            default => null,
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function documents(Tender $tender): array
    {
        return DocumentLink::query()
            ->where('linkable_type', Tender::class)->where('linkable_id', $tender->id)
            ->whereHas('document')
            ->with(['document:id,name,version,category,created_at', 'document.currentVersion.uploader:id,name'])
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
                'uploadedBy' => $link->document->currentVersion?->uploader?->name,
                'uploadedAt' => $link->document->created_at?->toDateTimeString(),
                'previewUrl' => "/documentos/{$link->document->id}/vista",
                'downloadUrl' => "/documentos/{$link->document->id}/download",
            ])
            ->all();
    }
}
