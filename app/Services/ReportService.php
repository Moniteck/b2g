<?php

namespace App\Services;

use App\Models\CollectionInvoice;
use App\Models\Opportunity;
use App\Models\PoaEntry;
use App\Models\Quote;
use App\Models\Tender;
use App\Services\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Cifras de Reportes e Indicadores (y de KPI) de la empresa activa.
 *
 * Todo se agrega en SQL, una consulta por fuente, y el resultado se guarda en
 * caché un minuto por empresa y filtro.
 *
 * Reglas de negocio:
 *  - Pipeline: oportunidades creadas en el período en etapas activas
 *    (prospecto, oportunidad, oferta presentada); el ponderado es monto × probabilidad.
 *  - Cotizaciones: por fecha de cotización. Tasa de adjudicación = ganadas / (ganadas + perdidas).
 *  - Cobros: facturas pagadas por fecha de pago.
 *  - Licitaciones: por fecha de cierre.
 *  - POA: por fecha estimada.
 */
class ReportService
{
    public const CACHE_SECONDS = 60;

    /** Meses máximos de la serie mensual. */
    private const MAX_MONTHS = 36;

    private const PRESENTED_STATUSES = ['Presentada', 'Sustituida'];

    private const TENDER_WON = ['Adjudicada'];

    private const TENDER_LOST = ['No adjudicada', 'Perdida'];

    public const MODULE_LABELS = [
        'pipeline' => 'Pipeline',
        'cotizaciones' => 'Cotizaciones',
        'cobros' => 'Cobros',
        'licitaciones' => 'Licitaciones',
        'poa' => 'POA',
    ];

    public function __construct(private CompanyContext $context) {}

    /**
     * @param  array{from: string, to: string, module?: string}  $filters
     * @return array<string, mixed>
     */
    public function summary(array $filters): array
    {
        $filters['module'] ??= 'all';
        $key = sprintf('reports.summary.%s.%s', $this->context->currentCompanyId() ?? 'all', md5(json_encode($filters)));

        return Cache::remember($key, self::CACHE_SECONDS, fn () => $this->compute($filters));
    }

    /**
     * @param  array{from: string, to: string, module: string}  $filters
     * @return array<string, mixed>
     */
    private function compute(array $filters): array
    {
        $from = CarbonImmutable::parse($filters['from'])->startOfDay();
        $to = CarbonImmutable::parse($filters['to'])->endOfDay();
        $range = [$from->toDateTimeString(), $to->toDateTimeString()];

        $pipeline = $this->pipeline($range);
        $quotes = $this->quotes($range);
        $collections = $this->collections($range);
        $tenders = $this->tenders($range);
        $poa = $this->poa($range);

        $decided = $quotes['won_count'] + $quotes['lost_count'];

        $rows = array_values(array_filter([
            $this->row('Pipeline ponderado', 'pipeline', $pipeline['weighted'], $pipeline['active_count']),
            $this->row('Cotizaciones presentadas', 'cotizaciones', $quotes['presented_amount'], $quotes['presented_count']),
            $this->row('Cotizaciones ganadas', 'cotizaciones', $quotes['won_amount'], $quotes['won_count']),
            $this->row('Cotizaciones perdidas', 'cotizaciones', $quotes['lost_amount'], $quotes['lost_count']),
            $this->row('Cobros recibidos', 'cobros', $collections['paid_amount'], $collections['paid_count']),
            $this->row('Cartera emitida sin cobrar', 'cobros', $collections['unpaid_amount'], $collections['unpaid_count']),
            $this->row('Licitaciones adjudicadas', 'licitaciones', $tenders['won_amount'], $tenders['won_count']),
            $this->row('Licitaciones no adjudicadas', 'licitaciones', $tenders['lost_amount'], $tenders['lost_count']),
            $this->row('POA presupuestado', 'poa', $poa['budgeted'], $poa['count']),
            $this->row('Ejecución POA', 'poa', $poa['executed'], $poa['count']),
        ], fn (array $row) => $filters['module'] === 'all' || $row['module'] === $filters['module']));

        return [
            'filters' => $filters,
            'rows' => $rows,
            'metrics' => [
                'pipeline_weighted' => $pipeline['weighted'],
                'pipeline_amount' => $pipeline['amount'],
                'pipeline_count' => $pipeline['active_count'],
                'quotes_presented' => $quotes['presented_count'],
                'won_count' => $quotes['won_count'],
                'won_amount' => $quotes['won_amount'],
                'lost_count' => $quotes['lost_count'],
                'pending_count' => $quotes['pending_count'],
                // Sin cotizaciones decididas no hay tasa (no es 0 %).
                'award_rate' => $decided > 0 ? round($quotes['won_count'] / $decided * 100, 1) : null,
                'collected' => $collections['paid_amount'],
                'collected_count' => $collections['paid_count'],
                'poa_budgeted' => $poa['budgeted'],
                'poa_executed' => $poa['executed'],
                'poa_execution' => $poa['budgeted'] > 0 ? round($poa['executed'] / $poa['budgeted'] * 100, 1) : null,
            ],
            'funnel' => $pipeline['stages'],
            'monthly' => $this->monthly($from, $to),
        ];
    }

    /**
     * @param  array{0: string, 1: string}  $range
     * @return array{weighted: float, amount: float, active_count: int, stages: array<int, array<string, mixed>>}
     */
    private function pipeline(array $range): array
    {
        $byStage = Opportunity::query()->toBase()
            ->whereBetween('created_at', $range)
            ->groupBy('stage')
            ->selectRaw('stage, COUNT(*) as total, COALESCE(SUM(amount), 0) as amount, COALESCE(SUM(amount * probability / 100), 0) as weighted')
            ->get()->keyBy('stage');

        $stages = [];
        foreach (Opportunity::STAGES as $stage => $label) {
            $data = $byStage->get($stage);
            $stages[] = ['stage' => $stage, 'label' => $label, 'count' => (int) ($data->total ?? 0), 'amount' => (float) ($data->amount ?? 0)];
        }

        $active = $byStage->only(Opportunity::ACTIVE_STAGES);

        return [
            'weighted' => round((float) $active->sum('weighted'), 2),
            'amount' => round((float) $active->sum('amount'), 2),
            'active_count' => (int) $active->sum('total'),
            'stages' => $stages,
        ];
    }

    /**
     * @param  array{0: string, 1: string}  $range
     * @return array<string, int|float>
     */
    private function quotes(array $range): array
    {
        $presented = "'".implode("','", self::PRESENTED_STATUSES)."'";

        $row = Quote::query()->whereBetween('quoted_at', $range)->toBase()->selectRaw("
            SUM(CASE WHEN status IN ({$presented}) THEN 1 ELSE 0 END) as presented_count,
            COALESCE(SUM(CASE WHEN status IN ({$presented}) THEN total ELSE 0 END), 0) as presented_amount,
            SUM(CASE WHEN outcome = 'Ganada' THEN 1 ELSE 0 END) as won_count,
            COALESCE(SUM(CASE WHEN outcome = 'Ganada' THEN COALESCE(awarded_amount, total) ELSE 0 END), 0) as won_amount,
            SUM(CASE WHEN outcome = 'Perdida' THEN 1 ELSE 0 END) as lost_count,
            COALESCE(SUM(CASE WHEN outcome = 'Perdida' THEN total ELSE 0 END), 0) as lost_amount,
            SUM(CASE WHEN outcome = 'Pendiente' AND status IN ({$presented}) THEN 1 ELSE 0 END) as pending_count
        ")->first();

        return [
            'presented_count' => (int) ($row->presented_count ?? 0),
            'presented_amount' => (float) ($row->presented_amount ?? 0),
            'won_count' => (int) ($row->won_count ?? 0),
            'won_amount' => (float) ($row->won_amount ?? 0),
            'lost_count' => (int) ($row->lost_count ?? 0),
            'lost_amount' => (float) ($row->lost_amount ?? 0),
            'pending_count' => (int) ($row->pending_count ?? 0),
        ];
    }

    /**
     * @param  array{0: string, 1: string}  $range
     * @return array<string, int|float>
     */
    private function collections(array $range): array
    {
        $paid = CollectionInvoice::query()->toBase()
            ->where('status', CollectionInvoice::STATUS_PAID)
            ->whereBetween('paid_at', $range)
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(amount), 0) as amount')->first();

        $unpaid = CollectionInvoice::query()->toBase()
            ->where('status', '!=', CollectionInvoice::STATUS_PAID)
            ->whereBetween('issued_at', $range)
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(amount), 0) as amount')->first();

        return [
            'paid_count' => (int) ($paid->total ?? 0),
            'paid_amount' => (float) ($paid->amount ?? 0),
            'unpaid_count' => (int) ($unpaid->total ?? 0),
            'unpaid_amount' => (float) ($unpaid->amount ?? 0),
        ];
    }

    /**
     * @param  array{0: string, 1: string}  $range
     * @return array<string, int|float>
     */
    private function tenders(array $range): array
    {
        $won = "'".implode("','", self::TENDER_WON)."'";
        $lost = "'".implode("','", self::TENDER_LOST)."'";

        $row = Tender::query()->whereBetween('closing_date', $range)->toBase()->selectRaw("
            SUM(CASE WHEN status IN ({$won}) THEN 1 ELSE 0 END) as won_count,
            COALESCE(SUM(CASE WHEN status IN ({$won}) THEN estimated_amount ELSE 0 END), 0) as won_amount,
            SUM(CASE WHEN status IN ({$lost}) THEN 1 ELSE 0 END) as lost_count,
            COALESCE(SUM(CASE WHEN status IN ({$lost}) THEN estimated_amount ELSE 0 END), 0) as lost_amount
        ")->first();

        return [
            'won_count' => (int) ($row->won_count ?? 0),
            'won_amount' => (float) ($row->won_amount ?? 0),
            'lost_count' => (int) ($row->lost_count ?? 0),
            'lost_amount' => (float) ($row->lost_amount ?? 0),
        ];
    }

    /**
     * @param  array{0: string, 1: string}  $range
     * @return array{count: int, budgeted: float, executed: float}
     */
    private function poa(array $range): array
    {
        $row = PoaEntry::query()->toBase()->whereBetween('estimated_date', $range)
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(budgeted_amount), 0) as budgeted, COALESCE(SUM(executed_amount), 0) as executed')
            ->first();

        return ['count' => (int) ($row->total ?? 0), 'budgeted' => (float) ($row->budgeted ?? 0), 'executed' => (float) ($row->executed ?? 0)];
    }

    /**
     * Adjudicado (cotizaciones ganadas) y cobrado por mes del período.
     *
     * @return array<int, array{month: string, won: float, collected: float}>
     */
    private function monthly(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $start = $from->startOfMonth();
        if ($start->diffInMonths($to) >= self::MAX_MONTHS) {
            $start = $to->startOfMonth()->subMonths(self::MAX_MONTHS - 1);
        }
        $range = [$start->toDateTimeString(), $to->toDateTimeString()];

        $won = $this->sumByMonth(
            Quote::query()->where('outcome', 'Ganada')->whereBetween('quoted_at', $range),
            'quoted_at', 'COALESCE(awarded_amount, total)'
        );
        $collected = $this->sumByMonth(
            CollectionInvoice::query()->where('status', CollectionInvoice::STATUS_PAID)->whereBetween('paid_at', $range),
            'paid_at', 'amount'
        );

        $months = [];
        for ($cursor = $start; $cursor->lte($to); $cursor = $cursor->addMonth()) {
            $month = $cursor->format('Y-m');
            $months[] = ['month' => $month, 'won' => (float) ($won[$month] ?? 0), 'collected' => (float) ($collected[$month] ?? 0)];
        }

        return $months;
    }

    /**
     * @return array<string, float>
     */
    private function sumByMonth(Builder $query, string $column, string $amount): array
    {
        $month = self::monthExpression($column);

        return $query->toBase()
            ->selectRaw("{$month} as month, COALESCE(SUM({$amount}), 0) as amount")
            ->groupByRaw($month)
            ->pluck('amount', 'month')
            ->map(fn ($value) => (float) $value)
            ->all();
    }

    /** Expresión «AAAA-MM» de una columna de fecha según el motor. */
    public static function monthExpression(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m', {$column})",
            'pgsql' => "to_char({$column}, 'YYYY-MM')",
            default => "DATE_FORMAT({$column}, '%Y-%m')",
        };
    }

    /**
     * @return array{label: string, module: string, module_label: string, amount: float, count: int}
     */
    private function row(string $label, string $module, float $amount, int $count): array
    {
        return ['label' => $label, 'module' => $module, 'module_label' => self::MODULE_LABELS[$module], 'amount' => round($amount, 2), 'count' => $count];
    }
}
