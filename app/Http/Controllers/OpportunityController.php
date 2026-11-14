<?php

namespace App\Http\Controllers;

use App\Http\Requests\OpportunityRequest;
use App\Http\Requests\OpportunityStageRequest;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\Quote;
use App\Models\QuoteParticipant;
use App\Models\Tender;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Business\AdjudicationService;
use App\Services\Notifications\CalendarSyncService;
use App\Services\Pipeline\OpportunityStageService;
use App\Services\ProcessExportService;
use App\Services\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pipeline de oportunidades (kanban, Fase 8).
 *
 *   GET    /pipeline                 tablero (Inertia `Pipeline/Index`)
 *   GET    /pipeline/{id}            el mismo tablero con el detalle abierto; con Accept JSON, el detalle
 *   POST   /pipeline                 crear (Prospecto u Oportunidad)
 *   PUT    /pipeline/{id}            editar datos (no la etapa)
 *   PATCH  /pipeline/{id}/etapa      mover (OpportunityStageService) → JSON { message, card }
 *   DELETE /pipeline/{id}            eliminar (admin, sin resultado)
 *
 * El resultado (Ganada, Perdida, Revertir) y los participantes van por AdjudicationController
 * (/oportunidades/{id}/...), con la misma lógica que la cotización.
 */
class OpportunityController extends Controller
{
    /** Las cerradas con más de estos días se colapsan tras «Ver más». */
    public const RECENT_CLOSED_DAYS = 90;

    public const STAGE_TONES = [
        'prospecto' => 'neutral',
        'oportunidad' => 'info',
        'oferta_presentada' => 'warning',
        'adjudicado' => 'ok',
        'no_adjudicado' => 'error',
    ];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Opportunity::class);

        return $this->board($request);
    }

    /**
     * `/pipeline/{id}` abre el tablero con el detalle de la oportunidad (enlace de la campana y del
     * calendario). Con Accept JSON devuelve el detalle completo que pinta la modal.
     */
    public function show(Request $request, Opportunity $opportunity, AdjudicationService $adjudication): Response|JsonResponse
    {
        $this->authorize('view', $opportunity);

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json($this->detail($opportunity, $request->user(), $adjudication));
        }

        return $this->board($request, $opportunity->id);
    }

    public function store(OpportunityRequest $request, CalendarSyncService $calendar, AuditLogger $audit): RedirectResponse
    {
        $data = $request->payload();

        $opportunity = DB::transaction(function () use ($data, $request) {
            $opportunity = new Opportunity;
            $opportunity->forceFill(array_merge($data, [
                'company_id' => $request->validated('company_id') ?: app(CompanyContext::class)->currentCompanyId(),
                'owner_id' => $data['owner_id'] ?? $request->user()->id,
                'stage' => $request->validated('stage') ?: 'prospecto',
            ]))->save();

            return $opportunity;
        });

        $calendar->syncForOpportunity($opportunity);
        $audit->record('oportunidad.creada', $opportunity, ['titulo' => $opportunity->title, 'etapa' => $opportunity->stageLabel()]);

        return redirect()->route('pipeline', $request->only(array_keys($this->filters($request))))
            ->with('success', "Oportunidad «{$opportunity->title}» creada en {$opportunity->stageLabel()}.");
    }

    public function update(OpportunityRequest $request, Opportunity $opportunity, CalendarSyncService $calendar, AuditLogger $audit): RedirectResponse
    {
        $opportunity->fill($request->payload());
        $changes = collect($opportunity->getDirty())->except('updated_at')->keys()->all();
        $opportunity->save();

        $calendar->syncForOpportunity($opportunity);

        if ($changes !== []) {
            $audit->record('oportunidad.actualizada', $opportunity, ['campos' => $changes]);
        }

        return back()->with('success', 'Datos de la oportunidad guardados.');
    }

    /**
     * Mover la tarjeta. Errores de transición: 422 con el motivo bajo `stage`; el tablero devuelve la
     * tarjeta a su columna.
     */
    public function stage(OpportunityStageRequest $request, Opportunity $opportunity, OpportunityStageService $stages): JsonResponse
    {
        $result = $stages->move($opportunity, $request->validated('stage'), $request->user());

        $message = $result->alreadyApplied
            ? 'La oportunidad ya estaba en '.$result->opportunity->stageLabel().'.'
            : 'Oportunidad movida a '.$result->opportunity->stageLabel().'.'
                .($result->presentedQuote ? " La cotización {$result->presentedQuote->number} quedó Presentada." : '');

        return response()->json([
            'message' => $message,
            'card' => $this->card($this->cardQuery()->whereKey($opportunity->id)->firstOrFail()),
        ]);
    }

    public function destroy(Request $request, Opportunity $opportunity, AuditLogger $audit): RedirectResponse
    {
        if ($opportunity->hasOutcome()) {
            return back()->with('error', 'No se puede eliminar una oportunidad con resultado. Revierta el resultado primero.');
        }

        $this->authorize('delete', $opportunity);

        DB::transaction(function () use ($opportunity) {
            QuoteParticipant::query()->where('opportunity_id', $opportunity->id)->whereNull('quote_id')->delete();
            $opportunity->delete();
        });

        $audit->record('oportunidad.eliminada', $opportunity, ['titulo' => $opportunity->title, 'etapa' => $opportunity->stageLabel()]);

        return redirect()->route('pipeline')->with('success', "Oportunidad «{$opportunity->title}» eliminada.");
    }

    public function pdf(Request $request, ProcessExportService $exports)
    {
        $this->authorize('viewAny', Opportunity::class);

        $records = $this->exportRows($request)->map(fn (Opportunity $opportunity) => (object) [
            'reference' => 'OP-'.$opportunity->id,
            'title' => $opportunity->title,
            'institution' => $opportunity->institution,
            'estimated_amount' => $opportunity->amount,
            'status' => $opportunity->stageLabel(),
            'closing_date' => $opportunity->expected_close_date,
        ]);

        return $exports->pdf('Pipeline de oportunidades', $records);
    }

    public function xlsx(Request $request, ProcessExportService $exports)
    {
        $this->authorize('viewAny', Opportunity::class);

        $records = $this->exportRows($request)->map(fn (Opportunity $opportunity) => (object) [
            'reference' => 'OP-'.$opportunity->id,
            'title' => $opportunity->title,
            'institution' => $opportunity->institution,
            'purchase_type' => $opportunity->opportunity_type,
            'estimated_amount' => $opportunity->amount,
            'published_at' => null,
            'closing_date' => $opportunity->expected_close_date,
            'status' => $opportunity->stageLabel(),
            'result' => match ($opportunity->stage) {
                'adjudicado' => 'Adjudicado'.($opportunity->awarded_amount ? ' por '.number_format((float) $opportunity->awarded_amount, 2) : ''),
                'no_adjudicado' => trim('Ganó '.$opportunity->winner_name.($opportunity->awarded_amount ? ' con '.number_format((float) $opportunity->awarded_amount, 2) : '')),
                default => null,
            },
        ]);

        return $exports->spreadsheet($records, 'pipeline.xlsx');
    }

    // ───────────────────────────── Tablero ─────────────────────────────

    private function board(Request $request, ?int $focus = null): Response
    {
        $filters = $this->filters($request);
        $showAllClosed = $request->boolean('cerradas');
        $user = $request->user();

        $cards = $this->filtered($filters, $this->cardQuery())
            ->when(! $showAllClosed, fn (Builder $query) => $this->recentOnly($query, $focus))
            ->get()
            ->map(fn (Opportunity $opportunity) => $this->card($opportunity))
            ->values();

        return Inertia::render('Pipeline/Index', [
            'opportunities' => $cards,
            'summary' => $this->summary($filters),
            'stages' => collect(Opportunity::STAGES)->map(fn (string $title, string $id) => ['id' => $id, 'title' => $title, 'tone' => self::STAGE_TONES[$id]])->values(),
            'transitions' => Opportunity::TRANSITIONS,
            'filters' => $filters + ['cerradas' => $showAllClosed],
            'filterOptions' => fn () => [
                'owners' => $this->members(),
                'tenders' => $this->tenderOptions(),
                'institution' => $filters['institution_id'] ? Institution::query()->whereKey($filters['institution_id'])->first(['id', 'name']) : null,
            ],
            'recentDays' => self::RECENT_CLOSED_DAYS,
            'focus' => $focus,
            'can' => [
                'create' => $user->can('create', Opportunity::class),
                'update' => $user->hasPermission(\App\Support\Permissions::UPDATE),
                'adjudicate' => $user->hasPermission(\App\Support\Permissions::ADJUDICATE),
                'revert' => $user->hasPermission(\App\Support\Permissions::REVERT_OUTCOME),
                'delete' => $user->hasPermission(\App\Support\Permissions::DELETE),
            ],
        ]);
    }

    /**
     * @return array{search: string, owner_id: ?int, institution_id: ?int, close_from: ?string, close_to: ?string}
     */
    private function filters(Request $request): array
    {
        $date = fn (string $key) => $request->filled($key) && strtotime((string) $request->input($key)) ? Carbon::parse((string) $request->input($key))->toDateString() : null;

        return [
            'search' => trim((string) $request->input('search', '')),
            'owner_id' => $request->filled('owner_id') ? $request->integer('owner_id') : null,
            'institution_id' => $request->filled('institution_id') ? $request->integer('institution_id') : null,
            'close_from' => $date('close_from'),
            'close_to' => $date('close_to'),
        ];
    }

    private function filtered(array $filters, ?Builder $query = null): Builder
    {
        return ($query ?? Opportunity::query())
            ->search($filters['search'] ?? '')
            ->when($filters['owner_id'] ?? null, fn (Builder $q, $id) => $q->where('opportunities.owner_id', $id))
            ->when($filters['institution_id'] ?? null, fn (Builder $q, $id) => $q->where('opportunities.institution_id', $id))
            ->when($filters['close_from'] ?? null, fn (Builder $q, $date) => $q->whereDate('opportunities.expected_close_date', '>=', $date))
            ->when($filters['close_to'] ?? null, fn (Builder $q, $date) => $q->whereDate('opportunities.expected_close_date', '<=', $date));
    }

    /** Activas siempre; cerradas solo las de los últimos 90 días (la enfocada siempre se incluye). */
    private function recentOnly(Builder $query, ?int $focus): Builder
    {
        $since = Carbon::today()->subDays(self::RECENT_CLOSED_DAYS)->toDateString();

        return $query->where(fn (Builder $q) => $q
            ->whereNotIn('opportunities.stage', Opportunity::CLOSED_STAGES)
            ->orWhereDate('opportunities.awarded_at', '>=', $since)
            ->orWhere(fn (Builder $open) => $open->whereNull('opportunities.awarded_at')->whereDate('opportunities.updated_at', '>=', $since))
            ->when($focus, fn (Builder $f) => $f->orWhere('opportunities.id', $focus)));
    }

    /**
     * Conteo y suma por columna en una sola consulta (incluye las cerradas colapsadas).
     *
     * @return array<string, array{count: int, amount: float}>
     */
    private function summary(array $filters): array
    {
        $rows = $this->filtered($filters)
            ->selectRaw("opportunities.stage, COUNT(*) as total_count, SUM(CASE WHEN opportunities.stage = 'adjudicado' THEN COALESCE(opportunities.awarded_amount, opportunities.amount) ELSE opportunities.amount END) as total_amount")
            ->groupBy('opportunities.stage')
            ->get()
            ->keyBy('stage');

        return collect(array_keys(Opportunity::STAGES))->mapWithKeys(fn (string $stage) => [$stage => [
            'count' => (int) ($rows[$stage]->total_count ?? 0),
            'amount' => round((float) ($rows[$stage]->total_amount ?? 0), 2),
        ]])->all();
    }

    private function cardQuery(): Builder
    {
        return Opportunity::query()
            ->select([
                'opportunities.id', 'opportunities.company_id', 'opportunities.title', 'opportunities.institution_id', 'opportunities.owner_id',
                'opportunities.tender_id', 'opportunities.quote_id', 'opportunities.amount', 'opportunities.probability', 'opportunities.stage',
                'opportunities.expected_close_date', 'opportunities.winner_name', 'opportunities.awarded_amount', 'opportunities.awarded_at',
                'opportunities.next_action', 'opportunities.updated_at',
            ])
            ->with([
                'institution:id,name,acronym',
                'owner:id,name',
                'tender:id,reference,presentation_deadline,closing_date',
                'quote:id,number,status,outcome',
            ])
            ->withCount([
                'participants',
                'quotes' => fn (Builder $query) => $query->whereNotIn('status', Quote::CLOSED_STATUSES),
            ])
            ->orderByRaw('opportunities.expected_close_date IS NULL')
            ->orderBy('opportunities.expected_close_date')
            ->orderBy('opportunities.id');
    }

    /**
     * @return array<string, mixed>
     */
    private function card(Opportunity $opportunity): array
    {
        return [
            'id' => $opportunity->id,
            'code' => 'OP-'.$opportunity->id,
            'title' => $opportunity->title,
            'stage' => $opportunity->stage,
            'amount' => (float) $opportunity->amount,
            'probability' => $opportunity->probability,
            'institution' => $opportunity->institution ? ['id' => $opportunity->institution->id, 'name' => $opportunity->institution->name, 'acronym' => $opportunity->institution->acronym] : null,
            'owner' => $opportunity->owner ? ['id' => $opportunity->owner->id, 'name' => $opportunity->owner->name] : null,
            'tender' => $opportunity->tender ? ['id' => $opportunity->tender->id, 'reference' => $opportunity->tender->reference] : null,
            'quote' => $opportunity->quote ? ['id' => $opportunity->quote->id, 'number' => $opportunity->quote->number, 'status' => $opportunity->quote->status] : null,
            'quotesCount' => (int) ($opportunity->quotes_count ?? 0),
            'participantsCount' => (int) ($opportunity->participants_count ?? 0),
            'keyDate' => $this->keyDate($opportunity),
            'nextAction' => $opportunity->next_action,
            'winner' => $opportunity->stage === 'no_adjudicado' ? ['name' => $opportunity->winner_name, 'amount' => $opportunity->awarded_amount !== null ? (float) $opportunity->awarded_amount : null] : null,
            'awardedAmount' => $opportunity->stage === 'adjudicado' && $opportunity->awarded_amount !== null ? (float) $opportunity->awarded_amount : null,
            'awardedAt' => $opportunity->awarded_at?->toDateString(),
        ];
    }

    /**
     * Fecha clave de la tarjeta: antes de presentar, la presentación de la licitación (si la hay);
     * con resultado, la fecha del resultado; si no, el cierre esperado.
     *
     * @return array{date: string, label: string}|null
     */
    private function keyDate(Opportunity $opportunity): ?array
    {
        if ($opportunity->hasOutcome()) {
            return $opportunity->awarded_at ? ['date' => $opportunity->awarded_at->toDateString(), 'label' => 'Resultado'] : null;
        }

        $presentation = $opportunity->tender?->presentation_deadline ?? $opportunity->tender?->closing_date;

        if (in_array($opportunity->stage, ['prospecto', 'oportunidad'], true) && $presentation !== null) {
            return ['date' => Carbon::parse($presentation)->toDateString(), 'label' => 'Presentación'];
        }

        return $opportunity->expected_close_date ? ['date' => $opportunity->expected_close_date->toDateString(), 'label' => 'Cierre esperado'] : null;
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
     * Licitaciones que aún no tienen oportunidad (decisión 13: una por licitación), las más recientes.
     *
     * @return array<int, array{id: int, name: string, description: string}>
     */
    private function tenderOptions(): array
    {
        return Tender::query()
            ->whereNotExists(fn ($query) => $query->from('opportunities')->whereColumn('opportunities.tender_id', 'tenders.id'))
            ->latest('id')
            ->limit(200)
            ->get(['id', 'reference', 'title'])
            ->map(fn (Tender $tender) => ['id' => $tender->id, 'name' => $tender->reference ?: "Licitación {$tender->id}", 'description' => (string) $tender->title])
            ->all();
    }

    // ───────────────────────────── Detalle ─────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function detail(Opportunity $opportunity, User $user, AdjudicationService $adjudication): array
    {
        $opportunity->load(['contact:id,name,position,email,phone']);
        $card = $this->card($this->cardQuery()->whereKey($opportunity->id)->firstOrFail());
        $summary = $adjudication->participantSummary($adjudication->participantsOf($opportunity));
        $quotes = Quote::query()->where('opportunity_id', $opportunity->id)->orderByDesc('id')
            ->get(['id', 'number', 'status', 'outcome', 'total', 'quoted_at']);
        $contract = Contract::query()->where('opportunity_id', $opportunity->id)
            ->orderByRaw("CASE WHEN status = 'Cancelado' THEN 1 ELSE 0 END")->latest('id')
            ->with('client:id,name')->first(['id', 'number', 'status', 'client_id', 'awarded_amount']);
        $ours = $summary['ours_amount'];
        $winnerAmount = $opportunity->stage === 'no_adjudicado' && $opportunity->awarded_amount !== null ? (float) $opportunity->awarded_amount : null;

        return [
            'data' => $card + [
                'contact' => $opportunity->contact ? $opportunity->contact->only(['id', 'name', 'position', 'email', 'phone']) : null,
                'opportunityType' => $opportunity->opportunity_type,
                'notes' => $opportunity->notes,
                'lostReason' => $opportunity->lost_reason,
                'expectedCloseDate' => $opportunity->expected_close_date?->toDateString(),
                'createdAt' => $opportunity->created_at?->toIso8601String(),
                'stageLabel' => $opportunity->stageLabel(),
            ],
            'form' => [
                'title' => $opportunity->title,
                'institution_id' => $opportunity->institution_id,
                'contact_id' => $opportunity->contact_id,
                'tender_id' => $opportunity->tender_id,
                'owner_id' => $opportunity->owner_id,
                'opportunity_type' => $opportunity->opportunity_type,
                'amount' => (float) $opportunity->amount,
                'probability' => $opportunity->probability,
                'next_action' => $opportunity->next_action,
                'expected_close_date' => $opportunity->expected_close_date?->toDateString(),
                'notes' => $opportunity->notes,
            ],
            'participants' => $summary,
            'quotes' => $quotes->map(fn (Quote $quote) => [
                'id' => $quote->id,
                'number' => $quote->number,
                'status' => $quote->status,
                'outcome' => $quote->outcome,
                'total' => (float) $quote->total,
                'quotedAt' => $quote->quoted_at?->toDateString(),
            ])->values(),
            'outcome' => [
                'result' => match ($opportunity->stage) {
                    'adjudicado' => 'Ganada',
                    'no_adjudicado' => 'Perdida',
                    default => null,
                },
                'awardedAt' => $opportunity->awarded_at?->toDateString(),
                'awardedAmount' => $opportunity->stage === 'adjudicado' && $opportunity->awarded_amount !== null ? (float) $opportunity->awarded_amount : null,
                'winnerName' => $opportunity->stage === 'no_adjudicado' ? $opportunity->winner_name : null,
                'winnerAmount' => $winnerAmount,
                'ourAmount' => $ours,
                'difference' => $winnerAmount !== null && $ours !== null ? round($ours - $winnerAmount, 2) : null,
                'differencePercent' => $winnerAmount !== null && $ours !== null && $winnerAmount > 0 ? round(($ours - $winnerAmount) / $winnerAmount * 100, 2) : null,
                'lostReason' => $opportunity->lost_reason,
                'contract' => $contract ? ['id' => $contract->id, 'number' => $contract->number, 'status' => $contract->status, 'url' => "/post-adjudicacion/{$contract->id}"] : null,
                'client' => $contract?->client ? ['id' => $contract->client->id, 'name' => $contract->client->name, 'url' => "/clientes/{$contract->client->id}"] : null,
                'revertBlocker' => $opportunity->hasOutcome() ? $adjudication->revertBlocker($opportunity) : null,
            ],
            'history' => $this->history($opportunity, $quotes->pluck('id')->all()),
            'can' => [
                'update' => $user->can('update', $opportunity),
                'move' => $user->can('move', $opportunity),
                'adjudicate' => $user->can('adjudicate', $opportunity),
                'revert' => $user->can('revertOutcome', $opportunity),
                'delete' => $user->can('delete', $opportunity),
            ],
        ];
    }

    /**
     * Auditoría de la oportunidad, de sus cotizaciones y de sus adjuntos.
     *
     * @param  array<int, int>  $quoteIds
     * @return array<int, array<string, mixed>>
     */
    private function history(Opportunity $opportunity, array $quoteIds): array
    {
        return AuditLog::query()
            ->where('company_id', $opportunity->company_id)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $own) => $own->where('resource_type', 'Opportunity')->where('resource_id', $opportunity->id))
                ->when($quoteIds !== [], fn (Builder $q) => $q->orWhere(fn (Builder $quotes) => $quotes->where('resource_type', 'Quote')->whereIn('resource_id', $quoteIds)))
                ->orWhere(fn (Builder $docs) => $docs->where('resource_type', 'Document')->where('changes->adjunto_a', "Opportunity #{$opportunity->id}")))
            ->latest('created_at')
            ->latest('id')
            ->limit(100)
            ->get(['id', 'action', 'actor_name', 'changes', 'created_at'])
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'actor' => $log->actor_name,
                'changes' => $log->changes,
                'createdAt' => $log->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /** Filas de los exportes: las mismas que ve el tablero con sus filtros (todas las etapas). */
    private function exportRows(Request $request)
    {
        return $this->filtered($this->filters($request))
            ->with('institution:id,name')
            ->orderByRaw("CASE opportunities.stage WHEN 'prospecto' THEN 1 WHEN 'oportunidad' THEN 2 WHEN 'oferta_presentada' THEN 3 WHEN 'adjudicado' THEN 4 ELSE 5 END")
            ->orderBy('opportunities.expected_close_date')
            ->orderBy('opportunities.id')
            ->get();
    }
}
