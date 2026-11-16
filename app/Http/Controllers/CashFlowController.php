<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\FinanceScreen;
use App\Http\Requests\Finance\CashIncomeRequest;
use App\Models\CollectionInvoice;
use App\Models\ManualCashIncome;
use App\Services\AuditLogger;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Flujo de caja de la empresa activa: lo cobrado, los ingresos manuales y la
 * proyección de lo que falta cobrar según el vencimiento de cada factura.
 */
class CashFlowController extends Controller
{
    use FinanceScreen;

    /** Meses hacia atrás y hacia adelante de la gráfica (más el mes actual). */
    private const MONTHS_BACK = 5;

    private const MONTHS_AHEAD = 6;

    private const PENDING_LIST = 8;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ManualCashIncome::class);

        $search = mb_substr(trim((string) $request->input('search', '')), 0, 100);
        $incomes = ManualCashIncome::query()->with('user:id,name');

        if ($search !== '') {
            $term = '%'.addcslashes($search, '%_\\').'%';
            $incomes->where(fn (Builder $inner) => $inner->where('concept', 'like', $term)->orWhere('notes', 'like', $term));
        }

        return Inertia::render('CashFlow/Index', [
            'records' => $incomes->orderByDesc('received_at')->orderByDesc('id')
                ->paginate($this->perPage($request))->withQueryString()
                ->through(fn (ManualCashIncome $income) => [
                    'id' => $income->id,
                    'concept' => $income->concept,
                    'amount' => (float) $income->amount,
                    'received_at' => $income->received_at?->toDateString(),
                    'notes' => $income->notes,
                    'user' => $income->user?->name,
                ]),
            'filters' => ['search' => $search],
            'totals' => $this->totals(),
            'chart' => $this->monthly(),
            'pendingInvoices' => CollectionInvoice::query()->unpaid()
                ->orderBy('due_at')->limit(self::PENDING_LIST)
                ->get(['id', 'number', 'concept', 'amount', 'due_at', 'status'])
                ->map(fn (CollectionInvoice $invoice) => [
                    'id' => $invoice->id,
                    'number' => $invoice->number,
                    'concept' => $invoice->concept,
                    'amount' => (float) $invoice->amount,
                    'due_at' => $invoice->due_at?->toDateString(),
                    'display_status' => $invoice->displayStatus(),
                ]),
            'can' => $this->abilities($request->user(), ManualCashIncome::class),
        ]);
    }

    public function store(CashIncomeRequest $request, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('create', ManualCashIncome::class);

        $income = ManualCashIncome::query()->create($request->validated() + ['user_id' => $request->user()->id]);
        $audit->record('cash_income.created', $income, ['amount' => $income->amount]);

        return back()->with('success', 'Ingreso registrado.');
    }

    public function update(CashIncomeRequest $request, ManualCashIncome $cashIncome, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('update', $cashIncome);

        $cashIncome->update($request->validated());
        $audit->record('cash_income.updated', $cashIncome, $cashIncome->getChanges());

        return back()->with('success', 'Ingreso actualizado.');
    }

    public function destroy(ManualCashIncome $cashIncome, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('delete', $cashIncome);

        $cashIncome->delete();
        $audit->record('cash_income.deleted', $cashIncome, ['concept' => $cashIncome->concept, 'amount' => $cashIncome->amount]);

        return back()->with('success', 'Ingreso eliminado.');
    }

    /**
     * @return array<string, float|int>
     */
    private function totals(): array
    {
        $today = today()->toDateString();
        $monthStart = today()->startOfMonth()->toDateString();
        $paid = CollectionInvoice::STATUS_PAID;

        $invoices = CollectionInvoice::query()->toBase()->selectRaw('
            COALESCE(SUM(CASE WHEN status = ? THEN amount ELSE 0 END), 0) as received,
            COALESCE(SUM(CASE WHEN status = ? AND paid_at >= ? THEN amount ELSE 0 END), 0) as received_month,
            COALESCE(SUM(CASE WHEN status <> ? THEN amount ELSE 0 END), 0) as pending,
            SUM(CASE WHEN status <> ? THEN 1 ELSE 0 END) as pending_count,
            COALESCE(SUM(CASE WHEN status <> ? AND due_at < ? THEN amount ELSE 0 END), 0) as overdue
        ', [$paid, $paid, $monthStart, $paid, $paid, $paid, $today])->first();

        $manual = ManualCashIncome::query()->toBase()->selectRaw('
            COALESCE(SUM(amount), 0) as total,
            COALESCE(SUM(CASE WHEN received_at >= ? THEN amount ELSE 0 END), 0) as month
        ', [$monthStart])->first();

        return [
            'manual' => (float) ($manual->total ?? 0),
            'manual_month' => (float) ($manual->month ?? 0),
            'received' => (float) ($invoices->received ?? 0),
            'received_month' => (float) ($invoices->received_month ?? 0),
            'pending' => (float) ($invoices->pending ?? 0),
            'pending_count' => (int) ($invoices->pending_count ?? 0),
            'overdue' => (float) ($invoices->overdue ?? 0),
        ];
    }

    /**
     * Por mes: cobrado (fecha de pago), ingresos manuales (fecha de recepción) y
     * por cobrar (vencimiento de las facturas pendientes; lo vencido cae en el mes actual).
     *
     * @return array<int, array{month: string, collected: float, manual: float, projected: float}>
     */
    private function monthly(): array
    {
        $current = CarbonImmutable::today()->startOfMonth();
        $start = $current->subMonths(self::MONTHS_BACK);
        $end = $current->addMonths(self::MONTHS_AHEAD)->endOfMonth();
        $range = [$start->toDateString(), $end->toDateString().' 23:59:59'];

        $collected = $this->sumByMonth(CollectionInvoice::query()->where('status', CollectionInvoice::STATUS_PAID)->whereBetween('paid_at', $range), 'paid_at');
        $manual = $this->sumByMonth(ManualCashIncome::query()->whereBetween('received_at', $range), 'received_at');
        $projected = $this->sumByMonth(CollectionInvoice::query()->unpaid()->whereBetween('due_at', [$current->toDateString(), $range[1]]), 'due_at');
        $overdue = (float) CollectionInvoice::query()->unpaid()->where('due_at', '<', $current->toDateString())->sum('amount');

        $months = [];
        for ($cursor = $start; $cursor->lte($end); $cursor = $cursor->addMonth()) {
            $key = $cursor->format('Y-m');
            $months[] = [
                'month' => $key,
                'collected' => (float) ($collected[$key] ?? 0),
                'manual' => (float) ($manual[$key] ?? 0),
                'projected' => (float) ($projected[$key] ?? 0) + ($cursor->equalTo($current) ? $overdue : 0),
            ];
        }

        return $months;
    }

    /**
     * @return array<string, float>
     */
    private function sumByMonth(Builder $query, string $column): array
    {
        $month = ReportService::monthExpression($column);

        return $query->toBase()->selectRaw("{$month} as month, COALESCE(SUM(amount), 0) as amount")
            ->groupByRaw($month)->pluck('amount', 'month')->map(fn ($value) => (float) $value)->all();
    }
}
