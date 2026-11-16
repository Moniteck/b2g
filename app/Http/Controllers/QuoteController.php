<?php

namespace App\Http\Controllers;

use App\Http\Requests\Quotes\QuoteRequest;
use App\Http\Requests\Quotes\QuoteStatusRequest;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\Opportunity;
use App\Models\Quote;
use App\Models\Tender;
use App\Models\User;
use App\Services\Business\QuoteService;
use App\Services\Pricing\TaxRules;
use App\Services\QuoteDocumentService;
use App\Services\Tenancy\CompanyContext;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cotizaciones de la empresa activa: lista, alta, edición, estado documental, PDF y eliminación.
 * El resultado comercial (Ganada, Perdida, revertir) y los participantes van en AdjudicationController.
 */
class QuoteController extends Controller
{
    public const PER_PAGE = 25;

    /** Segmentos de la lista: estado documental o resultado. */
    public const SEGMENTS = ['all', 'preparacion', 'presentadas', 'ganadas', 'perdidas'];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Quote::class);

        $user = $request->user();
        $filters = $this->filters($request);
        $perPage = in_array($request->integer('per_page'), [25, 50, 100], true) ? $request->integer('per_page') : self::PER_PAGE;

        $quotes = $this->filtered($request)
            ->select([
                'id', 'company_id', 'institution_id', 'contact_id', 'opportunity_id', 'tender_id', 'client_id', 'number',
                'quoted_at', 'validity_days', 'purchase_type', 'status', 'outcome', 'subtotal', 'tax', 'total',
                'awarded_amount', 'awarded_at', 'winner_name', 'updated_at',
            ])
            ->with([
                'institution:id,name,acronym',
                'contact:id,name,email',
                'tender:id,reference',
                'opportunity:id,title,stage',
                'client:id,name',
                'contract',
            ])
            ->withCount(['participants', 'documents'])
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Quote $quote) => $this->row($quote, $user));

        return Inertia::render('Quotes/Index', [
            'quotes' => $quotes,
            'stats' => $this->stats(),
            'filters' => $filters,
            'filterOptions' => $this->filterOptions($filters),
            'statuses' => Quote::STATUSES,
            'issuerCompanies' => $this->issuerCompanies($user),
            'taxRules' => TaxRules::forCompany(Company::query()->find(app(CompanyContext::class)->currentCompanyId()))->toArray(),
            'can' => [
                'create' => $user->can('create', Quote::class),
                'export' => $user->can('viewAny', Quote::class),
                'sign' => $user->hasPermission(Permissions::SIGN_DOCUMENTS),
                'viewCost' => $user->can('viewCost', InventoryItem::class),
            ],
        ]);
    }

    /**
     * Ficha completa en JSON: para el formulario de edición, la modal «Ver» y los participantes.
     */
    public function show(Request $request, Quote $quote): JsonResponse
    {
        $this->authorize('view', $quote);

        $quote->load(['items', 'institution:id,name,acronym', 'contact:id,name,institution_id,email,position', 'tender:id,reference,title', 'opportunity:id,title,stage', 'company:id,name', 'client:id,name', 'contract']);

        return response()->json(['data' => $this->detail($quote, $request->user())]);
    }

    public function store(QuoteRequest $request, QuoteService $quotes): RedirectResponse
    {
        $data = $request->validated();
        $companyId = (int) ($data['company_id'] ?? app(CompanyContext::class)->currentCompanyId());

        abort_if($companyId <= 0, 422, 'Seleccione una empresa activa.');

        $quote = $quotes->create($companyId, (int) $request->user()->id, $data);
        $active = app(CompanyContext::class)->currentCompanyId();
        $where = $active !== $companyId ? ' en '.Company::query()->whereKey($companyId)->value('name').' (cambie de empresa para verla)' : '';

        return back()->with('success', "Cotización {$quote->number} creada en Borrador{$where}.");
    }

    public function update(QuoteRequest $request, Quote $quote, QuoteService $quotes): RedirectResponse
    {
        $quotes->update($quote, $request->safe()->except('company_id'));

        return back()->with('success', "Cotización {$quote->number} actualizada.");
    }

    public function destroy(Request $request, Quote $quote, QuoteService $quotes): RedirectResponse
    {
        $this->authorize('delete', $quote);

        $quotes->delete($quote, $request->user());

        return back()->with('success', "Cotización {$quote->number} eliminada.");
    }

    /**
     * Cambio del estado documental. No altera el resultado comercial.
     */
    public function status(QuoteStatusRequest $request, Quote $quote, QuoteService $quotes): RedirectResponse
    {
        $updated = $quotes->changeStatus($quote, $request->validated('status'), $request->user());

        $extra = $updated->status === 'Presentada' ? ' Se registró nuestra oferta entre los participantes.' : '';

        return back()->with('success', "Cotización {$updated->number} en estado {$updated->status}.{$extra}");
    }

    /**
     * PDF generado de la cotización: en línea para el visor o como descarga (`?descargar=1`).
     */
    public function pdf(Request $request, Quote $quote, QuoteDocumentService $documents): HttpResponse
    {
        $this->authorize('view', $quote);

        $disposition = $request->boolean('descargar') ? 'attachment' : 'inline';

        return response($documents->pdf($quote), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "{$disposition}; filename=\"{$quote->number}.pdf\"",
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Copia del PDF generado como adjunto, para firmar y sellar esa copia.
     */
    public function snapshot(Request $request, Quote $quote, QuoteDocumentService $documents): JsonResponse
    {
        $this->authorize('manageAttachments', $quote);
        abort_unless($request->user()->hasPermission(Permissions::SIGN_DOCUMENTS, $quote->company_id), 403, 'No tiene permiso para firmar documentos.');

        $document = $documents->snapshot($quote, $request->user());

        return response()->json(['document' => [
            'id' => $document->id,
            'name' => $document->name,
            'mime' => $document->mime_type,
            'fileName' => $document->original_filename,
            'previewUrl' => route('documents.preview', $document),
            'downloadUrl' => route('documents.download', $document),
            'signed' => $document->signed_at !== null,
        ]], 201);
    }

    /**
     * Consulta filtrada de la lista y de las exportaciones (empresa activa por el scope).
     *
     * @return Builder<Quote>
     */
    public function filtered(Request $request): Builder
    {
        $query = Quote::query()->latest('quoted_at')->latest('id');
        $search = trim((string) $request->query('search', ''));

        if ($search !== '') {
            $query->where(fn (Builder $builder) => $builder
                ->where('number', 'like', "%{$search}%")
                ->orWhere('purchase_type', 'like', "%{$search}%")
                ->orWhereHas('institution', fn (Builder $institution) => $institution->where('name', 'like', "%{$search}%")->orWhere('acronym', 'like', "%{$search}%"))
                ->orWhereHas('tender', fn (Builder $tender) => $tender->where('reference', 'like', "%{$search}%")));
        }

        match ((string) $request->query('segment', 'all')) {
            'preparacion' => $query->whereIn('status', ['Borrador', 'Emitida'])->where('outcome', 'Pendiente'),
            'presentadas' => $query->where('status', 'Presentada')->where('outcome', 'Pendiente'),
            'ganadas' => $query->where('outcome', 'Ganada'),
            'perdidas' => $query->where('outcome', 'Perdida'),
            default => null,
        };

        if (in_array($request->query('status'), Quote::STATUSES, true)) {
            $query->where('status', $request->query('status'));
        }
        if (in_array($request->query('outcome'), Quote::OUTCOMES, true)) {
            $query->where('outcome', $request->query('outcome'));
        }
        if ($request->filled('institution_id')) {
            $query->where('institution_id', $request->integer('institution_id'));
        }
        if ($request->filled('tender_id')) {
            $query->where('tender_id', $request->integer('tender_id'));
        }
        if ($request->filled('opportunity_id')) {
            $query->where('opportunity_id', $request->integer('opportunity_id'));
        }
        if ($from = $this->date($request->query('from'))) {
            $query->whereDate('quoted_at', '>=', $from);
        }
        if ($to = $this->date($request->query('to'))) {
            $query->whereDate('quoted_at', '<=', $to);
        }

        return $query;
    }

    /**
     * Fila de la lista con lo que el usuario puede hacer en ella.
     *
     * @return array<string, mixed>
     */
    protected function row(Quote $quote, User $user): array
    {
        return [
            'id' => $quote->id,
            'number' => $quote->number,
            'quoted_at' => $quote->quoted_at?->toDateString(),
            'valid_until' => $quote->validUntil()?->toDateString(),
            'purchase_type' => $quote->purchase_type,
            'institution' => $quote->institution ? ['id' => $quote->institution->id, 'name' => $quote->institution->name, 'acronym' => $quote->institution->acronym] : null,
            'contact' => $quote->contact?->name,
            'contact_email' => $quote->contact?->email,
            'tender' => $quote->tender ? ['id' => $quote->tender->id, 'reference' => $quote->tender->reference] : null,
            'opportunity' => $quote->opportunity ? ['id' => $quote->opportunity->id, 'title' => $quote->opportunity->title] : null,
            'client' => $quote->client ? ['id' => $quote->client->id, 'name' => $quote->client->name, 'url' => "/clientes/{$quote->client->id}"] : null,
            'contract' => $quote->contract ? ['id' => $quote->contract->id, 'number' => $quote->contract->number, 'url' => '/post-adjudicacion?search='.rawurlencode($quote->contract->number)] : null,
            'subtotal' => (float) $quote->subtotal,
            'tax' => (float) $quote->tax,
            'total' => (float) $quote->total,
            'status' => $quote->status,
            'outcome' => $quote->outcome,
            'awarded_amount' => $quote->awarded_amount !== null ? (float) $quote->awarded_amount : null,
            'awarded_at' => $quote->awarded_at?->toDateString(),
            'winner_name' => $quote->winner_name,
            'participants_count' => (int) $quote->participants_count,
            'documents_count' => (int) $quote->documents_count,
            'next_statuses' => $quote->nextStatuses(),
            'can' => $this->abilities($quote, $user),
        ];
    }

    /**
     * @return array<string, bool>
     */
    protected function abilities(Quote $quote, User $user): array
    {
        $adjudicate = $user->can('adjudicate', $quote);

        return [
            'update' => $user->can('update', $quote),
            'changeStatus' => $user->can('changeStatus', $quote),
            'attach' => $user->can('manageAttachments', $quote),
            'participants' => $user->can('manageParticipants', $quote),
            'win' => $adjudicate && $quote->isAdjudicable(),
            'lose' => $adjudicate && $quote->isAdjudicable(),
            'revert' => ! $quote->isPending() && $user->can('revertOutcome', $quote),
            'send' => $user->can('send', $quote),
            'delete' => $user->can('delete', $quote),
            'sign' => $user->hasPermission(Permissions::SIGN_DOCUMENTS, $quote->company_id) && $user->can('manageAttachments', $quote),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function detail(Quote $quote, User $user): array
    {
        $document = app(QuoteService::class)->document($quote->items);
        // Costo y margen de cada línea: solo para quien puede ver costos (operador y administrador), como en Inventario.
        $canSeeCost = $user->can('viewCost', InventoryItem::class);

        return $this->row($quote->loadCount(['participants', 'documents']), $user) + [
            'company' => $quote->company ? ['id' => $quote->company->id, 'name' => $quote->company->name] : null,
            'company_id' => $quote->company_id,
            'institution_id' => $quote->institution_id,
            'contact_id' => $quote->contact_id,
            'contact_option' => $quote->contact ? ['id' => $quote->contact->id, 'name' => $quote->contact->name, 'institution_id' => $quote->contact->institution_id] : null,
            'tender_id' => $quote->tender_id,
            'tender_option' => $quote->tender ? ['id' => $quote->tender->id, 'name' => $quote->tender->reference, 'description' => $quote->tender->title] : null,
            'opportunity_id' => $quote->opportunity_id,
            'opportunity_option' => $quote->opportunity ? ['id' => $quote->opportunity->id, 'name' => $quote->opportunity->title] : null,
            'validity_days' => $quote->validity_days,
            'tax_included' => (bool) $quote->tax_included,
            'conditions' => $quote->conditions,
            'notes' => $quote->notes,
            'lost_reason' => $quote->lost_reason,
            'pdf_url' => route('quotes.pdf.show', $quote),
            'pdf_download_url' => route('quotes.pdf.show', [$quote, 'descargar' => 1]),
            'taxes' => $document->taxes,
            'can_see_cost' => $canSeeCost,
            'items' => $quote->items->map(fn ($item) => [
                'id' => $item->id,
                'description' => $item->description,
                'quantity' => (float) $item->quantity,
                'unit' => $item->unit,
                'unit_price' => (float) $item->unit_price,
                'discount_percent' => (float) $item->discount_percent,
                'tax_rate' => (float) ($item->tax_rate ?? 0),
                'tax_included' => (bool) $item->tax_included,
                'subtotal' => (float) $item->subtotal,
                'tax' => (float) $item->tax,
                'total' => (float) $item->total,
                ...($canSeeCost ? ['unit_cost' => (float) $item->unit_cost, 'margin' => (float) $item->margin] : []),
                'inventory_item_id' => $item->inventory_item_id !== null ? (int) $item->inventory_item_id : null,
                'sku' => $item->sku,
                'pricing_source' => $item->pricing_source,
            ])->values(),
        ];
    }

    /**
     * Cifras de la cabecera en una sola consulta (empresa activa).
     *
     * @return array<string, mixed>
     */
    protected function stats(): array
    {
        $row = Quote::query()->toBase()->selectRaw(
            "SUM(CASE WHEN status IN ('Borrador', 'Emitida') AND outcome = 'Pendiente' THEN 1 ELSE 0 END) AS preparing,
             SUM(CASE WHEN status = 'Presentada' AND outcome = 'Pendiente' THEN 1 ELSE 0 END) AS presented,
             SUM(CASE WHEN status = 'Presentada' AND outcome = 'Pendiente' THEN total ELSE 0 END) AS presented_amount,
             SUM(CASE WHEN outcome = 'Ganada' THEN 1 ELSE 0 END) AS won,
             SUM(CASE WHEN outcome = 'Ganada' THEN COALESCE(awarded_amount, total) ELSE 0 END) AS won_amount,
             SUM(CASE WHEN outcome = 'Perdida' THEN 1 ELSE 0 END) AS lost,
             COUNT(*) AS total"
        )->first();

        $won = (int) ($row->won ?? 0);
        $lost = (int) ($row->lost ?? 0);

        return [
            'preparing' => (int) ($row->preparing ?? 0),
            'presented' => (int) ($row->presented ?? 0),
            'presented_amount' => (float) ($row->presented_amount ?? 0),
            'won' => $won,
            'won_amount' => (float) ($row->won_amount ?? 0),
            'lost' => $lost,
            'total' => (int) ($row->total ?? 0),
            'rate' => $won + $lost > 0 ? round($won / ($won + $lost) * 100, 1) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function filters(Request $request): array
    {
        $segment = (string) $request->query('segment', 'all');

        return [
            'search' => trim((string) $request->query('search', '')),
            'segment' => in_array($segment, self::SEGMENTS, true) ? $segment : 'all',
            'institution_id' => $request->filled('institution_id') ? $request->integer('institution_id') : null,
            'tender_id' => $request->filled('tender_id') ? $request->integer('tender_id') : null,
            'from' => $this->date($request->query('from')),
            'to' => $this->date($request->query('to')),
        ];
    }

    /**
     * Textos de los filtros elegidos, para los combos con búsqueda remota.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function filterOptions(array $filters): array
    {
        return [
            'institution' => $filters['institution_id'] ? DB::table('institutions')->where('id', $filters['institution_id'])->first(['id', 'name']) : null,
            'tender' => $filters['tender_id'] ? Tender::query()->whereKey($filters['tender_id'])->first(['id', 'reference as name']) : null,
            'tenders' => Tender::query()->latest('id')->limit(200)->get(['id', 'reference', 'title'])
                ->map(fn (Tender $tender) => ['id' => $tender->id, 'name' => $tender->reference, 'description' => $tender->title])->values(),
            'opportunities' => Opportunity::query()->whereIn('stage', Opportunity::ACTIVE_STAGES)->latest('id')->limit(200)->get(['id', 'title', 'institution_id', 'tender_id'])
                ->map(fn (Opportunity $opportunity) => ['id' => $opportunity->id, 'name' => $opportunity->title, 'institution_id' => $opportunity->institution_id, 'tender_id' => $opportunity->tender_id])->values(),
        ];
    }

    /**
     * Empresas en las que el usuario puede crear cotizaciones (empresa emisora).
     *
     * @return array<int, array{id: int, name: string}>
     */
    protected function issuerCompanies(User $user): array
    {
        $context = app(CompanyContext::class);

        return Company::query()->orderBy('name')->get(['id', 'name'])
            ->filter(fn (Company $company) => $context->canAccess($company->id) && $user->hasPermission(Permissions::CREATE, $company->id))
            ->map(fn (Company $company) => ['id' => $company->id, 'name' => $company->name])
            ->values()->all();
    }

    protected function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
