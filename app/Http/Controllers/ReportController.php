<?php

namespace App\Http\Controllers;

use App\Services\ReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Inertia\Inertia;

class ReportController extends Controller
{
    public function index(Request $request, ReportService $reports)
    {
        return Inertia::render('Reports', ['report' => $reports->summary($reports->filters($request))]);
    }

    public function pdf(Request $request, ReportService $reports)
    {
        $report = $reports->summary($reports->filters($request));
        return Pdf::loadView('pdf.report', compact('report'))->setPaper('a4')->download('reporte-b2g.pdf');
    }

    public function xlsx(Request $request, ReportService $reports)
    {
        $report = $reports->summary($reports->filters($request));
        $spreadsheet = new Spreadsheet(); $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['Indicador', 'Módulo', 'Registros', 'Monto (L)'], null, 'A1');
        foreach ($report['rows'] as $index => $row) $sheet->fromArray([$row['label'], $row['module'], $row['count'], $row['amount']], null, 'A'.($index + 2));
        $totalRow = count($report['rows']) + 2; $sheet->fromArray(['TOTAL', '', '', $report['total']], null, "A{$totalRow}");
        $sheet->getStyle("A1:D1")->getFont()->setBold(true); $sheet->getStyle("A{$totalRow}:D{$totalRow}")->getFont()->setBold(true);
        foreach (range('A', 'D') as $column) $sheet->getColumnDimension($column)->setAutoSize(true);
        $path = tempnam(sys_get_temp_dir(), 'crm-report-'); (new Xlsx($spreadsheet))->save($path);
        return response()->download($path, 'reporte-b2g.xlsx')->deleteFileAfterSend(true);
    }

    public function csv(Request $request, ReportService $reports)
    {
        $report = $reports->summary($reports->filters($request));
        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'wb'); fputcsv($out, ['Indicador', 'Módulo', 'Registros', 'Monto (L)']);
            foreach ($report['rows'] as $row) fputcsv($out, [$row['label'], $row['module'], $row['count'], $row['amount']]);
            fputcsv($out, ['TOTAL', '', '', $report['total']]); fclose($out);
        }, 'reporte-b2g.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
