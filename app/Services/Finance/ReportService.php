<?php

namespace App\Services\Finance;

use App\Services\Metrics\BusinessMetrics;

/**
 * Cifras de Reportes e Indicadores (y de KPI) de la empresa activa.
 *
 * Las definiciones viven en `BusinessMetrics` (la misma fuente del Dashboard y de la cabecera de
 * Cotizaciones); aquí solo se arma la forma de la pantalla: filas por módulo, métricas, embudo y
 * serie mensual. La caché (60 s por empresa, invalidada al guardar) también es la de `BusinessMetrics`.
 */
class ReportService
{
    /** Se conserva por compatibilidad: la caché la gobierna BusinessMetrics::TTL_SECONDS. */
    public const CACHE_SECONDS = BusinessMetrics::TTL_SECONDS;

    public const MODULE_LABELS = [
        'pipeline' => 'Pipeline',
        'cotizaciones' => 'Cotizaciones',
        'cobros' => 'Cobros',
        'licitaciones' => 'Licitaciones',
        'poa' => 'POA',
    ];

    /** Pantalla a la que lleva cada módulo de la tabla de indicadores (B-28). */
    public const MODULE_LINKS = [
        'pipeline' => '/pipeline',
        'cotizaciones' => '/cotizaciones',
        'cobros' => '/cobros',
        'licitaciones' => '/licitaciones',
        'poa' => '/alertas-poa',
    ];

    public function __construct(private BusinessMetrics $metrics) {}

    /**
     * @param  array{from: string, to: string, module?: string}  $filters
     * @return array<string, mixed>
     */
    public function summary(array $filters): array
    {
        $filters['module'] ??= 'all';
        $period = $this->metrics->period($filters['from'], $filters['to']);
        ['pipeline' => $pipeline, 'quotes' => $quotes, 'collections' => $collections, 'tenders' => $tenders, 'poa' => $poa] = $period;

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
                'award_rate' => $quotes['award_rate'],
                'collected' => $collections['paid_amount'],
                'collected_count' => $collections['paid_count'],
                'poa_budgeted' => $poa['budgeted'],
                'poa_executed' => $poa['executed'],
                'poa_execution' => $poa['execution'],
            ],
            'funnel' => array_map(fn (array $stage) => array_diff_key($stage, ['weighted' => true]), $pipeline['stages']),
            'monthly' => $this->metrics->monthly($filters['from'], $filters['to']),
        ];
    }

    /** Expresión «AAAA-MM» de una columna de fecha según el motor (la usa también Flujo de caja). */
    public static function monthExpression(string $column): string
    {
        return BusinessMetrics::monthExpression($column);
    }

    /**
     * @return array{label: string, module: string, module_label: string, href: string, amount: float, count: int}
     */
    private function row(string $label, string $module, float $amount, int $count): array
    {
        return ['label' => $label, 'module' => $module, 'module_label' => self::MODULE_LABELS[$module], 'href' => self::MODULE_LINKS[$module], 'amount' => round($amount, 2), 'count' => $count];
    }
}
