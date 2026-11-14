<?php

namespace Tests\Feature\Documents;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Base de las pruebas del módulo de documentos: disco privado falso, una empresa con sus tres
 * roles y PDF reales generados en memoria (válidos, truncados o cifrados).
 */
abstract class DocumentsTestCase extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $admin;

    protected User $operator;

    protected User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        [$this->admin, $this->company] = $this->memberOf(role: 'admin');
        [$this->operator] = $this->memberOf($this->company, 'operator');
        [$this->viewer] = $this->memberOf($this->company, 'viewer');
    }

    /** PDF mínimo válido de una página (con tabla xref y `%%EOF`). */
    protected function pdfContents(string $text = 'Documento de prueba', string $trailerExtra = ''): string
    {
        $stream = "BT /F1 24 Tf 72 700 Td ({$text}) Tj ET";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer'."\n<< /Size ".(count($objects) + 1)." /Root 1 0 R{$trailerExtra} >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    protected function pdf(string $name = 'documento.pdf', string $text = 'Documento de prueba'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->pdfContents($text));
    }

    /** PDF cortado a la mitad, como una descarga interrumpida. */
    protected function truncatedPdf(string $name = 'cortado.pdf'): UploadedFile
    {
        $contents = $this->pdfContents();

        return UploadedFile::fake()->createWithContent($name, substr($contents, 0, intdiv(strlen($contents), 2)));
    }

    /** PDF con diccionario de cifrado en el trailer. */
    protected function encryptedPdf(string $name = 'cifrado.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->pdfContents('Secreto', ' /Encrypt 6 0 R'));
    }

    /** Rutas de todos los archivos guardados en el disco privado. */
    protected function storedFiles(): array
    {
        return Storage::disk('local')->allFiles('documents/private');
    }
}
