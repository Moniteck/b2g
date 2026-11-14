<?php

namespace Tests\Feature\Tenders;

use App\Jobs\AnalyzeBidDocument;
use App\Models\Company;
use App\Models\DocumentExtraction;
use App\Models\DocumentLink;
use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\Tender;
use App\Models\User;
use App\Services\Tenders\OcrReader;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/**
 * Flujo web de Licitaciones: lista, ficha, alta manual, importar captura, pliego, roles y
 * aislamiento entre empresas.
 */
class TenderWebFlowTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private User $operator;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        [$this->admin, $this->company] = $this->memberOf(role: 'admin');
        [$this->operator] = $this->memberOf($this->company, 'operator');
        [$this->viewer] = $this->memberOf($this->company, 'viewer');
    }

    /** OCR simulado con el texto real de la captura de referencia. */
    private function fakeOcr(?string $text = null, bool $installed = true): void
    {
        $text = $installed ? ($text ?? file_get_contents(base_path('tests/fixtures/honducompras/captura-cpr-sit-025-2022.ocr.txt'))) : null;

        $this->app->instance(OcrReader::class, new class($text) extends OcrReader
        {
            public function __construct(private ?string $text) {}

            public function read(string $imagePath, string $psm = '6'): string
            {
                return $this->text ?? throw new RuntimeException('Tesseract no está instalado en el servidor.');
            }
        });
    }

    private function capture(): UploadedFile
    {
        return new UploadedFile(base_path('tests/fixtures/honducompras/captura-cpr-sit-025-2022.png'), 'captura.png', 'image/png', null, true);
    }

    private function importAs(User $user): string
    {
        $response = $this->actingInCompany($user, $this->company)->post('/licitaciones/importar', ['file' => $this->capture()]);
        $response->assertRedirect();

        return basename(parse_url($response->headers->get('Location'), PHP_URL_PATH));
    }

    /** @return array<string, mixed> */
    private function confirmation(array $overrides = []): array
    {
        return array_merge([
            'reference' => 'CPR-SIT-025-2022',
            'title' => 'Supervisión del bacheo de la red vial pavimentada',
            'new_institution_name' => 'Secretaría de Estado en los Despachos de Infraestructura y Transporte (SIT)',
            'new_institution_acronym' => 'SIT',
            'purchasing_unit' => 'Gerencia Administrativa',
            'started_at' => '2022-07-13T18:21',
            'presentation_deadline' => '2022-07-25T16:00',
            'clarification_deadline' => '2022-07-20T16:00',
            'purchase_type' => 'Concurso privado',
            'honducompras_stage' => 'Adjudicado',
            'acquisition_type' => 'Consultoria',
            'funding_source_type' => 'Recursos Nacionales',
            'funding_source' => 'Entidad',
            'offer_reception_place' => 'Salon de Usos Múltiples de la SIT',
            'bid_document_fee' => 0,
            'contact_name' => 'DESPACHO MINISTERIAL',
            'contact_phone' => '2232-7200 EXT. 1126',
            'contact_email' => 'contrataciones@sit.gob.hn',
            'create_opportunity' => true,
            // Como envía la pantalla: el monto vacío llega como nulo.
            'estimated_amount' => null,
        ], $overrides);
    }

    public function test_list_shows_figures_filters_and_permissions(): void
    {
        Tender::factory()->for($this->company)->create(['reference' => 'LPN-1', 'presentation_deadline' => now()->addDays(3), 'clarification_deadline' => now()->addDays(2)]);
        Tender::factory()->for($this->company)->create(['reference' => 'LPN-2', 'presentation_deadline' => now()->addDays(20), 'clarification_deadline' => now()->addDays(15)]);
        Tender::factory()->for($this->company)->expired()->create(['reference' => 'LPN-3']);
        Tender::factory()->for($this->company)->create(['reference' => 'LPN-4', 'status' => 'Cancelada']);

        $this->actingInCompany($this->viewer, $this->company)->get('/licitaciones')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Tenders/Index')
                ->where('stats', ['open' => 2, 'week' => 1, 'clarifications' => 1])
                ->where('tenders.total', 4)
                ->where('can.create', false)
                ->where('can.delete', false));

        $this->actingInCompany($this->admin, $this->company)->get('/licitaciones?window=week')
            ->assertInertia(fn (Assert $page) => $page->where('tenders.total', 1)->where('tenders.data.0.reference', 'LPN-1')->where('can.delete', true));

        $this->actingInCompany($this->admin, $this->company)->get('/licitaciones?status=Cancelada&search=LPN')
            ->assertInertia(fn (Assert $page) => $page->where('tenders.total', 1)->where('tenders.data.0.reference', 'LPN-4'));

        $this->actingInCompany($this->admin, $this->company)->get('/licitaciones/export/xlsx')->assertOk();
        $this->actingInCompany($this->admin, $this->company)->get('/licitaciones/export/pdf')->assertOk();
    }

    public function test_operator_creates_and_edits_manually_and_only_admin_deletes(): void
    {
        $this->actingInCompany($this->operator, $this->company)->post('/licitaciones', [
            'reference' => 'lpn-007-2026', 'title' => 'Lubricantes y grasas', 'status' => 'Activa',
            'estimated_amount' => 150000, 'purchasing_unit' => 'Compras', 'offer_validity_days' => 60,
            'clarification_deadline' => '2026-09-17T23:59', 'presentation_deadline' => '2026-09-30T10:00', 'opening_at' => '2026-09-30T10:15',
        ])->assertRedirect()->assertSessionHas('success');

        $tender = Tender::query()->where('reference', 'LPN-007-2026')->sole();
        $this->assertSame($this->company->id, $tender->company_id);
        $this->assertSame('2026-09-30 10:00:00', $tender->presentation_deadline->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-30', $tender->closing_date->toDateString());

        // Apertura antes de la recepción: 422 con mensaje.
        $this->actingInCompany($this->operator, $this->company)->put("/licitaciones/{$tender->id}", [
            'reference' => 'LPN-007-2026', 'title' => 'Lubricantes', 'status' => 'Activa', 'estimated_amount' => 1,
            'presentation_deadline' => '2026-09-30T10:00', 'opening_at' => '2026-09-29T10:00',
        ])->assertSessionHasErrors('opening_at');

        $this->actingInCompany($this->operator, $this->company)->put("/licitaciones/{$tender->id}", [
            'reference' => 'LPN-007-2026', 'title' => 'Lubricantes', 'status' => 'En preparación', 'estimated_amount' => 1,
        ])->assertSessionHas('success');
        $this->assertSame('En preparación', $tender->fresh()->status);

        $this->actingInCompany($this->operator, $this->company)->delete("/licitaciones/{$tender->id}")->assertForbidden();
        $this->actingInCompany($this->viewer, $this->company)->post('/licitaciones', ['reference' => 'X', 'title' => 'X', 'status' => 'Activa'])->assertForbidden();
        $this->actingInCompany($this->admin, $this->company)->delete("/licitaciones/{$tender->id}")->assertRedirect('/licitaciones');
        $this->assertModelMissing($tender);
    }

    public function test_reference_is_unique_per_company_but_not_across_companies(): void
    {
        Tender::factory()->for($this->company)->create(['reference' => 'LPN-1-2026']);
        [$otherAdmin, $other] = $this->memberOf();

        $payload = ['reference' => 'LPN-1-2026', 'title' => 'X', 'status' => 'Activa', 'estimated_amount' => 1];
        $this->actingInCompany($this->admin, $this->company)->post('/licitaciones', $payload)->assertSessionHasErrors('reference');
        $this->actingInCompany($otherAdmin, $other)->post('/licitaciones', $payload)->assertSessionHas('success');
    }

    public function test_show_page_and_isolation_between_companies(): void
    {
        $tender = Tender::factory()->for($this->company)->create();
        [$otherAdmin, $other] = $this->memberOf();

        $this->actingInCompany($this->viewer, $this->company)->get("/licitaciones/{$tender->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Tenders/Show')
                ->where('tender.reference', $tender->reference)
                ->where('can.update', false)->where('can.useAi', false));

        $this->actingInCompany($otherAdmin, $other)->get("/licitaciones/{$tender->id}")->assertNotFound();
        $this->actingInCompany($otherAdmin, $other)->put("/licitaciones/{$tender->id}", ['reference' => 'X', 'title' => 'X', 'status' => 'Activa'])->assertNotFound();
        $this->actingInCompany($otherAdmin, $other)->delete("/licitaciones/{$tender->id}")->assertNotFound();
        $this->actingInCompany($otherAdmin, $other)->get('/licitaciones')
            ->assertInertia(fn (Assert $page) => $page->where('tenders.total', 0));
    }

    public function test_capture_import_reads_reviews_and_creates_tender_opportunity_and_document(): void
    {
        $this->fakeOcr();
        Institution::factory()->create(['name' => 'Secretaría de Salud', 'acronym' => 'SESAL']);
        $sit = Institution::factory()->create(['name' => 'Secretaría de Infraestructura y Transporte', 'acronym' => 'SIT']);

        $token = $this->importAs($this->operator);

        // Nada se guardó todavía.
        $this->assertSame(0, Tender::query()->count());

        $this->actingInCompany($this->operator, $this->company)->get("/licitaciones/importar/{$token}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Tenders/ImportReview')
                ->where('review.fields.presentation_deadline.value', '2022-07-25 16:00:00')
                ->where('review.fields.reference.status', 'doubtful')
                ->where('review.suggestedInstitutionId', $sit->id)
                ->where('review.duplicate', null));

        $this->actingInCompany($this->operator, $this->company)->get("/licitaciones/importar/{$token}/imagen")->assertOk();

        $this->actingInCompany($this->operator, $this->company)
            ->post("/licitaciones/importar/{$token}", $this->confirmation(['institution_id' => $sit->id, 'new_institution_name' => null]))
            ->assertRedirect()->assertSessionHas('success');

        $tender = Tender::query()->sole();
        $this->assertSame('CPR-SIT-025-2022', $tender->reference);
        $this->assertSame($this->company->id, $tender->company_id);
        $this->assertSame($sit->id, $tender->institution_id);
        $this->assertSame('2022-07-20 16:00:00', $tender->clarification_deadline->format('Y-m-d H:i:s'));
        $this->assertSame('2022-07-13 18:21:00', $tender->started_at->format('Y-m-d H:i:s'));
        $this->assertSame('Concurso privado', $tender->purchase_type);

        $opportunity = Opportunity::query()->sole();
        $this->assertSame('prospecto', $opportunity->stage);
        $this->assertSame($tender->id, $opportunity->tender_id);

        $this->assertSame(1, DocumentLink::query()->where('linkable_type', Tender::class)->where('linkable_id', $tender->id)->count());
        $this->assertSame('captura', DocumentExtraction::query()->sole()->kind);

        // Reenviar la misma confirmación (doble clic) no duplica nada.
        $this->actingInCompany($this->operator, $this->company)
            ->post("/licitaciones/importar/{$token}", $this->confirmation(['institution_id' => $sit->id]))->assertRedirect("/licitaciones/{$tender->id}");
        $this->assertSame(1, Tender::query()->count());
        $this->assertSame(1, Opportunity::query()->count());
    }

    public function test_duplicate_reference_requires_explicit_update(): void
    {
        $this->fakeOcr();
        $existing = Tender::factory()->for($this->company)->create(['reference' => 'CPR-SIT-025-2022', 'title' => 'Ya cargada', 'clarification_deadline' => null]);

        $token = $this->importAs($this->operator);
        $this->actingInCompany($this->operator, $this->company)->get("/licitaciones/importar/{$token}")
            ->assertInertia(fn (Assert $page) => $page->where('review.duplicate.id', $existing->id));

        $this->actingInCompany($this->operator, $this->company)
            ->post("/licitaciones/importar/{$token}", $this->confirmation(['create_opportunity' => false]))
            ->assertSessionHasErrors('reference');
        $this->assertSame(1, Tender::query()->count());

        $this->actingInCompany($this->operator, $this->company)
            ->post("/licitaciones/importar/{$token}", $this->confirmation(['create_opportunity' => false, 'update_existing' => true]))
            ->assertRedirect("/licitaciones/{$existing->id}");

        $this->assertSame(1, Tender::query()->count());
        $this->assertSame('2022-07-20 16:00:00', $existing->fresh()->clarification_deadline->format('Y-m-d H:i:s'));
        $this->assertSame(1, Institution::query()->where('acronym', 'SIT')->count());
    }

    public function test_without_tesseract_the_review_offers_manual_entry(): void
    {
        $this->fakeOcr(installed: false);

        $token = $this->importAs($this->operator);

        $this->actingInCompany($this->operator, $this->company)->get("/licitaciones/importar/{$token}")
            ->assertInertia(fn (Assert $page) => $page->where('review.error', fn ($error) => str_contains($error, 'Tesseract no está instalado'))
                ->where('review.fields', []));
    }

    public function test_import_token_is_private_to_its_user_and_company(): void
    {
        $this->fakeOcr();
        $token = $this->importAs($this->operator);
        [$otherAdmin, $other] = $this->memberOf();

        $this->actingInCompany($this->admin, $this->company)->get("/licitaciones/importar/{$token}")->assertNotFound();
        $this->actingInCompany($otherAdmin, $other)->get("/licitaciones/importar/{$token}/imagen")->assertNotFound();
        $this->actingInCompany($this->viewer, $this->company)->post('/licitaciones/importar', ['file' => $this->capture()])->assertForbidden();
        $this->actingInCompany($this->operator, $this->company)->post('/licitaciones/importar', ['file' => UploadedFile::fake()->create('nota.txt', 2, 'text/plain')])->assertSessionHasErrors('file');
    }

    public function test_bid_upload_is_queued_and_requires_ai_permission(): void
    {
        Queue::fake();
        config(['services.openai.key' => 'sk-prueba']);
        $tender = Tender::factory()->for($this->company)->create();
        $pdf = UploadedFile::fake()->createWithContent('pliego.pdf', Pdf::loadHTML('<p>Pliego de condiciones LPN-007</p>')->output());

        $this->actingInCompany($this->viewer, $this->company)->post("/licitaciones/{$tender->id}/pliego", ['file' => $pdf])->assertForbidden();

        $this->actingInCompany($this->operator, $this->company)->post("/licitaciones/{$tender->id}/pliego", ['file' => $pdf])
            ->assertRedirect()->assertSessionHas('success');

        $extraction = DocumentExtraction::query()->sole();
        $this->assertSame('queued', $extraction->status);
        $this->assertSame($this->company->id, $extraction->company_id);
        Queue::assertPushed(AnalyzeBidDocument::class, fn ($job) => $job->extractionId === $extraction->id);

        $this->actingInCompany($this->viewer, $this->company)->getJson("/licitaciones/{$tender->id}/pliego/{$extraction->id}/estado")
            ->assertOk()->assertJson(['status' => 'queued', 'statusLabel' => 'En cola', 'pending' => true]);

        // Otra empresa no ve el análisis.
        [$otherAdmin, $other] = $this->memberOf();
        $this->actingInCompany($otherAdmin, $other)->getJson("/licitaciones/{$tender->id}/pliego/{$extraction->id}/estado")->assertNotFound();
    }

    public function test_ai_screen_lists_open_tenders_and_requires_ai_permission(): void
    {
        $this->actingInCompany($this->viewer, $this->company)->get('/ia-licitaciones')->assertForbidden();
        $this->actingInCompany($this->operator, $this->company)->get('/ia-licitaciones')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Tenders/Index')->where('aiView', true)->where('filters.window', 'open'));
    }
}
