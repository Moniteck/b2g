<?php

namespace App\Services;

use App\Models\Quote;
use Barryvdh\DomPDF\Facade\Pdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Exportaciones de cotizaciones.
 *
 * Los precios de referencia ya incluyen impuesto, por lo que el total coincide
 * con el subtotal y el impuesto contenido se informa solo como referencia.
 */
class QuoteDocumentService
{
    public function pdf(Quote $quote): string
    {
        return Pdf::loadView('pdf.quote', ['quote' => $quote->loadMissing(['company', 'institution', 'contact', 'items'])])->setPaper('a4')->output();
    }

    public function listPdf(iterable $quotes): string
    {
        $quotes = collect($quotes)->loadMissing(['company', 'institution']);

        return Pdf::loadView('pdf.quotes-list', ['quotes' => $quotes])->setPaper('a4')->output();
    }

    public function spreadsheet(iterable $quotes): string
    {
        $sheet = (new Spreadsheet)->getActiveSheet();
        $sheet->fromArray(['Número', 'Empresa', 'Institución', 'Fecha', 'Tipo', 'Subtotal', 'Impuesto contenido', 'Total', 'Estado', 'Resultado'], null, 'A1');
        $row = 2;

        foreach ($quotes as $quote) {
            $sheet->fromArray([
                $quote->number,
                $quote->company?->name,
                $quote->institution?->name,
                $quote->quoted_at?->toDateString(),
                $quote->purchase_type,
                $quote->subtotal,
                $quote->tax,
                $quote->total,
                $quote->status,
                $quote->outcome,
            ], null, "A{$row}");
            $row++;
        }

        $sheet->getStyle('A1:J1')->getFont()->setBold(true);

        foreach (range('A', 'J') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $path = tempnam(sys_get_temp_dir(), 'crm-quotes-');
        (new Xlsx($sheet->getParent()))->save($path);

        return $path;
    }
}
