<?php

namespace App\Services;

use App\Services\Metrics\BusinessMetrics;

/**
 * Valores reales de los KPI de la empresa activa en un período. Salen de `BusinessMetrics`, la misma
 * definición de Reportes y del Dashboard, así que las tres pantallas coinciden.
 */
class KpiService
{
    public const METRICS = [
        'pipeline' => 'Pipeline ponderado',
        'adjudicaciones' => 'Monto adjudicado',
        'cobros' => 'Cobros recibidos',
        'tasa_adjudicacion' => 'Tasa de adjudicación',
        'ejecucion_poa' => 'Ejecución POA',
    ];

    /** Explicación corta de cada indicador, para la interfaz. */
    public const DESCRIPTIONS = [
        'pipeline' => 'Monto × probabilidad de las oportunidades activas creadas en el período.',
        'adjudicaciones' => 'Monto adjudicado de las cotizaciones ganadas, por fecha de cotización.',
        'cobros' => 'Facturas cobradas en el período, por fecha de pago.',
        'tasa_adjudicacion' => 'Cotizaciones ganadas ÷ (ganadas + perdidas) del período; las pendientes no cuentan.',
        'ejecucion_poa' => 'Ejecutado ÷ presupuestado de las partidas POA del período.',
    ];

    /** Métricas que se expresan en porcentaje; el resto en lempiras. */
    public const PERCENT_METRICS = ['tasa_adjudicacion', 'ejecucion_poa'];

    public function __construct(private BusinessMetrics $metrics) {}

    /**
     * @return array<string, float|null>
     */
    public function actuals(string $from, string $to): array
    {
        $period = $this->metrics->period($from, $to);

        return [
            'pipeline' => $period['pipeline']['weighted'],
            'adjudicaciones' => $period['quotes']['won_amount'],
            'cobros' => $period['collections']['paid_amount'],
            'tasa_adjudicacion' => $period['quotes']['award_rate'],
            'ejecucion_poa' => $period['poa']['execution'],
        ];
    }
}
