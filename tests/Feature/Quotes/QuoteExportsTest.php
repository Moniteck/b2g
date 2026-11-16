<?php

namespace Tests\Feature\Quotes;

/**
 * Exportes de la lista de cotizaciones (PDF y Excel), también para el rol de solo lectura.
 */
class QuoteExportsTest extends QuotesTestCase
{
    public function test_list_pdf_and_excel_download_for_every_role(): void
    {
        $this->quote(['number' => 'COT-FIN-0002']);

        foreach ([$this->admin, $this->operator, $this->viewer] as $user) {
            $this->as($user)->get('/cotizaciones/export/list-pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->as($user)->get('/cotizaciones/export/excel')->assertOk();
        }
    }
}
