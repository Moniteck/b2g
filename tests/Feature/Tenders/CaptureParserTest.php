<?php

namespace Tests\Feature\Tenders;

use App\Services\Tenders\HonduComprasCaptureParser;
use App\Services\Tenders\OcrReader;
use App\Services\Tenders\SpanishDates;
use RuntimeException;
use Tests\TestCase;

/**
 * Analizador de la ficha de HonduCompras con el texto OCR real de la captura de referencia
 * (expediente CPR-SIT-025-2022), leído con `tesseract -l spa --psm 6`.
 */
class CaptureParserTest extends TestCase
{
    private const FIXTURE = 'tests/fixtures/honducompras/captura-cpr-sit-025-2022';

    public function test_reads_the_three_dates_and_the_entity_from_the_real_ocr_text(): void
    {
        $result = (new HonduComprasCaptureParser)->parse(file_get_contents(base_path(self::FIXTURE.'.ocr.txt')));
        $fields = $result['fields'];

        $this->assertTrue($result['useful']);
        $this->assertSame('2022-07-13 18:21:00', $fields['started_at']['value']);
        $this->assertSame('2022-07-25 16:00:00', $fields['presentation_deadline']['value']);
        $this->assertSame('2022-07-20 16:00:00', $fields['clarification_deadline']['value']);
        foreach (['started_at', 'presentation_deadline', 'clarification_deadline'] as $date) {
            $this->assertSame('read', $fields[$date]['status'], $date);
        }

        $this->assertSame('Secretaría de Estado en los Despachos de Infraestructura y Transporte (SIT)', $fields['entity']['value']);
        $this->assertSame('read', $fields['entity']['status']);
    }

    public function test_reference_comes_out_corrected_and_marked_as_doubtful(): void
    {
        $fields = (new HonduComprasCaptureParser)->parse(file_get_contents(base_path(self::FIXTURE.'.ocr.txt')))['fields'];

        // El OCR lee «CPRSIT-025-2022»: se repone el guion y se pide confirmarlo.
        $this->assertSame('CPR-SIT-025-2022', $fields['reference']['value']);
        $this->assertSame('doubtful', $fields['reference']['status']);
        $this->assertNotEmpty($fields['reference']['note']);
    }

    public function test_tolerates_ocr_noise_in_labels_amounts_and_catalog_values(): void
    {
        $fields = (new HonduComprasCaptureParser)->parse(file_get_contents(base_path(self::FIXTURE.'.ocr.txt')))['fields'];

        $this->assertSame('Gerencia Admiristrativa', $fields['purchasing_unit']['value']);
        $this->assertStringStartsWith('1. SUPERVISIÓN DEL BACHEO', $fields['title']['value']);
        $this->assertStringContainsString('CUERPO DE BOMBEROS 2. SUPERVISIÓN', $fields['title']['value']);
        $this->assertStringEndsWith('CRUCE CON RUTA CA-7', $fields['title']['value']);
        $this->assertSame('Concurso privado', $fields['purchase_type']['value']);
        $this->assertSame('Adjudicado', $fields['honducompras_stage']['value']);
        $this->assertSame('Consultoria', $fields['acquisition_type']['value']);
        $this->assertSame('Recursos Nacionales', $fields['funding_source_type']['value']);
        // «[Fuente ntidad» → «Entidad», corregido y dudoso.
        $this->assertSame('Entidad', $fields['funding_source']['value']);
        $this->assertSame('doubtful', $fields['funding_source']['status']);
        // «Los. 0.00» se entiende como lempiras.
        $this->assertSame(0.0, $fields['bid_document_fee']['value']);
        $this->assertSame('Salon de Usos Múltiples de la SIT', $fields['offer_reception_place']['value']);
        $this->assertSame('DESPACHO MINISTERIAL', $fields['contact_name']['value']);
        $this->assertSame('2232-7200 EXT. 1126', $fields['contact_phone']['value']);
        // El correo vino mal leído: se deja como dudoso para corregir.
        $this->assertSame('doubtful', $fields['contact_email']['status']);
    }

    public function test_text_without_the_form_is_not_useful(): void
    {
        $result = (new HonduComprasCaptureParser)->parse("Factura 001\nTotal 1,000.00\nGracias por su compra");

        $this->assertFalse($result['useful']);
        $this->assertSame('missing', $result['fields']['reference']['status']);
    }

    public function test_hour_without_meridiem_is_doubtful(): void
    {
        $fields = (new HonduComprasCaptureParser)->parse("Expediente LPN-SIT-001-2026\nEntidad Secretaría de Salud\nFecha Recepción Ofertas 25/07/2026 04:00:00\nModalidad Licitación Pública Nacional")['fields'];

        $this->assertSame('2026-07-25 04:00:00', $fields['presentation_deadline']['value']);
        $this->assertSame('doubtful', $fields['presentation_deadline']['status']);
        $this->assertSame('read', $fields['reference']['status']);
    }

    public function test_spanish_prose_dates_from_the_bid_document(): void
    {
        $this->assertSame(['date' => '2026-09-17', 'time' => null], SpanishDates::prose('hasta el día jueves diecisiete (/ 7) de septiembre del año dos mil Vei11tiséis (2026)'));
        $this->assertSame(['date' => '2026-09-30', 'time' => '10:15'], SpanishDates::prose('Tegucigalpa, hora: 10:15 a.m., del día miércoles 30 de septiembre del 2026'));
        $this->assertSame('2022-07-13 18:21:00', SpanishDates::numeric('13/07/2022 06:21:00 p.m.')['value']);
        $this->assertSame('2022-07-13 00:30:00', SpanishDates::numeric('13/07/2022 12:30:00 a.m.')['value']);
    }

    /** Integración: la imagen real con Tesseract (se omite si no está instalado). */
    public function test_tesseract_reads_the_real_capture(): void
    {
        try {
            $text = app(OcrReader::class)->read(base_path(self::FIXTURE.'.png'));
        } catch (RuntimeException $exception) {
            $this->markTestSkipped('Tesseract no disponible: '.$exception->getMessage());
        }

        $fields = (new HonduComprasCaptureParser)->parse($text)['fields'];

        $this->assertSame('2022-07-13 18:21:00', $fields['started_at']['value']);
        $this->assertSame('2022-07-25 16:00:00', $fields['presentation_deadline']['value']);
        $this->assertSame('2022-07-20 16:00:00', $fields['clarification_deadline']['value']);
        $this->assertStringContainsString('Infraestructura y Transporte', (string) $fields['entity']['value']);
        $this->assertStringContainsString('025-2022', (string) $fields['reference']['value']);
    }
}
