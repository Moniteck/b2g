<?php

namespace App\Services;

/**
 * Valores reales de los KPI de la empresa activa en un período. Salen de las
 * mismas cifras de Reportes (ReportService), así que ambas pantallas coinciden.
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
        'adjudicaciones' => 'Monto adjudicado de las cotizaciones ganadas.',
        'cobros' => 'Facturas cobradas en el período.',
        'tasa_adjudicacion' => 'Ganadas ÷ (ganadas + perdidas).',
        'ejecucion_poa' => 'Ejecutado ÷ presupuestado de las partidas POA del período.',
    ];

    /** Métricas que se expresan en porcentaje; el resto en lempiras. */
    public const PERCENT_METRICS = ['tasa_adjudicacion', 'ejecucion_poa'];

    public function __construct(private ReportService $reports) {}

    /**
     * @return array<string, float|null>
     */
    public function actuals(string $from, string $to): array
    {
        $metrics = $this->reports->summary(['from' => $from, 'to' => $to, 'module' => 'all'])['metrics'];

        return [
            'pipeline' => $metrics['pipeline_weighted'],
            'adjudicaciones' => $metrics['won_amount'],
            'cobros' => $metrics['collected'],
            'tasa_adjudicacion' => $metrics['award_rate'],
            'ejecucion_poa' => $metrics['poa_execution'],
        ];
    }
}
