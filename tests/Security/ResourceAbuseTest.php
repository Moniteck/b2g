<?php

namespace Tests\Security;

use App\Services\Ai\BidDocumentAnalyzer;
use App\Services\Tenders\OcrReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Hallazgos de disponibilidad y costo:
 *
 * 1. Rutas costosas (IA de OpenAI, OCR con Tesseract, ZIP, PDF con dompdf) sin `throttle`:
 *    cualquier operador puede encadenar cientos de llamadas por minuto.
 * 2. El OCR de la captura corre DENTRO de la petición y no revisa las dimensiones de la imagen:
 *    un PNG de ~100 KB que declara 30 000 × 30 000 px (bomba de píxeles) llega a Tesseract.
 * 3. El análisis del pliego no limita páginas ni tramos: un PDF de 40 MB de texto se parte en
 *    cientos de tramos de 60 000 caracteres y cada uno es una llamada a OpenAI.
 *
 * Estas pruebas FALLAN mientras la vulnerabilidad exista.
 */
#[Group('security')]
class ResourceAbuseTest extends TestCase
{
    use RefreshDatabase;

    /** Rutas que llaman a OpenAI, Tesseract/Poppler o generan archivos pesados. */
    private const COSTLY_ROUTES = [
        'tenders.bid.store',        // sube pliego → OCR + OpenAI en cola
        'tenders.bid.retry',        // vuelve a encolar OCR + OpenAI
        'tenders.ai.recommend',     // OpenAI síncrono
        'tenders.import.store',     // Tesseract síncrono
        'directory.package',        // ZIP de hasta 100 documentos
        'legal.writing.pdf',        // dompdf
        'offer-calculator.pdf',     // dompdf
        'reports.pdf',              // dompdf
        'tenders.xlsx',             // PhpSpreadsheet hasta 5 000 filas
    ];

    public function test_las_rutas_costosas_tienen_limite_de_peticiones(): void
    {
        $missing = collect(self::COSTLY_ROUTES)
            ->reject(fn (string $name) => collect(Route::getRoutes()->getByName($name)?->gatherMiddleware() ?? [])
                ->contains(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'throttle')))
            ->values()->all();

        $this->assertSame([], $missing, 'Rutas costosas sin throttle: '.implode(', ', $missing));
    }

    public function test_la_captura_rechaza_imagenes_con_dimensiones_desmesuradas_antes_del_ocr(): void
    {
        Storage::fake('local');
        [$admin] = $this->memberOf();

        $calls = 0;
        $ocr = Mockery::mock(OcrReader::class);
        $ocr->shouldReceive('read')->andReturnUsing(function () use (&$calls) {
            $calls++;

            return 'texto';
        });
        $this->app->instance(OcrReader::class, $ocr);

        $bomb = UploadedFile::fake()->createWithContent('captura.png', $this->pixelBombPng(30000, 30000));
        $this->assertLessThan(2 * 1024 * 1024, $bomb->getSize(), 'Control: la bomba debe pesar poco.');
        $this->assertSame([30000, 30000], array_slice(getimagesize($bomb->getRealPath()), 0, 2), 'Control: el PNG declara 30 000 × 30 000 px.');

        $this->actingAs($admin)->post('/licitaciones/importar', ['file' => $bomb]);

        $this->assertSame(0, $calls, 'Una imagen de 900 megapíxeles llegó a Tesseract dentro de la petición web.');
    }

    public function test_el_analisis_del_pliego_tiene_un_tope_de_llamadas_a_la_ia(): void
    {
        // 700 páginas de 5 000 caracteres (≈3,5 MB de texto, un PDF muy por debajo de 40 MB).
        $pages = array_fill(0, 700, str_repeat('Requisito técnico del pliego. ', 170));

        $chunks = app(BidDocumentAnalyzer::class)->chunks($pages);

        $this->assertLessThanOrEqual(20, count($chunks), count($chunks).' llamadas a OpenAI para un solo pliego, sin tope ni confirmación.');
    }

    /** PNG en escala de grises de 1 bit: pocas decenas de KB que se expanden a cientos de MB. */
    private function pixelBombPng(int $width, int $height): string
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $row = "\x00".str_repeat("\x00", intdiv($width + 7, 8));
        $deflate = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 9]);
        $idat = '';
        for ($y = 0; $y < $height; $y++) {
            $idat .= deflate_add($deflate, $row, ZLIB_NO_FLUSH);
        }
        $idat .= deflate_add($deflate, '', ZLIB_FINISH);

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 1, 0, 0, 0, 0))
            .$chunk('IDAT', $idat)
            .$chunk('IEND', '');
    }
}
