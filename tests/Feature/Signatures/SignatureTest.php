<?php

namespace Tests\Feature\Signatures;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Document;
use App\Models\SignatureApplication;
use App\Models\SigningAsset;
use App\Models\User;
use App\Services\Documents\DocumentService;
use App\Support\Binaries;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Tcpdf\Fpdi;
use TCPDF;
use Tests\TestCase;

class SignatureTest extends TestCase
{
    use RefreshDatabase;

    /** Tamaños de las páginas del PDF de prueba, en puntos: carta vertical, A4 apaisado y oficio. */
    private const PAGES = [[612.0, 792.0], [841.89, 595.28], [612.0, 1008.0]];

    private string $disk;

    protected function setUp(): void
    {
        parent::setUp();

        // Disco propio por prueba: `Storage::fake('local')` comparte carpeta con otras ejecuciones
        // en paralelo y una puede vaciarla a mitad de otra.
        $this->disk = sys_get_temp_dir().DIRECTORY_SEPARATOR.'firmas-'.bin2hex(random_bytes(6));
        Storage::set('local', Storage::createLocalDriver(['root' => $this->disk, 'throw' => false]));
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->disk);

        parent::tearDown();
    }

    public function test_user_uploads_and_replaces_their_signature(): void
    {
        [$user, $company] = $this->memberOf(role: 'operator');

        $this->actingInCompany($user, $company)
            ->postJson('/firmas/activos', ['type' => 'firma', 'file' => $this->png('firma.png')])
            ->assertOk()
            ->assertJsonPath('asset.type', 'firma')
            ->assertJsonPath('assets.sello', null);

        $first = SigningAsset::query()->withoutGlobalScopes()->sole();
        Storage::disk('local')->assertExists($first->path);

        $this->actingInCompany($user, $company)
            ->postJson('/firmas/activos', ['type' => 'firma', 'file' => $this->jpg('firma.jpg')])
            ->assertOk()
            ->assertJsonPath('message', 'Firma reemplazada.');

        $replaced = SigningAsset::query()->withoutGlobalScopes()->sole();
        $this->assertSame('image/jpeg', $replaced->mime_type);
        Storage::disk('local')->assertMissing($first->path);
        Storage::disk('local')->assertExists($replaced->path);

        $this->actingInCompany($user, $company)->get('/firmas/activos/firma/imagen')
            ->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        $this->assertTrue(AuditLog::query()->where('action', 'firma.activo.reemplazado')->exists());
    }

    public function test_upload_rejects_other_formats_and_heavy_images(): void
    {
        [$user, $company] = $this->memberOf();

        $this->actingInCompany($user, $company)
            ->postJson('/firmas/activos', ['type' => 'firma', 'file' => UploadedFile::fake()->create('firma.gif', 10, 'image/gif')])
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->actingInCompany($user, $company)
            ->postJson('/firmas/activos', ['type' => 'firma', 'file' => UploadedFile::fake()->image('firma.png', 400, 200)->size(3000)])
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->actingInCompany($user, $company)
            ->postJson('/firmas/activos', ['type' => 'rubrica', 'file' => $this->png('firma.png')])
            ->assertUnprocessable()->assertJsonValidationErrors('type');

        $this->assertSame(0, SigningAsset::query()->withoutGlobalScopes()->count());
    }

    public function test_nobody_sees_or_uses_another_users_signature(): void
    {
        [$owner, $company] = $this->memberOf();
        [$other] = $this->memberOf($company, 'admin');
        $this->giveAssets($owner, $company);
        $document = $this->document($company, $owner);

        $this->actingInCompany($other, $company)->get('/firmas/activos/firma/imagen')->assertNotFound();
        $this->actingInCompany($other, $company)->getJson('/firmas/estado')
            ->assertOk()->assertJsonPath('assets.firma', null)->assertJsonPath('assets.sello', null);

        $this->actingInCompany($other, $company)
            ->postJson('/firmas/firmar', $this->payload($document, page: 1))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Aún no has subido tu firma.');

        $this->actingInCompany($other, $company)->deleteJson('/firmas/activos/firma')->assertNotFound();
        $this->assertSame(2, SigningAsset::query()->withoutGlobalScopes()->where('user_id', $owner->id)->count());
    }

    public function test_signing_keeps_every_page_and_creates_a_new_version(): void
    {
        [$user, $company] = $this->memberOf(role: 'operator');
        $this->giveAssets($user, $company);
        $document = $this->document($company, $user);
        $original = $document->path;
        $originalHash = hash('sha256', Storage::disk('local')->get($original));

        $response = $this->actingInCompany($user, $company)
            ->postJson('/firmas/firmar', $this->payload($document, page: 2))
            ->assertCreated()
            ->assertJsonPath('document.version', 2)
            ->assertJsonPath('application.pages', [2])
            ->assertJsonPath('application.page_count', 3);

        $document->refresh();
        $this->assertSame(2, $document->version);
        $this->assertNotSame($original, $document->path);
        $this->assertSame($user->id, $document->signed_by);
        $this->assertNotNull($document->sealed_at);

        // El original sigue intacto como versión 1.
        $this->assertSame($originalHash, hash('sha256', Storage::disk('local')->get($original)));
        $this->assertSame(2, $document->versions()->count());

        $this->assertPdfPages(Storage::disk('local')->path($document->path));

        $application = SignatureApplication::query()->withoutGlobalScopes()->sole();
        $this->assertSame($user->name, $application->signer_name);
        $this->assertTrue($application->used_signature && $application->used_seal);
        $this->assertSame('firma', $application->placements[0]['type']);
        $this->assertEquals(60.0, $application->placements[0]['x']);
        $this->assertTrue(AuditLog::query()->where('action', 'documento.firmado')->where('resource_id', $document->id)->exists());

        // La última posición queda como punto de partida.
        $this->actingInCompany($user, $company)->getJson('/firmas/estado?document_id='.$document->id)
            ->assertJsonPath('last_placements.0.x', 60)
            ->assertJsonPath('document.already_signed', true);

        $this->actingInCompany($user, $company)->get($response->json('application.downloadUrl'))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_signing_all_pages_with_only_the_seal(): void
    {
        [$user, $company] = $this->memberOf();
        $this->giveAssets($user, $company);
        $document = $this->document($company, $user);

        $this->actingInCompany($user, $company)
            ->postJson('/firmas/firmar', array_merge($this->payload($document, page: 1), ['use_signature' => false, 'all_pages' => true]))
            ->assertCreated()
            ->assertJsonPath('application.pages', [1, 2, 3])
            ->assertJsonPath('application.used_signature', false)
            ->assertJsonPath('application.used_seal', true);

        $this->assertPdfPages(Storage::disk('local')->path($document->fresh()->path));
    }

    public function test_signing_twice_asks_for_confirmation(): void
    {
        [$user, $company] = $this->memberOf();
        $this->giveAssets($user, $company);
        $document = $this->document($company, $user);

        $this->actingInCompany($user, $company)->postJson('/firmas/firmar', $this->payload($document, page: 3))->assertCreated();

        $this->actingInCompany($user, $company)->postJson('/firmas/firmar', $this->payload($document, page: 3))
            ->assertStatus(409)
            ->assertJsonValidationErrors('confirm');
        $this->assertSame(2, $document->fresh()->version);

        $this->actingInCompany($user, $company)
            ->postJson('/firmas/firmar', array_merge($this->payload($document, page: 3), ['confirm' => true]))
            ->assertCreated()
            ->assertJsonPath('document.version', 3);

        $this->assertSame(2, SignatureApplication::query()->withoutGlobalScopes()->count());
    }

    public function test_viewer_cannot_sign(): void
    {
        [$admin, $company] = $this->memberOf();
        [$viewer] = $this->memberOf($company, 'viewer');
        $this->giveAssets($viewer, $company);
        $document = $this->document($company, $admin);

        $this->actingInCompany($viewer, $company)->postJson('/firmas/firmar', $this->payload($document, page: 1))->assertForbidden();
        $this->actingInCompany($viewer, $company)
            ->post('/firmas/firmar-archivo', $this->payload(null, page: 1) + ['file' => $this->pdfUpload()], ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->actingInCompany($viewer, $company)->get('/firmas')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Signatures/Index')->where('can.sign', false));

        $this->assertSame(1, $document->fresh()->version);
    }

    public function test_documents_of_another_company_are_out_of_reach(): void
    {
        [$user, $company] = $this->memberOf();
        [$foreignUser, $foreign] = $this->memberOf();
        $this->giveAssets($user, $company);
        $foreignDocument = $this->document($foreign, $foreignUser);

        $this->actingInCompany($user, $company)->postJson('/firmas/firmar', $this->payload($foreignDocument, page: 1))
            ->assertUnprocessable()->assertJsonValidationErrors('document_id');
        $this->actingInCompany($user, $company)->get("/firmas/documentos/{$foreignDocument->id}/vista")->assertNotFound();
        $this->actingInCompany($user, $company)->getJson('/firmas/documentos')->assertOk()->assertJsonCount(0, 'data');

        $own = $this->document($company, $user);
        $this->actingInCompany($user, $company)->getJson('/firmas/documentos?q=Pliego')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);

        $this->assertSame(1, $foreignDocument->fresh()->version);
    }

    public function test_invalid_or_damaged_pdf_returns_422(): void
    {
        [$user, $company] = $this->memberOf();
        $this->giveAssets($user, $company);

        $this->actingInCompany($user, $company)
            ->post('/firmas/firmar-archivo', $this->payload(null, page: 1) + ['file' => UploadedFile::fake()->createWithContent('falso.pdf', 'esto no es un pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        $damaged = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF";
        $this->actingInCompany($user, $company)
            ->post('/firmas/firmar-archivo', $this->payload(null, page: 1) + ['file' => UploadedFile::fake()->createWithContent('danado.pdf', $damaged)], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->actingInCompany($user, $company)
            ->post('/firmas/firmar-archivo', $this->payload(null, page: 9) + ['file' => $this->pdfUpload()], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonPath('message', 'El documento solo tiene 3 páginas.');

        $this->assertSame(0, SignatureApplication::query()->withoutGlobalScopes()->count());
    }

    public function test_loose_pdf_is_signed_for_its_owner_only(): void
    {
        [$user, $company] = $this->memberOf();
        [$colleague] = $this->memberOf($company, 'admin');
        $this->giveAssets($user, $company);

        $response = $this->actingInCompany($user, $company)
            ->post('/firmas/firmar-archivo', $this->payload(null, page: 1) + ['file' => $this->pdfUpload()], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('application.loose', true)
            ->assertJsonPath('document', null);

        $application = SignatureApplication::query()->withoutGlobalScopes()->sole();
        $this->assertPdfPages(Storage::disk('local')->path($application->result_path));

        $this->actingInCompany($user, $company)->get($response->json('application.downloadUrl'))->assertOk();
        $this->actingInCompany($colleague, $company)->get($response->json('application.downloadUrl'))->assertForbidden();
        $this->assertSame(0, Document::query()->withoutGlobalScopes()->count());
    }

    public function test_loose_pdf_can_be_saved_to_documents_with_the_original_as_version_one(): void
    {
        [$user, $company] = $this->memberOf(role: 'operator');
        $this->giveAssets($user, $company);

        $this->actingInCompany($user, $company)
            ->post('/firmas/firmar-archivo', $this->payload(null, page: 1) + [
                'file' => $this->pdfUpload(),
                'save_to_documents' => '1',
                'document_name' => 'Oferta firmada',
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('document.name', 'Oferta firmada')
            ->assertJsonPath('document.version', 2);

        $document = Document::query()->withoutGlobalScopes()->sole();
        $this->assertSame($company->id, $document->company_id);
        $this->assertSame(2, $document->versions()->count());
        $this->assertPdfPages(Storage::disk('local')->path($document->path));
    }

    public function test_removing_the_signature_keeps_the_history(): void
    {
        [$user, $company] = $this->memberOf();
        $this->giveAssets($user, $company);
        $document = $this->document($company, $user);
        $this->actingInCompany($user, $company)->postJson('/firmas/firmar', $this->payload($document, page: 1))->assertCreated();

        $this->actingInCompany($user, $company)->deleteJson('/firmas/activos/firma')->assertOk()->assertJsonPath('assets.firma', null);
        $this->actingInCompany($user, $company)->deleteJson('/firmas/activos/sello')->assertOk();

        $this->assertSame(0, SigningAsset::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, SignatureApplication::query()->withoutGlobalScopes()->count());
        $this->assertTrue(AuditLog::query()->where('action', 'firma.activo.quitado')->exists());

        $this->actingInCompany($user, $company)->get('/firmas')->assertOk()
            ->assertInertia(fn ($page) => $page->where('history.data.0.document_name', $document->name)->where('assets.firma', null));
    }

    public function test_pdf_with_compressed_cross_reference_is_normalized(): void
    {
        $cairo = Binaries::poppler('pdftocairo');

        $version = Process::run([$cairo, '-v']);

        if (! str_contains($version->output().$version->errorOutput(), 'pdftocairo')) {
            $this->markTestSkipped('Poppler no está disponible.');
        }

        [$user, $company] = $this->memberOf();
        $this->giveAssets($user, $company);

        // pdftocairo (cairo ≥ 1.17) escribe object streams y xref comprimido: FPDI libre no lo abre.
        $source = tempnam(sys_get_temp_dir(), 'src').'.pdf';
        $compressed = tempnam(sys_get_temp_dir(), 'cmp').'.pdf';
        file_put_contents($source, $this->pdf());
        Process::run([$cairo, '-pdf', $source, $compressed])->throw();

        try {
            $response = $this->actingInCompany($user, $company)
                ->post('/firmas/firmar-archivo', $this->payload(null, page: 3) + ['file' => new UploadedFile($compressed, 'escaneo.pdf', 'application/pdf', null, true)], ['Accept' => 'application/json'])
                ->assertCreated();

            $application = SignatureApplication::query()->withoutGlobalScopes()->sole();
            $this->assertSame(3, $application->page_count);
            $this->assertPdfPages(Storage::disk('local')->path($application->result_path));
            $response->assertJsonPath('application.pages', [3]);
        } finally {
            @unlink($source);
            @unlink($compressed);
        }
    }

    /**
     * El PDF resultante tiene las mismas páginas, con el mismo tamaño y orientación.
     */
    private function assertPdfPages(string $path): void
    {
        $pdf = new Fpdi;
        $this->assertSame(count(self::PAGES), $pdf->setSourceFile($path));

        foreach (self::PAGES as $index => [$width, $height]) {
            $size = $pdf->getTemplateSize($pdf->importPage($index + 1), null, null);
            $points = 72 / 25.4; // FPDI devuelve milímetros con la unidad por defecto.
            $this->assertEqualsWithDelta($width, $size['width'] * $points, 0.5, 'Ancho de la página '.($index + 1));
            $this->assertEqualsWithDelta($height, $size['height'] * $points, 0.5, 'Alto de la página '.($index + 1));
        }
    }

    private function giveAssets(User $user, Company $company): void
    {
        foreach (['firma', 'sello'] as $type) {
            $this->actingInCompany($user, $company)
                ->postJson('/firmas/activos', ['type' => $type, 'file' => $this->png("{$type}.png")])
                ->assertOk();
        }
    }

    private function document(Company $company, User $user): Document
    {
        return $this->inCompany($company, fn () => app(DocumentService::class)->create([
            'company_id' => $company->id,
            'name' => 'Pliego '.$company->id,
            'kind' => 'proceso',
        ], $this->pdfUpload(), $user));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(?Document $document, int $page): array
    {
        return array_filter([
            'document_id' => $document?->id,
            'use_signature' => true,
            'use_seal' => true,
            'all_pages' => false,
            'placements' => [
                ['type' => 'firma', 'page' => $page, 'x' => 60, 'y' => 80, 'width' => 25, 'height' => 8],
                ['type' => 'sello', 'page' => $page, 'x' => 10, 'y' => 75, 'width' => 15, 'height' => 12],
            ],
        ], fn ($value) => $value !== null);
    }

    private function pdfUpload(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('pliego.pdf', $this->pdf());
    }

    /** PDF de tres páginas de tamaños y orientaciones distintas, generado con TCPDF. */
    private function pdf(): string
    {
        $pdf = new TCPDF('P', 'pt');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        foreach (self::PAGES as $index => [$width, $height]) {
            $pdf->AddPage($width > $height ? 'L' : 'P', [$width, $height]);
            $pdf->Write(0, 'Página '.($index + 1));
        }

        return $pdf->Output('prueba.pdf', 'S');
    }

    private function png(string $name): UploadedFile
    {
        $image = imagecreatetruecolor(300, 100);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imageline($image, 10, 50, 290, 60, imagecolorallocate($image, 20, 40, 160));
        $path = tempnam(sys_get_temp_dir(), 'png');
        imagepng($image, $path);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    private function jpg(string $name): UploadedFile
    {
        $image = imagecreatetruecolor(300, 100);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        $path = tempnam(sys_get_temp_dir(), 'jpg');
        imagejpeg($image, $path);

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }
}
