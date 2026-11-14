<?php

namespace App\Services\Finance;

use App\Models\Company;
use App\Services\Tenancy\CompanyContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportes (PDF, XLSX y CSV) de las pantallas financieras con la identidad del
 * sistema de diseño: colores de `config('design')`, nombre del sistema desde
 * `config('design.company.name')` y los datos de la empresa activa.
 *
 *   $export->pdf('pdf.collections', ['invoices' => $rows], 'Cobros y cartera', 'cobros');
 *   $export->xlsx('Cobros y cartera', ['Factura', 'Monto (L)'], $rows, 'cobros', moneyColumns: ['B']);
 *   $export->csv(['Factura', 'Monto'], $rows, 'cobros');
 */
class FinanceExport
{
    public function __construct(private CompanyContext $context) {}

    /**
     * Datos de la empresa activa para la cabecera de los documentos.
     *
     * @return array{name: string, tax_id: ?string, contact: ?string, system: string}
     */
    public function company(): array
    {
        return $this->company ??= $this->loadCompany();
    }

    /** @var array{name: string, tax_id: ?string, contact: ?string, system: string}|null */
    private ?array $company = null;

    /**
     * @return array{name: string, tax_id: ?string, contact: ?string, system: string}
     */
    private function loadCompany(): array
    {
        $company = Company::query()->find($this->context->currentCompanyId(), ['id', 'name', 'tax_id', 'contact_email', 'contact_phone']);

        return [
            'name' => (string) ($company?->name ?? config('design.company.name')),
            'tax_id' => $company?->tax_id,
            'contact' => collect([$company?->contact_email, $company?->contact_phone])->filter()->implode(' · ') ?: null,
            'system' => (string) config('design.company.name'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function pdf(string $view, array $data, string $title, string $slug, bool $landscape = false)
    {
        $data += ['title' => $title, 'company' => $this->company(), 'generatedAt' => now()];

        return Pdf::loadView($view, $data)
            ->setPaper('a4', $landscape ? 'landscape' : 'portrait')
            ->download($this->filename($slug, 'pdf'));
    }

    /**
     * @param  array<int, string>  $headings
     * @param  iterable<int, array<int, mixed>>  $rows
     * @param  array<int, string>  $moneyColumns  letras de columna con formato de moneda
     * @param  array<int, string>  $subtitle  líneas bajo el título (período, filtros)
     */
    public function xlsx(string $title, array $headings, iterable $rows, string $slug, array $moneyColumns = [], array $subtitle = [], ?array $totals = null): BinaryFileResponse
    {
        $company = $this->company();
        $book = new Spreadsheet;
        $book->getProperties()->setCreator($company['system'])->setTitle($title)->setCompany($company['name']);
        $sheet = $book->getActiveSheet();
        $sheet->setTitle(Str::limit($title, 28, ''));

        $lastColumn = chr(ord('A') + count($headings) - 1);
        $sheet->setCellValue('A1', $company['name']);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB($this->hex('brand.red'));
        $sheet->setCellValue('A2', $title);
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);

        $line = 3;
        foreach ($subtitle as $text) {
            $sheet->setCellValue("A{$line}", $text);
            $sheet->getStyle("A{$line}")->getFont()->getColor()->setRGB($this->hex('chrome.textSoft'));
            $line++;
        }

        $headerRow = $line + 1;
        $sheet->fromArray($headings, null, "A{$headerRow}");
        $header = $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}");
        $header->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($this->hex('chrome.surface'));

        $row = $headerRow + 1;
        foreach ($rows as $values) {
            $sheet->fromArray(array_values($values), null, "A{$row}", true);
            $row++;
        }

        if ($totals !== null) {
            $sheet->fromArray($totals, null, "A{$row}", true);
            $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->getFont()->setBold(true);
            $row++;
        }

        foreach ($moneyColumns as $column) {
            $sheet->getStyle("{$column}".($headerRow + 1).":{$column}{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
        }

        foreach (range('A', $lastColumn) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $sheet->freezePane('A'.($headerRow + 1));

        $path = tempnam(sys_get_temp_dir(), 'crm-finanzas-');
        (new Xlsx($book))->save($path);

        return response()->download($path, $this->filename($slug, 'xlsx'))->deleteFileAfterSend(true);
    }

    /**
     * CSV en UTF-8 con BOM, para que Excel lea bien las tildes.
     *
     * @param  array<int, string>  $headings
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    public function csv(array $headings, iterable $rows, string $slug): StreamedResponse
    {
        return response()->streamDownload(function () use ($headings, $rows) {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headings);

            foreach ($rows as $row) {
                fputcsv($out, array_values($row));
            }

            fclose($out);
        }, $this->filename($slug, 'csv'), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** «cobros-cap-honduras-2026-11-13.xlsx» */
    public function filename(string $slug, string $extension): string
    {
        return Str::slug($slug.' '.$this->company()['name']).'-'.today()->toDateString().'.'.$extension;
    }

    private function hex(string $token): string
    {
        return ltrim((string) config("design.{$token}"), '#');
    }
}
