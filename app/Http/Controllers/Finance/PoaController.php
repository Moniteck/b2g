<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Finance\Concerns\FinanceScreen;
use App\Http\Requests\Finance\PoaEntryRequest;
use App\Models\Institution;
use App\Models\PoaEntry;
use App\Services\System\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Alertas POA: partidas del Plan Operativo Anual de las instituciones que la
 * empresa sigue, con alerta por fecha estimada cercana o por cierre de año.
 */
class PoaController extends Controller
{
    use FinanceScreen;

    private const FILTERS = ['all', 'alert', 'open', 'closed'];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PoaEntry::class);

        $filters = $this->filters($request);

        return Inertia::render('Poa/Index', [
            'records' => $this->filtered($filters)
                ->with('institution:id,name')
                ->orderByRaw('CASE WHEN estimated_date IS NULL THEN 1 ELSE 0 END')
                ->orderBy('estimated_date')->orderByDesc('id')
                ->paginate($this->perPage($request))->withQueryString()
                ->through(fn (PoaEntry $entry) => [
                    'id' => $entry->id,
                    'institution_id' => $entry->institution_id,
                    'institution' => $entry->institution?->name,
                    'project' => $entry->project,
                    'year' => $entry->year,
                    'budget_line' => $entry->budget_line,
                    'quarter' => $entry->quarter,
                    'probability' => $entry->probability,
                    'budgeted_amount' => (float) $entry->budgeted_amount,
                    'executed_amount' => (float) $entry->executed_amount,
                    'estimated_date' => $entry->estimated_date?->toDateString(),
                    'annual_close_alert' => $entry->annual_close_alert,
                    'status' => $entry->status,
                    'notes' => $entry->notes,
                    'has_alert' => $entry->hasAlert(),
                    'is_closed' => $entry->isClosed(),
                ]),
            'filters' => $filters,
            'stats' => $this->stats($filters['year']),
            'years' => $this->years(),
            'statuses' => PoaEntry::STATUSES,
            'institutions' => Institution::query()->orderBy('name')->get(['id', 'name']),
            'can' => $this->abilities($request->user(), PoaEntry::class),
        ]);
    }

    public function store(PoaEntryRequest $request, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('create', PoaEntry::class);

        $entry = PoaEntry::query()->create($request->payload());
        $audit->record('poa.created', $entry, ['project' => $entry->project]);

        return back()->with('success', 'Partida POA registrada.');
    }

    public function update(PoaEntryRequest $request, PoaEntry $poaEntry, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('update', $poaEntry);

        $poaEntry->update($request->payload());
        $audit->record('poa.updated', $poaEntry, $poaEntry->getChanges());

        return back()->with('success', 'Partida POA actualizada.');
    }

    public function execute(PoaEntry $poaEntry, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('update', $poaEntry);

        $executed = DB::transaction(function () use ($poaEntry) {
            $entry = PoaEntry::query()->lockForUpdate()->findOrFail($poaEntry->id);

            if ($entry->status === PoaEntry::STATUS_EXECUTED) {
                return false;
            }

            $entry->update(['executed_amount' => $entry->budgeted_amount, 'status' => PoaEntry::STATUS_EXECUTED]);

            return true;
        });

        if (! $executed) {
            return back()->with('warning', 'La partida ya estaba ejecutada.');
        }

        $audit->record('poa.executed', $poaEntry);

        return back()->with('success', 'Partida marcada como ejecutada.');
    }

    public function destroy(PoaEntry $poaEntry, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('delete', $poaEntry);

        $poaEntry->delete();
        $audit->record('poa.deleted', $poaEntry, ['project' => $poaEntry->project]);

        return back()->with('success', 'Partida POA eliminada.');
    }

    /**
     * @return array{filter: string, search: string, year: ?int, quarter: ?int}
     */
    private function filters(Request $request): array
    {
        $filter = (string) $request->input('filter', 'all');
        $year = $request->integer('year') ?: null;
        $quarter = $request->integer('quarter') ?: null;

        return [
            'filter' => in_array($filter, self::FILTERS, true) ? $filter : 'all',
            'search' => mb_substr(trim((string) $request->input('search', '')), 0, 100),
            'year' => $year !== null && $year >= 2020 && $year <= 2100 ? $year : null,
            'quarter' => $quarter !== null && $quarter >= 1 && $quarter <= 4 ? $quarter : null,
        ];
    }

    /**
     * @param  array{filter: string, search: string, year: ?int, quarter: ?int}  $filters
     * @return Builder<PoaEntry>
     */
    private function filtered(array $filters): Builder
    {
        $query = PoaEntry::query();

        match ($filters['filter']) {
            'alert' => $query->withAlert(),
            'open' => $query->whereNotIn('status', PoaEntry::CLOSED_STATUSES),
            'closed' => $query->whereIn('status', PoaEntry::CLOSED_STATUSES),
            default => null,
        };

        if ($filters['year'] !== null) {
            $query->where('year', $filters['year']);
        }

        if ($filters['quarter'] !== null) {
            $query->where('quarter', $filters['quarter']);
        }

        if ($filters['search'] !== '') {
            $term = '%'.addcslashes($filters['search'], '%_\\').'%';
            $query->where(fn (Builder $inner) => $inner
                ->where('project', 'like', $term)
                ->orWhere('budget_line', 'like', $term)
                ->orWhereHas('institution', fn (Builder $institution) => $institution->where('name', 'like', $term)));
        }

        return $query;
    }

    /**
     * @return array<string, float|int|null>
     */
    private function stats(?int $year): array
    {
        $base = PoaEntry::query()->when($year, fn (Builder $query) => $query->where('year', $year));
        $closed = "'".implode("','", PoaEntry::CLOSED_STATUSES)."'";

        $row = (clone $base)->toBase()->selectRaw("
            COUNT(*) as total,
            COALESCE(SUM(budgeted_amount), 0) as budgeted,
            COALESCE(SUM(executed_amount), 0) as executed,
            COALESCE(SUM(CASE WHEN status NOT IN ({$closed}) THEN budgeted_amount * probability / 100 ELSE 0 END), 0) as weighted
        ")->first();

        $budgeted = (float) ($row->budgeted ?? 0);
        $executed = (float) ($row->executed ?? 0);

        return [
            'count' => (int) ($row->total ?? 0),
            'budgeted' => $budgeted,
            'executed' => $executed,
            'execution' => $budgeted > 0 ? round($executed / $budgeted * 100, 1) : null,
            'weighted' => (float) ($row->weighted ?? 0),
            'alerts' => (clone $base)->withAlert()->count(),
        ];
    }

    /**
     * @return array<int, int>
     */
    private function years(): array
    {
        return PoaEntry::query()->toBase()->distinct()->orderByDesc('year')->pluck('year')
            ->map(fn ($year) => (int) $year)
            ->push((int) today()->year)->unique()->sortDesc()->values()->all();
    }
}
