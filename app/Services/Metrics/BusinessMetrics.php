<?php

namespace App\Services\Metrics;

use App\Models\AccessRequest;
use App\Models\CollectionInvoice;
use App\Models\Contract;
use App\Models\ContractDelivery;
use App\Models\Document;
use App\Models\Event;
use App\Models\Opportunity;
use App\Models\PoaEntry;
use App\Models\Quote;
use App\Models\Tender;
use App\Services\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Definición ÚNICA de las cifras del negocio de la empresa activa (hallazgo B-01 de la auditoría).
 *
 * Dashboard, Reportes, KPI (y la MCP) leen de aquí, así que la misma cifra vale lo mismo en todas
 * las pantallas. Reglas:
 *
 *  - Pipeline ponderado: Σ monto × probabilidad de las oportunidades en etapa activa
 *    (prospecto, oportunidad, oferta presentada) CREADAS en el período. El embudo cuenta todas las
 *    etapas con el mismo criterio de fecha.
 *  - Tasa de adjudicación: cotizaciones ganadas ÷ (ganadas + perdidas), es decir, sobre las
 *    cotizaciones DECIDIDAS por fecha de cotización (`quoted_at`). Las pendientes se informan aparte y
 *    no cuentan. Sin decididas, la tasa es `null` (no 0 %). Sin período (`awardRate()`) es histórica:
 *    la cifra de la cabecera de Cotizaciones.
 *  - Monto adjudicado: Σ COALESCE(monto adjudicado, total) de las cotizaciones ganadas del período.
 *  - Cobros: facturas pagadas por fecha de pago. Cartera por cobrar en una fecha D: facturas emitidas
 *    hasta D que no estaban pagadas en D; vencida, además, con vencimiento anterior a D.
 *  - Licitaciones decididas (adjudicadas / no adjudicadas): por fecha de recepción de ofertas
 *    (`presentation_deadline`, o `closing_date` si falta).
 *  - Procesos vigentes: licitación en estado abierto (Activa, En preparación) cuya recepción de ofertas
 *    no ha pasado (o aún no tiene fecha). Es el mismo criterio del filtro «Vigentes» de Licitaciones.
 *  - POA: partidas por fecha estimada.
 *
 * Caché: 60 s por empresa con una VERSIÓN por empresa que sube al guardar o borrar cualquier modelo
 * implicado (`watch()`); el store `database` no admite etiquetas, así que la versión forma parte de
 * cada clave y lo anterior simplemente deja de leerse.
 */
class BusinessMetrics
{
    public const TTL_SECONDS = 60;

    /** Atajos de período del Dashboard. */
    public const PERIODS = ['mes' => 'Este mes', 'trimestre' => 'Este trimestre', 'anio' => 'Este año'];

    public const PRESENTED_QUOTE_STATUSES = ['Presentada', 'Sustituida'];

    public const TENDER_WON = ['Adjudicada'];

    public const TENDER_LOST = ['No adjudicada', 'Perdida'];

    /** Meses máximos de una serie mensual. */
    public const MAX_MONTHS = 36;

    /** Modelos cuyas escrituras cambian alguna cifra: invalidan la caché de su empresa. */
    public const WATCHED = [
        Opportunity::class, Quote::class, Tender::class, CollectionInvoice::class, PoaEntry::class,
        Contract::class, ContractDelivery::class, Document::class, Event::class, AccessRequest::class,
    ];

    /** @var array<int, string> Versión ya leída en esta petición, por empresa. */
    private static array $versions = [];

    private static bool $watching = false;

    public function __construct(private CompanyContext $context) {}

    // ───────────────────────────── Caché ─────────────────────────────

    /** Registro (una vez) en `AppServiceProvider::boot`: cada escritura sube la versión de su empresa. */
    public static function watch(): void
    {
        if (self::$watching) {
            return;
        }
        self::$watching = true;

        foreach (self::WATCHED as $model) {
            foreach (['saved', 'deleted', 'restored'] as $event) {
                if ($event === 'restored' && ! method_exists($model, 'restore')) {
                    continue;
                }
                $model::$event(fn (Model $record) => self::bump(self::companyOf($record)));
            }
        }
    }

    public static function bump(?int $companyId): void
    {
        if ($companyId === null) {
            return;
        }
        $version = (string) hrtime(true);
        self::$versions[$companyId] = $version;
        Cache::forever(self::versionKey($companyId), $version);
    }

    public static function version(int $companyId): string
    {
        return self::$versions[$companyId] ??= (string) Cache::get(self::versionKey($companyId), '0');
    }

    /** Olvida las versiones leídas (pruebas y procesos largos). */
    public static function flushMemory(): void
    {
        self::$versions = [];
    }

    private static function versionKey(int $companyId): string
    {
        return "metrics.version.{$companyId}";
    }

    private static function companyOf(Model $record): ?int
    {
        if ($record instanceof ContractDelivery) {
            $companyId = Contract::query()->withoutGlobalScopes()->whereKey($record->contract_id)->value('company_id');

            return $companyId === null ? null : (int) $companyId;
        }

        $companyId = $record->getAttribute('company_id');

        return $companyId === null ? null : (int) $companyId;
    }

    /**
     * Guarda el resultado 60 s con la versión vigente de la empresa activa.
     *
     * @template T
     *
     * @param  array<int|string, mixed>  $args
     * @param  Closure(): T  $compute
     * @return T
     */
    public function remember(string $name, array $args, Closure $compute): mixed
    {
        $companyId = $this->context->currentCompanyId();
        if ($companyId === null) {
            return $compute();
        }

        $key = sprintf('metrics.%d.%s.%s.%s', $companyId, self::version($companyId), $name, md5(json_encode($args)));

        return Cache::remember($key, self::TTL_SECONDS, $compute);
    }

    // ───────────────────────────── Períodos ─────────────────────────────

    /**
     * Rango de un atajo del Dashboard en hora de Honduras.
     *
     * @return array{from: string, to: string}
     */
    public static function presetRange(string $preset, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();

        [$from, $to] = match ($preset) {
            'trimestre' => [$today->firstOfQuarter(), $today->lastOfQuarter()],
            'anio' => [$today->startOfYear(), $today->endOfYear()],
            default => [$today->startOfMonth(), $today->endOfMonth()],
        };

        return ['from' => $from->toDateString(), 'to' => $to->toDateString()];
    }

    /**
     * Período anterior de la misma longitud: si el rango son meses completos, los meses anteriores
     * (marzo → febrero, T2 → T1, 2026 → 2025); si no, los mismos días justo antes.
     *
     * @return array{from: string, to: string}
     */
    public static function previousRange(string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();

        if ($start->day === 1 && $end->isSameDay($end->endOfMonth())) {
            $months = ($end->year - $start->year) * 12 + $end->month - $start->month + 1;
            $previousStart = $start->subMonthsNoOverflow($months);

            return ['from' => $previousStart->toDateString(), 'to' => $start->subDay()->toDateString()];
        }

        $days = (int) $start->diffInDays($end) + 1;

        return ['from' => $start->subDays($days)->toDateString(), 'to' => $start->subDay()->toDateString()];
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
     * @return array{0: string, 1: string}
     */
    private static function bounds(string $from, string $to): array
    {
        return [CarbonImmutable::parse($from)->startOfDay()->toDateTimeString(), CarbonImmutable::parse($to)->endOfDay()->toDateTimeString()];
    }

    // ───────────────────────────── Cifras de un período ─────────────────────────────

    /**
     * Todas las cifras de un período (Reportes, KPI y la parte por período del Dashboard).
     *
     * @return array{pipeline: array<string, mixed>, quotes: array<string, mixed>, collections: array<string, mixed>, tenders: array<string, mixed>, poa: array<string, mixed>}
     */
    public function period(string $from, string $to): array
    {
        return $this->remember('period', [$from, $to], fn () => [
            'pipeline' => $this->computePipeline($from, $to),
            'quotes' => $this->computeQuotes($from, $to),
            'collections' => $this->computeCollections($from, $to),
            'tenders' => $this->computeTenders($from, $to),
            'poa' => $this->computePoa($from, $to),
        ]);
    }

    /**
     * Tasa de adjudicación (ganadas ÷ decididas). Sin fechas, histórica (cabecera de Cotizaciones).
     *
     * @return array{won: int, lost: int, decided: int, rate: float|null}
     */
    public function awardRate(?string $from = null, ?string $to = null): array
    {
        $quotes = $from !== null && $to !== null
            ? $this->period($from, $to)['quotes']
            : $this->remember('quotes.all', [], fn () => $this->computeQuotes(null, null));

        return ['won' => $quotes['won_count'], 'lost' => $quotes['lost_count'], 'decided' => $quotes['won_count'] + $quotes['lost_count'], 'rate' => $quotes['award_rate']];
    }

    /** Procesos vigentes: licitaciones abiertas cuya recepción de ofertas no ha pasado. */
    public function openTenders(): int
    {
        return $this->remember('tenders.open', [], fn () => (int) self::openTendersQuery(Tender::query())->count());
    }

    /** Criterio de «vigente» (mismo filtro que «Vigentes» en Licitaciones). */
    public static function openTendersQuery(Builder $query): Builder
    {
        return $query->whereIn('status', Tender::OPEN_STATUSES)
            ->where(fn (Builder $q) => $q->whereNull('presentation_deadline')->orWhere('presentation_deadline', '>=', now()));
    }

    /**
     * Cartera por cobrar hoy.
     *
     * @return array{outstanding: float, outstanding_count: int, overdue: float, overdue_count: int}
     */
    public function receivables(): array
    {
        return $this->remember('receivables', [], function () {
            $today = CarbonImmutable::today()->toDateString();
            [$condition, $bindings] = self::receivableCondition($today);
            [$overdue, $overdueBindings] = self::overdueCondition($today);

            $row = CollectionInvoice::query()->toBase()->selectRaw(
                "COALESCE(SUM(CASE WHEN {$condition} THEN amount ELSE 0 END), 0) AS outstanding,
                 COALESCE(SUM(CASE WHEN {$condition} THEN 1 ELSE 0 END), 0) AS outstanding_count,
                 COALESCE(SUM(CASE WHEN {$overdue} THEN amount ELSE 0 END), 0) AS overdue,
                 COALESCE(SUM(CASE WHEN {$overdue} THEN 1 ELSE 0 END), 0) AS overdue_count",
                [...$bindings, ...$bindings, ...$overdueBindings, ...$overdueBindings],
            )->first();

            return [
                'outstanding' => round((float) ($row->outstanding ?? 0), 2),
                'outstanding_count' => (int) ($row->outstanding_count ?? 0),
                'overdue' => round((float) ($row->overdue ?? 0), 2),
                'overdue_count' => (int) ($row->overdue_count ?? 0),
            ];
        });
    }

    /**
     * Adjudicado (cotizaciones ganadas por fecha de cotización) y cobrado (por fecha de pago) por mes.
     *
     * @return array<int, array{month: string, won: float, collected: float}>
     */
    public function monthly(string $from, string $to): array
    {
        return $this->remember('monthly', [$from, $to], function () use ($from, $to) {
            $end = CarbonImmutable::parse($to)->endOfDay();
            $start = CarbonImmutable::parse($from)->startOfMonth();
            if ($start->diffInMonths($end) >= self::MAX_MONTHS) {
                $start = $end->startOfMonth()->subMonths(self::MAX_MONTHS - 1);
            }
            $range = [$start->toDateTimeString(), $end->toDateTimeString()];

            $won = $this->sumByMonth(Quote::query()->where('outcome', 'Ganada')->whereBetween('quoted_at', $range), 'quoted_at', 'COALESCE(awarded_amount, total)');
            $collected = $this->sumByMonth(CollectionInvoice::query()->where('status', CollectionInvoice::STATUS_PAID)->whereBetween('paid_at', $range), 'paid_at', 'amount');

            $months = [];
            for ($cursor = $start; $cursor->lte($end); $cursor = $cursor->addMonthNoOverflow()) {
                $month = $cursor->format('Y-m');
                $months[] = ['month' => $month, 'won' => round($won[$month] ?? 0, 2), 'collected' => round($collected[$month] ?? 0, 2)];
            }

            return $months;
        });
    }

    /**
     * Serie mensual de los KPI del Dashboard (pipeline ponderado, decididas y adjudicado por mes, y
     * cartera al cierre de cada mes). Sale de las MISMAS condiciones que `period()`: sumar los meses de
     * un período da exactamente la cifra del período.
     *
     * @return array<string, array{pipeline: float, active: int, won: int, lost: int, pending: int, won_amount: float, receivable: float}>
     */
    public function kpiSeries(string $from, string $to): array
    {
        return $this->remember('kpi-series', [$from, $to], function () use ($from, $to) {
            $start = CarbonImmutable::parse($from)->startOfMonth();
            $end = CarbonImmutable::parse($to)->endOfMonth();
            $range = [$start->toDateTimeString(), $end->endOfDay()->toDateTimeString()];

            $pipelineMonth = self::monthExpression('created_at');
            $pipeline = Opportunity::query()->toBase()
                ->whereBetween('created_at', $range)
                ->whereIn('stage', Opportunity::ACTIVE_STAGES)
                ->selectRaw("{$pipelineMonth} AS month, COALESCE(SUM(amount * probability / 100), 0) AS weighted, COUNT(*) AS active")
                ->groupByRaw($pipelineMonth)
                ->get()->keyBy('month');

            $presented = "'".implode("','", self::PRESENTED_QUOTE_STATUSES)."'";
            $quoteMonth = self::monthExpression('quoted_at');
            $quotes = Quote::query()->toBase()
                ->whereBetween('quoted_at', $range)
                ->selectRaw("{$quoteMonth} AS month,
                    SUM(CASE WHEN outcome = 'Ganada' THEN 1 ELSE 0 END) AS won,
                    SUM(CASE WHEN outcome = 'Perdida' THEN 1 ELSE 0 END) AS lost,
                    SUM(CASE WHEN outcome = 'Pendiente' AND status IN ({$presented}) THEN 1 ELSE 0 END) AS pending,
                    COALESCE(SUM(CASE WHEN outcome = 'Ganada' THEN COALESCE(awarded_amount, total) ELSE 0 END), 0) AS won_amount")
                ->groupByRaw($quoteMonth)
                ->get()->keyBy('month');

            // Cartera al cierre de cada mes (o a hoy, si el mes no ha terminado): una columna por mes.
            $today = CarbonImmutable::today();
            $months = [];
            for ($cursor = $start; $cursor->lte($end); $cursor = $cursor->addMonthNoOverflow()) {
                $months[$cursor->format('Y-m')] = $cursor->endOfMonth()->min($today)->toDateString();
            }
            $columns = [];
            $bindings = [];
            foreach (array_values($months) as $index => $date) {
                [$condition, $values] = self::receivableCondition($date);
                $columns[] = "COALESCE(SUM(CASE WHEN {$condition} THEN amount ELSE 0 END), 0) AS m{$index}";
                array_push($bindings, ...$values);
            }
            $receivable = (array) CollectionInvoice::query()->toBase()->selectRaw(implode(', ', $columns), $bindings)->first();

            $series = [];
            foreach (array_keys($months) as $index => $month) {
                $row = $quotes->get($month);
                $opportunities = $pipeline->get($month);
                $series[$month] = [
                    'pipeline' => round((float) ($opportunities->weighted ?? 0), 2),
                    'active' => (int) ($opportunities->active ?? 0),
                    'won' => (int) ($row->won ?? 0),
                    'lost' => (int) ($row->lost ?? 0),
                    'pending' => (int) ($row->pending ?? 0),
                    'won_amount' => round((float) ($row->won_amount ?? 0), 2),
                    'receivable' => round((float) ($receivable["m{$index}"] ?? 0), 2),
                ];
            }

            return $series;
        });
    }

    // ───────────────────────────── Definiciones (SQL) ─────────────────────────────

    /**
     * Factura por cobrar en la fecha D: emitida hasta D y sin pagar en D.
     *
     * @return array{0: string, 1: array<int, string>}
     */
    public static function receivableCondition(string $date): array
    {
        return [
            "(issued_at IS NULL OR issued_at <= ?) AND (status <> 'Pagada' OR (paid_at IS NOT NULL AND paid_at > ?))",
            [$date, $date],
        ];
    }

    /**
     * Factura vencida en la fecha D: por cobrar en D y con vencimiento anterior a D.
     *
     * @return array{0: string, 1: array<int, string>}
     */
    public static function overdueCondition(string $date): array
    {
        [$condition, $bindings] = self::receivableCondition($date);

        return ["{$condition} AND due_at < ?", [...$bindings, $date]];
    }

    /**
     * @return array{weighted: float, amount: float, active_count: int, stages: array<int, array{stage: string, label: string, count: int, amount: float, weighted: float}>}
     */
    private function computePipeline(string $from, string $to): array
    {
        $byStage = Opportunity::query()->toBase()
            ->whereBetween('created_at', self::bounds($from, $to))
            ->groupBy('stage')
            ->selectRaw('stage, COUNT(*) AS total, COALESCE(SUM(amount), 0) AS amount, COALESCE(SUM(amount * probability / 100), 0) AS weighted')
            ->get()->keyBy('stage');

        $stages = [];
        foreach (Opportunity::STAGES as $stage => $label) {
            $data = $byStage->get($stage);
            $stages[] = [
                'stage' => $stage,
                'label' => $label,
                'count' => (int) ($data->total ?? 0),
                'amount' => round((float) ($data->amount ?? 0), 2),
                'weighted' => round((float) ($data->weighted ?? 0), 2),
            ];
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
     * @return array<string, int|float|null>
     */
    private function computeQuotes(?string $from, ?string $to): array
    {
        $presented = "'".implode("','", self::PRESENTED_QUOTE_STATUSES)."'";

        $row = Quote::query()
            ->when($from !== null && $to !== null, fn (Builder $query) => $query->whereBetween('quoted_at', self::bounds($from, $to)))
            ->toBase()->selectRaw("
                SUM(CASE WHEN status IN ({$presented}) THEN 1 ELSE 0 END) AS presented_count,
                COALESCE(SUM(CASE WHEN status IN ({$presented}) THEN total ELSE 0 END), 0) AS presented_amount,
                SUM(CASE WHEN outcome = 'Ganada' THEN 1 ELSE 0 END) AS won_count,
                COALESCE(SUM(CASE WHEN outcome = 'Ganada' THEN COALESCE(awarded_amount, total) ELSE 0 END), 0) AS won_amount,
                SUM(CASE WHEN outcome = 'Perdida' THEN 1 ELSE 0 END) AS lost_count,
                COALESCE(SUM(CASE WHEN outcome = 'Perdida' THEN total ELSE 0 END), 0) AS lost_amount,
                SUM(CASE WHEN outcome = 'Pendiente' AND status IN ({$presented}) THEN 1 ELSE 0 END) AS pending_count
            ")->first();

        $won = (int) ($row->won_count ?? 0);
        $lost = (int) ($row->lost_count ?? 0);

        return [
            'presented_count' => (int) ($row->presented_count ?? 0),
            'presented_amount' => round((float) ($row->presented_amount ?? 0), 2),
            'won_count' => $won,
            'won_amount' => round((float) ($row->won_amount ?? 0), 2),
            'lost_count' => $lost,
            'lost_amount' => round((float) ($row->lost_amount ?? 0), 2),
            'pending_count' => (int) ($row->pending_count ?? 0),
            'award_rate' => self::rate($won, $lost),
        ];
    }

    /** Ganadas ÷ (ganadas + perdidas) en %, con un decimal; null si no hay decididas. */
    public static function rate(int $won, int $lost): ?float
    {
        return $won + $lost > 0 ? round($won / ($won + $lost) * 100, 1) : null;
    }

    /**
     * @return array<string, int|float>
     */
    private function computeCollections(string $from, string $to): array
    {
        [$start, $end] = self::bounds($from, $to);

        $row = CollectionInvoice::query()->toBase()->selectRaw(
            "SUM(CASE WHEN status = 'Pagada' AND paid_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS paid_count,
             COALESCE(SUM(CASE WHEN status = 'Pagada' AND paid_at BETWEEN ? AND ? THEN amount ELSE 0 END), 0) AS paid_amount,
             SUM(CASE WHEN status <> 'Pagada' AND issued_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS unpaid_count,
             COALESCE(SUM(CASE WHEN status <> 'Pagada' AND issued_at BETWEEN ? AND ? THEN amount ELSE 0 END), 0) AS unpaid_amount",
            [$start, $end, $start, $end, $start, $end, $start, $end],
        )->first();

        return [
            'paid_count' => (int) ($row->paid_count ?? 0),
            'paid_amount' => round((float) ($row->paid_amount ?? 0), 2),
            'unpaid_count' => (int) ($row->unpaid_count ?? 0),
            'unpaid_amount' => round((float) ($row->unpaid_amount ?? 0), 2),
        ];
    }

    /**
     * Licitaciones decididas por fecha de recepción de ofertas.
     *
     * @return array<string, int|float>
     */
    private function computeTenders(string $from, string $to): array
    {
        $won = "'".implode("','", self::TENDER_WON)."'";
        $lost = "'".implode("','", self::TENDER_LOST)."'";

        $row = Tender::query()
            ->whereRaw('COALESCE(presentation_deadline, closing_date) BETWEEN ? AND ?', self::bounds($from, $to))
            ->toBase()->selectRaw("
                SUM(CASE WHEN status IN ({$won}) THEN 1 ELSE 0 END) AS won_count,
                COALESCE(SUM(CASE WHEN status IN ({$won}) THEN estimated_amount ELSE 0 END), 0) AS won_amount,
                SUM(CASE WHEN status IN ({$lost}) THEN 1 ELSE 0 END) AS lost_count,
                COALESCE(SUM(CASE WHEN status IN ({$lost}) THEN estimated_amount ELSE 0 END), 0) AS lost_amount
            ")->first();

        return [
            'won_count' => (int) ($row->won_count ?? 0),
            'won_amount' => round((float) ($row->won_amount ?? 0), 2),
            'lost_count' => (int) ($row->lost_count ?? 0),
            'lost_amount' => round((float) ($row->lost_amount ?? 0), 2),
        ];
    }

    /**
     * @return array{count: int, budgeted: float, executed: float, execution: float|null}
     */
    private function computePoa(string $from, string $to): array
    {
        $row = PoaEntry::query()->toBase()->whereBetween('estimated_date', self::bounds($from, $to))
            ->selectRaw('COUNT(*) AS total, COALESCE(SUM(budgeted_amount), 0) AS budgeted, COALESCE(SUM(executed_amount), 0) AS executed')
            ->first();

        $budgeted = round((float) ($row->budgeted ?? 0), 2);
        $executed = round((float) ($row->executed ?? 0), 2);

        return ['count' => (int) ($row->total ?? 0), 'budgeted' => $budgeted, 'executed' => $executed, 'execution' => $budgeted > 0 ? round($executed / $budgeted * 100, 1) : null];
    }

    /**
     * @return array<string, float>
     */
    private function sumByMonth(Builder $query, string $column, string $amount): array
    {
        $month = self::monthExpression($column);

        return $query->toBase()
            ->selectRaw("{$month} AS month, COALESCE(SUM({$amount}), 0) AS amount")
            ->groupByRaw($month)
            ->pluck('amount', 'month')
            ->map(fn ($value) => (float) $value)
            ->all();
    }
}
