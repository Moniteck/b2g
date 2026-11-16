<?php

namespace App\Services\Business;

use App\Models\Document;
use App\Models\DocumentLink;
use App\Models\Quote;
use App\Models\User;
use App\Services\Documents\DocumentService;
use App\Support\SafeCell;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * PDF de la cotización, listados y la copia del PDF generado como documento adjunto (para firmarla).
 */
class QuoteDocumentService
{
    /** Contexto del enlace de la copia del PDF generado en `document_links`. */
    public const GENERATED_CONTEXT = 'cotizacion_generada';

    public function __construct(protected QuoteService $quotes, protected DocumentService $documents) {}

    public function pdf(Quote $quote): string
    {
        $quote->loadMissing(['company', 'institution', 'contact', 'items', 'tender:id,reference']);

        return Pdf::loadView('pdf.quote', [
            'quote' => $quote,
            'document' => $this->quotes->document($quote->items),
        ])->setPaper('letter')->output();
    }

    /**
     * Guarda el PDF generado como adjunto de la cotización y devuelve ese documento, para firmar y
     * sellar la copia (el PDF generado nunca se modifica ni se elimina, decisión 4).
     *
     * Idempotente: si ya hay una copia sin firmar posterior al último cambio de la cotización, se
     * reutiliza en vez de crear otra.
     */
    public function snapshot(Quote $quote, User $actor): Document
    {
        return DB::transaction(function () use ($quote, $actor) {
            $locked = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();

            $existing = DocumentLink::query()
                ->where('linkable_type', Quote::class)->where('linkable_id', $locked->id)
                ->where('context', self::GENERATED_CONTEXT)
                ->with('document')->latest('id')->get()
                ->map->document
                ->filter(fn (?Document $document) => $document !== null && $document->signed_at === null && $document->created_at >= $locked->updated_at)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $path = tempnam(sys_get_temp_dir(), 'cot-');
            file_put_contents($path, $this->pdf($locked));

            try {
                $file = new UploadedFile($path, "{$locked->number}.pdf", 'application/pdf', null, true);

                return $this->documents->create(
                    ['name' => "Cotización {$locked->number}", 'kind' => 'proceso', 'category' => 'Cotización'],
                    $file,
                    $actor,
                    $locked,
                    ['category' => 'Cotización generada', 'context' => self::GENERATED_CONTEXT, 'allowed' => ['pdf']],
                );
            } finally {
                @unlink($path);
            }
        });
    }

    public function listPdf(iterable $quotes): string
    {
        // collect() daría una colección base, sin loadMissing: se conserva la de Eloquent.
        $quotes = (new EloquentCollection($quotes))->loadMissing(['company', 'institution']);

        return Pdf::loadView('pdf.quotes-list', ['quotes' => $quotes])->setPaper('a4')->output();
    }

    public function spreadsheet(iterable $quotes): string
    {
        $sheet = (new Spreadsheet)->getActiveSheet();
        $sheet->fromArray(['Número', 'Empresa', 'Institución', 'Fecha', 'Tipo', 'Subtotal (sin ISV)', 'ISV', 'Total', 'Estado', 'Resultado'], null, 'A1');
        $row = 2;

        foreach ($quotes as $quote) {
            SafeCell::row($sheet, [
                $quote->number,
                $quote->company?->name,
                $quote->institution?->name,
                $quote->quoted_at?->format('d/m/Y'),
                $quote->purchase_type,
                (float) $quote->subtotal,
                (float) $quote->tax,
                (float) $quote->total,
                $quote->status,
                $quote->outcome,
            ], "A{$row}");
            $row++;
        }

        $sheet->getStyle('A1:J1')->getFont()->setBold(true);
        $sheet->getStyle("F2:H{$row}")->getNumberFormat()->setFormatCode('#,##0.00');

        foreach (range('A', 'J') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $path = tempnam(sys_get_temp_dir(), 'crm-quotes-');
        (new Xlsx($sheet->getParent()))->save($path);

        return $path;
    }
}
