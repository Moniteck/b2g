<?php

namespace Tests\Feature\Tenders;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\Quote;
use App\Models\Tender;
use App\Models\User;
use App\Services\Tenders\CaptureConsistency;
use App\Services\Tenders\OcrReader;
use App\Services\Tenders\TenderLifecycle;
use App\Services\Tenders\TenderStage;
use App\Services\Tenders\TenderText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Rediseño de Licitaciones: etapas y estados automáticos (B-19), vistas tabla/kanban/calendario,
 * filtros, cambio de etapa, ficha con siguiente paso, importación con cajas del OCR, avisos de
 * contradicción y oportunidad única (B-18), atajo de la PWA, exportes y rendimiento de la lista.
 */
class TenderRedesignTest extends TestCase
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

    private function tender(array $attributes = []): Tender
    {
        return Tender::factory()->for($this->company)->create($attributes);
    }

    /** OCR simulado con el texto y la disposición (TSV) reales de la captura de referencia. */
    private function fakeOcrWithLayout(): void
    {
        $text = file_get_contents(base_path('tests/fixtures/honducompras/captura-cpr-sit-025-2022.ocr.txt'));
        $tsv = file_get_contents(base_path('tests/fixtures/honducompras/captura-cpr-sit-025-2022.tsv'));

        $this->app->instance(OcrReader::class, new class($text, $tsv) extends OcrReader
        {
            public function __construct(private string $text, private string $tsv) {}

            public function read(string $imagePath, string $psm = '6'): string
            {
                $this->layout = ['lines' => self::linesFromTsv($this->tsv)];

                return $this->text;
            }
        });
    }

    private function importAs(User $user): string
    {
        $file = new UploadedFile(base_path('tests/fixtures/honducompras/captura-cpr-sit-025-2022.png'), 'captura.png', 'image/png', null, true);
        $response = $this->actingInCompany($user, $this->company)->post('/licitaciones/importar', ['file' => $file]);
        $response->assertRedirect();

        return basename(parse_url($response->headers->get('Location'), PHP_URL_PATH));
    }

    private function confirmation(array $overrides = []): array
    {
        return array_merge([
            'reference' => 'CPR-SIT-025-2022',
            'title' => 'Supervisión del bacheo',
            'new_institution_name' => 'Secretaría de Infraestructura y Transporte',
            'new_institution_acronym' => 'SIT',
            'presentation_deadline' => now()->addDays(10)->format('Y-m-d\TH:i'),
            'clarification_deadline' => now()->addDays(5)->format('Y-m-d\TH:i'),
            'create_opportunity' => true,
        ], $overrides);
    }

    public function test_stage_is_derived_on_the_fly_and_expired_tenders_hide_by_default(): void
    {
        $overdue = $this->tender(['reference' => 'LPN-VENCIDA']);
        DB::table('tenders')->where('id', $overdue->id)->update(['presentation_deadline' => now()->subDays(3), 'clarification_deadline' => now()->subDays(6), 'opening_at' => now()->subDays(3)]);
        $presented = $this->tender(['reference' => 'LPN-PRESENTADA', 'status' => 'Presentada', 'offer_submitted' => true, 'presentation_deadline' => now()->subDay(), 'opening_at' => now()->subHours(20)]);
        $this->tender(['reference' => 'LPN-VIGENTE']);

        $this->assertSame('vencida', TenderStage::of($overdue->fresh())['key']);
        $this->assertSame('Esperando resultado', TenderStage::of($presented->fresh())['status_label']);

        $this->actingInCompany($this->viewer, $this->company)->get('/licitaciones')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Tenders/Index')
                ->where('tenders.total', 2)
                ->where('stats.expired', 1)
                ->where('stats.presented', 1)
                ->where('stats.awaiting', 1)
                ->where('tenders.data.0.reference', 'LPN-VIGENTE')
                ->where('tenders.data.0.next_deadline.key', 'clarification_deadline'));

        $this->actingInCompany($this->viewer, $this->company)->get('/licitaciones?etapa=vencida')
            ->assertInertia(fn (Assert $page) => $page->where('tenders.total', 1)->where('tenders.data.0.stage.key', 'vencida'));

        $this->actingInCompany($this->viewer, $this->company)->get('/licitaciones?etapa=presentada,por_analizar&orden=reference&dir=desc')
            ->assertInertia(fn (Assert $page) => $page->where('tenders.total', 2)->where('tenders.data.0.reference', 'LPN-VIGENTE'));
    }

    public function test_filters_by_owner_mine_institution_and_reception_dates(): void
    {
        $this->tender(['reference' => 'LPN-MIA', 'owner_id' => $this->operator->id, 'presentation_deadline' => now()->addDays(20)->setTime(10, 0)]);
        $this->tender(['reference' => 'LPN-OTRA', 'owner_id' => $this->admin->id, 'presentation_deadline' => now()->addDays(40)->setTime(10, 0)]);

        $this->actingInCompany($this->operator, $this->company)->get('/licitaciones?mias=1')
            ->assertInertia(fn (Assert $page) => $page->where('tenders.total', 1)->where('tenders.data.0.reference', 'LPN-MIA')->where('filters.active', 1));

        $this->actingInCompany($this->operator, $this->company)->get("/licitaciones?owner_id={$this->admin->id}")
            ->assertInertia(fn (Assert $page) => $page->where('tenders.total', 1)->where('tenders.data.0.owner.name', $this->admin->name));

        $from = now()->addDays(30)->toDateString();
        $this->actingInCompany($this->operator, $this->company)->get("/licitaciones?desde={$from}")
            ->assertInertia(fn (Assert $page) => $page->where('tenders.total', 1)->where('tenders.data.0.reference', 'LPN-OTRA'));

        // Fecha mal escrita: se ignora, no rompe.
        $this->actingInCompany($this->operator, $this->company)->get('/licitaciones?desde=31-02-2026')->assertOk();
    }

    public function test_kanban_and_calendar_views(): void
    {
        $at = now()->addDays(20)->setTime(10, 0);
        $this->tender(['reference' => 'LPN-A', 'status' => 'En preparación', 'presentation_deadline' => $at, 'clarification_deadline' => null, 'opening_at' => null]);
        $this->tender(['reference' => 'LPN-B', 'status' => 'Adjudicada']);

        $this->actingInCompany($this->viewer, $this->company)->get('/licitaciones?vista=kanban')
            ->assertInertia(fn (Assert $page) => $page->where('tenders', null)
                ->where('board.columns.1.id', 'en_preparacion')
                ->where('board.columns.1.total', 1)
                ->where('board.columns.3.total', 1)
                ->has('board.cards', 2));

        $month = $at->format('Y-m');
        $this->actingInCompany($this->viewer, $this->company)->get("/licitaciones?vista=calendario&mes={$month}")
            ->assertInertia(fn (Assert $page) => $page->where('calendar.month', $month)
                ->where('calendar.events', fn ($events) => collect($events)->contains(fn ($e) => $e['reference'] === 'LPN-A' && $e['kind'] === 'presentation_deadline')));
    }

    public function test_stage_move_respects_roles_results_and_companies(): void
    {
        $tender = $this->tender();

        $this->actingInCompany($this->viewer, $this->company)->patch("/licitaciones/{$tender->id}/etapa", ['stage' => 'en_preparacion'])->assertForbidden();

        $this->actingInCompany($this->operator, $this->company)->patch("/licitaciones/{$tender->id}/etapa", ['stage' => 'en_preparacion'])->assertSessionHas('success');
        $this->assertSame('En preparación', $tender->fresh()->status);

        $this->actingInCompany($this->operator, $this->company)->patch("/licitaciones/{$tender->id}/etapa", ['stage' => 'presentada']);
        $this->assertTrue($tender->fresh()->offer_submitted);
        $this->assertSame('Presentada', $tender->fresh()->status);

        // El resultado se registra en la oportunidad, no arrastrando la tarjeta.
        $this->actingInCompany($this->operator, $this->company)->patch("/licitaciones/{$tender->id}/etapa", ['stage' => 'adjudicada'])->assertSessionHasErrors('stage');
        $this->actingInCompany($this->operator, $this->company)->patch("/licitaciones/{$tender->id}/etapa", ['stage' => 'inventada'])->assertSessionHasErrors('stage');

        [$otherAdmin, $other] = $this->memberOf();
        $this->actingInCompany($otherAdmin, $other)->patch("/licitaciones/{$tender->id}/etapa", ['stage' => 'desierta'])->assertNotFound();

        $this->assertSame(2, AuditLog::query()->withoutGlobalScopes()->where('action', 'licitacion.etapa')->where('resource_id', $tender->id)->count());
    }

    public function test_daily_command_expires_overdue_tenders_without_offer(): void
    {
        $overdue = $this->tender(['reference' => 'LPN-1']);
        $withQuote = $this->tender(['reference' => 'LPN-2']);
        $future = $this->tender(['reference' => 'LPN-3']);
        DB::table('tenders')->whereIn('id', [$overdue->id, $withQuote->id])->update(['presentation_deadline' => now()->subDays(2)]);
        Quote::factory()->for($this->company)->create(['tender_id' => $withQuote->id, 'status' => 'Presentada']);

        $this->artisan('licitaciones:actualizar-estados')->expectsOutputToContain('vencidas: 1')->assertSuccessful();

        $this->assertSame('Vencida', $overdue->fresh()->status);
        $this->assertSame('Activa', $withQuote->fresh()->status);
        $this->assertSame('Activa', $future->fresh()->status);
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()->where('action', 'licitacion.vencida')->count());

        // Idempotente.
        $this->assertSame(0, app(TenderLifecycle::class)->expireOverdue());

        // Si después se registra la oferta, vuelve a «Presentada».
        $fresh = $overdue->fresh();
        $fresh->offer_submitted = true;
        $fresh->save();
        $this->assertSame('Presentada', $fresh->fresh()->status);
    }

    public function test_show_offers_next_step_stepper_and_deferred_history(): void
    {
        config(['services.openai.key' => null]);
        $tender = $this->tender(['contact_phone' => '2232-7200 EXT. 1126', 'title' => 'SUPERVISIÓN DEL BACHEO DE LA RUTA CA-7 (SIT)']);
        $this->actingInCompany($this->operator, $this->company)->put("/licitaciones/{$tender->id}", [
            'reference' => $tender->reference, 'title' => $tender->title, 'status' => 'Activa', 'estimated_amount' => 100,
        ]);

        $this->actingInCompany($this->operator, $this->company)->get("/licitaciones/{$tender->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Tenders/Show')
                ->where('tender.display_title', 'Supervisión del bacheo de la ruta CA-7 (SIT)')
                ->where('tender.stage.key', 'por_analizar')
                ->where('tender.next_deadline.key', 'clarification_deadline')
                ->where('flow.steps.0.state', 'done')
                ->where('flow.next.label', 'Crear cotización')
                ->missing('history')
                ->loadDeferredProps('historial', fn (Assert $reload) => $reload
                    ->where('history.0.title', 'Datos actualizados')));

        // El lector ve la ficha sin botón de siguiente paso.
        $this->actingInCompany($this->viewer, $this->company)->get("/licitaciones/{$tender->id}")
            ->assertInertia(fn (Assert $page) => $page->where('flow.next', null)->where('can.update', false));
    }

    public function test_presented_tender_without_opportunity_offers_to_create_one_only_once(): void
    {
        $tender = $this->tender(['status' => 'Presentada', 'offer_submitted' => true]);

        $this->actingInCompany($this->operator, $this->company)->get("/licitaciones/{$tender->id}")
            ->assertInertia(fn (Assert $page) => $page->where('flow.next.label', 'Crear oportunidad'));

        $this->actingInCompany($this->viewer, $this->company)->post("/licitaciones/{$tender->id}/oportunidad")->assertForbidden();
        $this->actingInCompany($this->operator, $this->company)->post("/licitaciones/{$tender->id}/oportunidad")->assertRedirect();
        $this->actingInCompany($this->operator, $this->company)->post("/licitaciones/{$tender->id}/oportunidad")->assertRedirect();
        $this->assertSame(1, Opportunity::query()->where('tender_id', $tender->id)->count());

        $this->actingInCompany($this->operator, $this->company)->get("/licitaciones/{$tender->id}")
            ->assertInertia(fn (Assert $page) => $page->where('flow.next.label', 'Registrar participantes'));
    }

    public function test_import_review_has_ocr_boxes_warnings_and_suggested_status(): void
    {
        $this->fakeOcrWithLayout();
        $token = $this->importAs($this->operator);

        $this->actingInCompany($this->operator, $this->company)->get("/licitaciones/importar/{$token}")
            ->assertInertia(fn (Assert $page) => $page->component('Tenders/ImportReview')
                ->where('review.fields.reference.box', fn ($box) => $box['x'] >= 0 && $box['w'] > 0.1 && $box['y'] > 0.1 && $box['y'] < 0.5)
                ->where('review.fields.title.lines', [8, 9, 10])
                ->where('review.imageSize.width', 1109)
                // La recepción de 2022 ya pasó: se sugiere importarla como vencida.
                ->where('review.suggestedStatus', 'Vencida')
                ->where('review.warnings', fn ($warnings) => collect($warnings)->contains(fn ($w) => $w['field'] === 'presentation_deadline' && $w['kind'] === 'info')));
    }

    public function test_consistency_detects_contradictions(): void
    {
        $warnings = CaptureConsistency::check([
            'clarification_deadline' => '2030-07-26 16:00',
            'presentation_deadline' => '2030-07-25 16:00',
            'started_at' => '2030-07-30 08:00',
            'honducompras_stage' => 'Adjudicado',
            'status' => 'Activa',
            'bid_document_fee' => null,
        ], ['bid_document_fee' => ['status' => 'missing']]);

        $fields = array_column($warnings, 'field');
        $this->assertContains('clarification_deadline', $fields);
        $this->assertContains('presentation_deadline', $fields);
        $this->assertContains('honducompras_stage', $fields);
        $this->assertContains('bid_document_fee', $fields);
        $this->assertSame('Desierta', CaptureConsistency::suggestedStatus(['honducompras_stage' => 'Desierto']));
    }

    public function test_importing_twice_never_duplicates_the_opportunity(): void
    {
        $this->fakeOcrWithLayout();

        $first = $this->importAs($this->operator);
        $this->actingInCompany($this->operator, $this->company)->post("/licitaciones/importar/{$first}", $this->confirmation())->assertRedirect();
        $tender = Tender::query()->sole();
        $this->assertSame(1, Opportunity::query()->count());

        // Segunda captura del mismo proceso, con el expediente escrito distinto (B-18).
        $second = $this->importAs($this->operator);
        $this->actingInCompany($this->operator, $this->company)->get("/licitaciones/importar/{$second}")
            ->assertInertia(fn (Assert $page) => $page->where('review.duplicate.id', $tender->id)->where('review.existingOpportunity.id', Opportunity::query()->sole()->id));

        $this->actingInCompany($this->operator, $this->company)
            ->post("/licitaciones/importar/{$second}", $this->confirmation(['reference' => 'CPR SIT-025-2022', 'update_existing' => true, 'update_tender_id' => $tender->id]))
            ->assertRedirect("/licitaciones/{$tender->id}")
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'se reutilizó'));

        $this->assertSame(1, Tender::query()->count());
        $this->assertSame(1, Opportunity::query()->count());
    }

    public function test_loose_opportunity_with_the_same_reference_is_linked_instead_of_duplicated(): void
    {
        $loose = Opportunity::factory()->for($this->company)->create(['tender_id' => null, 'title' => 'Bacheo CPR-SIT-025-2022', 'institution_id' => null]);
        $this->fakeOcrWithLayout();

        $token = $this->importAs($this->operator);
        $this->actingInCompany($this->operator, $this->company)->post("/licitaciones/importar/{$token}", $this->confirmation())->assertRedirect();

        $this->assertSame(1, Opportunity::query()->count());
        $this->assertSame(Tender::query()->sole()->id, $loose->fresh()->tender_id);
    }

    public function test_contradictions_are_reminded_when_confirming(): void
    {
        $this->fakeOcrWithLayout();
        $token = $this->importAs($this->operator);

        $this->actingInCompany($this->operator, $this->company)->post("/licitaciones/importar/{$token}", $this->confirmation([
            'clarification_deadline' => now()->addDays(12)->format('Y-m-d\TH:i'),
            'create_opportunity' => false,
        ]))->assertSessionHas('warning', fn ($message) => str_contains($message, 'aclaraciones es posterior'));

        $this->assertSame(0, Opportunity::query()->count());
    }

    public function test_pwa_and_dashboard_shortcuts_open_the_import(): void
    {
        $this->actingInCompany($this->operator, $this->company)->get('/licitaciones?accion=importar')
            ->assertInertia(fn (Assert $page) => $page->where('openImport', true));
        $this->actingInCompany($this->operator, $this->company)->get('/licitaciones?importar=1')
            ->assertInertia(fn (Assert $page) => $page->where('openImport', true));
        $this->actingInCompany($this->operator, $this->company)->get('/licitaciones')
            ->assertInertia(fn (Assert $page) => $page->where('openImport', false));
    }

    public function test_exports_use_the_new_columns_and_filters(): void
    {
        $this->tender(['reference' => 'LPN-EXP-1', 'title' => '=HYPERLINK("x")']);

        $response = $this->actingInCompany($this->viewer, $this->company)->get('/licitaciones/export/xlsx?etapa=por_analizar');
        $response->assertOk();
        $sheet = IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet();
        $values = collect($sheet->toArray())->flatten()->filter()->values();
        $this->assertTrue($values->contains('Próximo plazo'));
        $this->assertTrue($values->contains('Etapa'));
        $this->assertTrue($values->contains('LPN-EXP-1'));
        // SafeCell: el título que parece fórmula queda como texto.
        $this->assertFalse(collect($sheet->getCellCollection()->getCoordinates())->contains(fn ($c) => $sheet->getCell($c)->getDataType() === 'f'));

        $this->actingInCompany($this->viewer, $this->company)->get('/licitaciones/export/pdf?vencidas=1')->assertOk();
    }

    public function test_list_runs_with_few_queries_and_without_n_plus_one(): void
    {
        foreach (range(1, 30) as $i) {
            $this->tender(['reference' => "LPN-Q-{$i}", 'owner_id' => $this->operator->id, 'institution_id' => Institution::factory()->create()->id]);
        }

        $this->actingInCompany($this->admin, $this->company)->get('/licitaciones')->assertOk();

        DB::enableQueryLog();
        $this->actingInCompany($this->admin, $this->company)->get('/licitaciones')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(15, $count, "La lista hizo {$count} consultas.");
    }

    public function test_text_display_normalizes_shouting(): void
    {
        $this->assertSame('1. Supervisión del bacheo de la red. 2. Ruta CA-7, empalme en S156', TenderText::display('1. SUPERVISIÓN DEL BACHEO DE LA RED. 2. RUTA CA-7, EMPALME EN S156'));
        $this->assertSame('Suministro de papelería', TenderText::display('Suministro de papelería'));
    }
}
