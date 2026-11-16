<?php

namespace App\Http\Controllers;

use App\Http\Requests\Finance\PeriodRequest;
use App\Services\Finance\FinanceExport;
use App\Services\ReportService;
use App\Support\Permissions;
use Carbon\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reportes e Indicadores de la empresa activa. Verlos y exportarlos requiere
 * `view_reports` (todos los roles de la empresa).
 */
class ReportController extends Controller
{
    private const HEADINGS = ['Indicador', 'Módulo', 'Registros', 'Monto (L)'];

    public function index(PeriodRequest $request, ReportService $reports): Response
    {
        $this->authorize(Permissions::gate(Permissions::VIEW_REPORTS));

        $period = $request->period();

        // El cálculo va diferido: la pantalla llega al instante con sus filtros y un esqueleto, y las
        // cifras entran en una segunda petición (al filtrar, solo se pide `report`).
        return Inertia::render('Finance/Reports', [
            'filters' => $period,
            'report' => Inertia::defer(fn () => $reports->summary($period), 'report'),
            'modules' => collect(ReportService::MODULE_LABELS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
        ]);
    }

    public function pdf(PeriodRequest $request, ReportService $reports, FinanceExport $export)
    {
        $this->authorize(Permissions::gate(Permissions::VIEW_REPORTS));

        $report = $reports->summary($request->period());

        return $export->pdf('pdf.report', ['report' => $report, 'period' => $this->periodLabel($report['filters'])], 'Reporte de indicadores', 'reporte-indicadores');
    }

    public function xlsx(PeriodRequest $request, ReportService $reports, FinanceExport $export)
    {
        $this->authorize(Permissions::gate(Permissions::VIEW_REPORTS));

        $report = $reports->summary($request->period());

        return $export->xlsx(
            'Reporte de indicadores',
            self::HEADINGS,
            array_map(fn (array $row) => [$row['label'], $row['module_label'], $row['count'], $row['amount']], $report['rows']),
            'reporte-indicadores',
            moneyColumns: ['D'],
            subtitle: ['Período: '.$this->periodLabel($report['filters']), 'Tasa de adjudicación: '.$this->rate($report['metrics']['award_rate'])],
        );
    }

    public function csv(PeriodRequest $request, ReportService $reports, FinanceExport $export)
    {
        $this->authorize(Permissions::gate(Permissions::VIEW_REPORTS));

        $report = $reports->summary($request->period());

        return $export->csv(
            self::HEADINGS,
            array_map(fn (array $row) => [$row['label'], $row['module_label'], $row['count'], $row['amount']], $report['rows']),
            'reporte-indicadores',
        );
    }

    /**
     * @param  array{from: string, to: string}  $filters
     */
    private function periodLabel(array $filters): string
    {
        return Carbon::parse($filters['from'])->format('d/m/Y').' – '.Carbon::parse($filters['to'])->format('d/m/Y');
    }

    private function rate(?float $rate): string
    {
        return $rate === null ? 'sin cotizaciones decididas' : number_format($rate, 1).' %';
    }
}
