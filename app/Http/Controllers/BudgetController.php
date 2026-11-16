<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\FinanceScreen;
use App\Http\Requests\Finance\BudgetMovementRequest;
use App\Models\BudgetMovement;
use App\Services\AuditLogger;
use App\Services\Finance\FinanceExport;
use App\Services\RevolvingBudgetService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Presupuesto revolvente de la empresa activa: monto inicial, movimientos
 * manuales, sincronización con cotizaciones, licitaciones y cobros, y exportes.
 */
class BudgetController extends Controller
{
    use FinanceScreen;

    private const FILTERS = ['all', 'income', 'expense', 'synced'];

    /** Puntos máximos de la gráfica de saldo. */
    private const CHART_POINTS = 60;

    public function index(Request $request, RevolvingBudgetService $service): Response
    {
        $this->authorize('viewAny', BudgetMovement::class);

        $budget = $service->budget();
        $filters = $this->filters($request);

        return Inertia::render('RevolvingBudget/Index', [
            'budget' => [
                'initial_amount' => (float) $budget->initial_amount,
                'current_balance' => (float) $budget->current_balance,
                'updated_at' => $budget->updated_at?->toDateTimeString(),
            ],
            'records' => $this->filtered($budget->movements()->getQuery(), $filters)
                ->with('user:id,name')
                ->orderByDesc('moved_at')->orderByDesc('id')
                ->paginate($this->perPage($request))->withQueryString()
                ->through(fn (BudgetMovement $movement) => [
                    'id' => $movement->id,
                    'moved_at' => $movement->moved_at?->toDateString(),
                    'type' => $movement->type,
                    'is_expense' => $movement->isExpense(),
                    'concept' => $movement->concept,
                    'reference' => $movement->reference,
                    'amount' => (float) $movement->amount,
                    'balance_after' => $movement->balance_after === null ? null : (float) $movement->balance_after,
                    'synchronized' => $movement->synchronized,
                    'source' => $movement->source_type ? (BudgetMovement::SOURCES[$movement->source_type] ?? null) : null,
                    'user' => $movement->user?->name,
                ]),
            'filters' => $filters,
            'stats' => $this->stats($budget->id),
            'chart' => $this->balanceSeries($budget->id),
            'types' => BudgetMovement::MANUAL_TYPES,
            'can' => $this->abilities($request->user(), BudgetMovement::class),
        ]);
    }

    public function initialize(Request $request, RevolvingBudgetService $service, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('create', BudgetMovement::class);

        $data = $request->validate(
            ['initial_amount' => ['required', 'numeric', 'min:0', 'max:999999999999']],
            [],
            ['initial_amount' => 'monto inicial'],
        );

        $budget = $service->setInitialAmount((float) $data['initial_amount']);
        $audit->record('budget.initialized', $budget, ['initial_amount' => $data['initial_amount']]);

        return back()->with('success', 'Monto inicial actualizado.');
    }

    public function store(BudgetMovementRequest $request, RevolvingBudgetService $service, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('create', BudgetMovement::class);

        $movement = $service->record($request->validated(), $request->user()->id);
        $audit->record('budget.movement_created', $movement, ['type' => $movement->type, 'amount' => $movement->amount]);

        return back()->with('success', 'Movimiento registrado.');
    }

    public function destroy(BudgetMovement $budgetMovement, RevolvingBudgetService $service, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('delete', $budgetMovement);

        if ($budgetMovement->synchronized) {
            return back()->with('error', 'Los movimientos sincronizados se actualizan desde su origen.');
        }

        $service->remove($budgetMovement);
        $audit->record('budget.movement_deleted', $budgetMovement, ['concept' => $budgetMovement->concept, 'amount' => $budgetMovement->amount]);

        return back()->with('success', 'Movimiento eliminado.');
    }

    public function sync(RevolvingBudgetService $service, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('create', BudgetMovement::class);

        $result = $service->sync();
        $audit->record('budget.synced', $result['budget'], ['created' => $result['created'], 'updated' => $result['updated'], 'removed' => $result['removed']]);

        $changes = $result['created'] + $result['updated'] + $result['removed'];

        return back()->with('success', $changes === 0
            ? 'Las fuentes ya estaban sincronizadas.'
            : "Sincronizado: {$result['created']} nuevos, {$result['updated']} actualizados y {$result['removed']} retirados.");
    }

    public function pdf(Request $request, RevolvingBudgetService $service, FinanceExport $export)
    {
        $this->authorize('viewAny', BudgetMovement::class);

        $budget = $service->budget();

        return $export->pdf('pdf.budget', [
            'budget' => $budget,
            'stats' => $this->stats($budget->id),
            'movements' => $this->filtered($budget->movements()->getQuery(), $this->filters($request))->orderBy('moved_at')->orderBy('id')->limit(5000)->get(),
        ], 'Presupuesto revolvente', 'presupuesto-revolvente');
    }

    public function xlsx(Request $request, RevolvingBudgetService $service, FinanceExport $export)
    {
        $this->authorize('viewAny', BudgetMovement::class);

        $budget = $service->budget();
        $rows = $this->filtered($budget->movements()->getQuery(), $this->filters($request))->orderBy('moved_at')->orderBy('id')->limit(5000)->get();

        return $export->xlsx(
            'Presupuesto revolvente',
            ['Fecha', 'Tipo', 'Concepto', 'Referencia', 'Origen', 'Monto (L)', 'Saldo (L)'],
            $rows->map(fn (BudgetMovement $row) => [
                $row->moved_at?->format('d/m/Y'), $row->type, $row->concept, $row->reference,
                $row->synchronized ? 'Sincronizado' : 'Manual',
                ($row->isExpense() ? -1 : 1) * (float) $row->amount,
                $row->balance_after === null ? null : (float) $row->balance_after,
            ]),
            'presupuesto-revolvente',
            moneyColumns: ['F', 'G'],
            subtitle: ['Monto inicial: L '.number_format((float) $budget->initial_amount, 2).' · Saldo actual: L '.number_format((float) $budget->current_balance, 2)],
        );
    }

    /**
     * @return array{filter: string, search: string}
     */
    private function filters(Request $request): array
    {
        $filter = (string) $request->input('filter', 'all');

        return [
            'filter' => in_array($filter, self::FILTERS, true) ? $filter : 'all',
            'search' => mb_substr(trim((string) $request->input('search', '')), 0, 100),
        ];
    }

    /**
     * @param  Builder<BudgetMovement>  $query
     * @param  array{filter: string, search: string}  $filters
     * @return Builder<BudgetMovement>
     */
    private function filtered(Builder $query, array $filters): Builder
    {
        match ($filters['filter']) {
            'income' => $query->whereIn('type', [BudgetMovement::TYPE_INCOME, BudgetMovement::TYPE_PROJECTED]),
            'expense' => $query->where('type', BudgetMovement::TYPE_EXPENSE),
            'synced' => $query->where('synchronized', true),
            default => null,
        };

        if ($filters['search'] !== '') {
            $term = '%'.addcslashes($filters['search'], '%_\\').'%';
            $query->where(fn (Builder $inner) => $inner->where('concept', 'like', $term)->orWhere('reference', 'like', $term));
        }

        return $query;
    }

    /**
     * @return array<string, float|int>
     */
    private function stats(int $budgetId): array
    {
        $row = BudgetMovement::query()->where('revolving_budget_id', $budgetId)->toBase()->selectRaw('
            COUNT(*) as total,
            COALESCE(SUM(CASE WHEN type = ? THEN amount ELSE 0 END), 0) as income,
            COALESCE(SUM(CASE WHEN type = ? THEN amount ELSE 0 END), 0) as expense,
            COALESCE(SUM(CASE WHEN type = ? THEN amount ELSE 0 END), 0) as projected,
            SUM(CASE WHEN synchronized = ? THEN 1 ELSE 0 END) as synced
        ', [BudgetMovement::TYPE_INCOME, BudgetMovement::TYPE_EXPENSE, BudgetMovement::TYPE_PROJECTED, true])->first();

        return [
            'count' => (int) ($row->total ?? 0),
            'income' => (float) ($row->income ?? 0),
            'expense' => (float) ($row->expense ?? 0),
            'projected' => (float) ($row->projected ?? 0),
            'synced' => (int) ($row->synced ?? 0),
        ];
    }

    /**
     * Saldo al cierre de cada día con movimientos (los últimos 60 días con actividad).
     *
     * @return array<int, array{date: string, balance: float}>
     */
    private function balanceSeries(int $budgetId): array
    {
        $rows = BudgetMovement::query()->where('revolving_budget_id', $budgetId)
            ->whereNotNull('balance_after')
            ->orderByDesc('moved_at')->orderByDesc('id')
            ->limit(self::CHART_POINTS * 5)
            ->get(['moved_at', 'balance_after']);

        return $rows->groupBy(fn (BudgetMovement $row) => $row->moved_at->toDateString())
            ->map(fn ($day, $date) => ['date' => $date, 'balance' => (float) $day->first()->balance_after])
            ->take(self::CHART_POINTS)
            ->sortBy('date')->values()->all();
    }
}
