<?php

namespace Tests\Feature\Documents;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Services\Documents\DocumentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Subida blindada: tipo real, tamaño, PDF legible, disco privado y sin archivos huérfanos.
 */
class DocumentUploadTest extends DocumentsTestCase
{
    public function test_valid_pdf_is_stored_privately_with_version_one(): void
    {
        $this->actingInCompany($this->operator, $this->company)
            ->post('/documentos', [
                'file' => $this->pdf('rtn.pdf'),
                'category' => 'rtn',
                'reference_number' => '08019999123456',
                'issued_at' => now()->subMonth()->toDateString(),
                'expires_at' => now()->addYear()->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $document = Document::query()->sole();

        $this->assertSame('empresa', $document->kind);
        $this->assertSame('rtn', $document->category);
        $this->assertSame('rtn', $document->name);
        $this->assertSame('Vigente', $document->status);
        $this->assertSame($this->company->id, $document->company_id);
        $this->assertSame('application/pdf', $document->mime_type);
        $this->assertStringStartsWith('documents/private/', $document->path);
        Storage::disk('local')->assertExists($document->path);
        $this->assertSame(1, $document->currentVersion->version);
        $this->assertDatabaseHas('audit_logs', ['action' => 'documento.subido', 'resource_id' => $document->id]);
    }

    public function test_truncated_pdf_is_rejected_with_422_and_leaves_nothing(): void
    {
        $this->actingInCompany($this->admin, $this->company)
            ->postJson('/documentos', ['file' => $this->truncatedPdf(), 'category' => 'rtn'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file' => 'incompleto o dañado']);

        $this->assertSame(0, Document::query()->count());
        $this->assertSame([], $this->storedFiles());
    }

    public function test_encrypted_pdf_is_rejected(): void
    {
        $this->actingInCompany($this->admin, $this->company)
            ->postJson('/documentos', ['file' => $this->encryptedPdf(), 'category' => 'rtn'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file' => 'cifrado']);

        $this->assertSame([], $this->storedFiles());
    }

    public function test_disallowed_type_and_disguised_content_are_rejected(): void
    {
        $this->actingInCompany($this->admin, $this->company)
            ->postJson('/documentos', ['file' => UploadedFile::fake()->createWithContent('notas.txt', 'hola'), 'category' => 'otro'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file' => 'no es un tipo permitido']);

        // Un ejecutable renombrado a .pdf: el contenido manda, no la extensión.
        $this->actingInCompany($this->admin, $this->company)
            ->postJson('/documentos', ['file' => UploadedFile::fake()->createWithContent('factura.pdf', "MZ\x90\x00".str_repeat("\x00", 200)), 'category' => 'otro'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file' => 'no corresponde']);

        $this->assertSame(0, Document::query()->count());
        $this->assertSame([], $this->storedFiles());
    }

    public function test_excel_files_are_accepted(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('xl/workbook.xml', '<workbook/>');
        $zip->close();

        $this->actingInCompany($this->admin, $this->company)
            ->post('/documentos', ['file' => new UploadedFile($path, 'precios.xlsx', null, null, true), 'category' => 'otro'])
            ->assertSessionHasNoErrors();

        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', Document::query()->sole()->mime_type);
    }

    public function test_size_limit_comes_from_configuration(): void
    {
        config(['tools.uploads.max_pdf_mb' => 1]);
        $big = UploadedFile::fake()->createWithContent('pesado.pdf', $this->pdfContents().str_repeat('%', 1024 * 1024 + 10));

        $this->actingInCompany($this->admin, $this->company)
            ->postJson('/documentos', ['file' => $big, 'category' => 'otro'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file' => 'el máximo es 1 MB']);

        $this->assertSame([], $this->storedFiles());
    }

    public function test_directory_category_and_dates_are_validated(): void
    {
        $this->actingInCompany($this->admin, $this->company)
            ->postJson('/documentos', [
                'file' => $this->pdf(),
                'category' => 'inventada',
                'issued_at' => '2026-05-01',
                'expires_at' => '2026-04-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category', 'expires_at']);

        $this->assertSame([], $this->storedFiles());
    }

    public function test_failure_inside_transaction_removes_the_stored_file(): void
    {
        Document::creating(fn () => throw new RuntimeException('Fallo simulado'));

        try {
            app(DocumentService::class)->create(['name' => 'X', 'kind' => 'empresa', 'company_id' => $this->company->id], $this->pdf(), $this->admin);
            $this->fail('Se esperaba la excepción.');
        } catch (RuntimeException) {
            // esperado
        }

        $this->assertSame([], $this->storedFiles());
        $this->assertSame(0, DocumentVersion::query()->count());
    }

    public function test_version_numbers_never_repeat_and_original_is_kept(): void
    {
        $service = app(DocumentService::class);
        $this->actingInCompany($this->admin, $this->company);
        $document = $this->inCompany($this->company, fn () => $service->create(['name' => 'Solvencia', 'kind' => 'empresa', 'category' => 'solvencia_sar'], $this->pdf('v1.pdf'), $this->admin));

        $service->addVersion($document, $this->pdf('v2.pdf', 'Dos'), $this->admin);
        // Un objeto desactualizado (versión 1 en memoria) no repite el número.
        $stale = Document::query()->withoutGlobalScopes()->find($document->id);
        $stale->version = 1;
        $third = $service->addVersion($stale, $this->pdf('v3.pdf', 'Tres'), $this->admin);
        $signed = $service->storeDerivedVersion($document->fresh(), $this->pdfContents('Firmado'), 'v3-firmado.pdf', $this->admin);

        $this->assertSame(3, $third->version);
        $this->assertSame(4, $signed->version);
        $this->assertNotNull($signed->signed_at);
        $this->assertSame([1, 2, 3, 4], DocumentVersion::query()->orderBy('version')->pluck('version')->all());
        $this->assertCount(4, $this->storedFiles());
        $this->assertSame(4, $document->fresh()->currentVersion->version);
    }
}
