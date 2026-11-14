<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ProcessExportService
{
    public function pdf(string $title, Collection $records)
    {
        $rows = $records->map(fn ($record) => [
            e($record->reference), e($record->title), e($record->institution?->name ?? '-'),
            number_format((float) $record->estimated_amount, 2), e($record->status),
            e($record->closing_date?->toDateString() ?? '-'),
        ]);
        $html = '<h1>'.e($title).'</h1><table width="100%" border="1" cellspacing="0" cellpadding="5"><thead><tr><th>Proceso</th><th>Objeto</th><th>Institución</th><th>Monto</th><th>Estado</th><th>Cierre</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr><td>'.implode('</td><td>', $row).'</td></tr>';
        }

        return Pdf::loadHTML($html.'</tbody></table>')->setPaper('a4', 'landscape')->download(str($title)->slug().'.pdf');
    }

    public function spreadsheet(Collection $records, string $filename)
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray(['Proceso', 'Objeto', 'Institución', 'Tipo', 'Monto estimado', 'Publicación', 'Cierre', 'Estado', 'Resultado'], null, 'A1');
        foreach ($records as $index => $record) {
            $sheet->fromArray([$record->reference, $record->title, $record->institution?->name, $record->purchase_type, $record->estimated_amount, $record->published_at?->toDateString(), $record->closing_date?->toDateString(), $record->status, $record->result], null, 'A'.($index + 2));
        }
        $sheet->getStyle('A1:I1')->getFont()->setBold(true);
        foreach (range('A', 'I') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $path = tempnam(sys_get_temp_dir(), 'crm-processes-');
        (new Xlsx($book))->save($path);

        return response()->download($path, $filename)->deleteFileAfterSend(true);
    }
}
