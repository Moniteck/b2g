<?php

namespace Tests\Feature\Dashboard;

use App\Models\AccessRequest;
use App\Models\CollectionInvoice;
use App\Models\Company;
use App\Models\Document;
use App\Models\Opportunity;
use App\Models\Quote;
use App\Models\Tender;
use App\Services\Metrics\BusinessMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Dashboard: cifras idénticas a Reportes y KPI (una sola definición), período anterior, aislamiento
 * entre empresas, permisos por rol, empresa vacía, invalidación de la caché y presupuesto de consultas.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        BusinessMetrics::flushMemory();
        $this->travelTo('2026-08-20 10:00:00');
    }

    /** Cotizaciones, oportunidades y facturas de agosto (y una ganada de julio para el período anterior). */
    private function seedAugust(Company $company): void
    {
        $this->inCompany($company, function () use ($company) {
            Quote::factory()->for($company)->won()->create(['quoted_at' => '2026-08-05', 'total' => 1000, 'awarded_amount' => 900]);
            Quote::factory()->for($company)->won()->create(['quoted_at' => '2026-08-12', 'total' => 500, 'awarded_amount' => null]);
            Quote::factory()->for($company)->lost()->create(['quoted_at' => '2026-08-15', 'total' => 700]);
            Quote::factory()->for($company)->status('Presentada')->create(['quoted_at' => '2026-08-16']);
            Quote::factory()->for($company)->won()->create(['quoted_at' => '2026-07-10', 'total' => 300, 'awarded_amount' => 300]);

            Opportunity::factory()->for($company)->create(['amount' => 1000, 'probability' => 50, 'stage' => 'prospecto', 'created_at' => '2026-08-02']);
            Opportunity::factory()->for($company)->create(['amount' => 2000, 'probability' => 25, 'stage' => 'oferta_presentada', 'created_at' => '2026-08-03']);
            Opportunity::factory()->for($company)->create(['amount' => 9000, 'probability' => 100, 'stage' => 'adjudicado', 'created_at' => '2026-08-04']);

            CollectionInvoice::create(['number' => 'F-1', 'concept' => 'Servicio', 'amount' => 400, 'issued_at' => '2026-07-01', 'due_at' => '2026-08-01', 'status' => 'Pendiente']);
            CollectionInvoice::create(['number' => 'F-2', 'concept' => 'Servicio', 'amount' => 250, 'issued_at' => '2026-08-10', 'due_at' => '2026-09-10', 'status' => 'Pendiente']);
            CollectionInvoice::create(['number' => 'F-3', 'concept' => 'Pagada', 'amount' => 800, 'issued_at' => '2026-07-01', 'due_at' => '2026-07-30', 'paid_at' => '2026-08-11', 'status' => 'Pagada']);
        });
    }

    /** Props de la respuesta Inertia (con las diferidas si `$deferred`). */
    private function props($response, bool $deferred = false): array
    {
        $props = null;
        $response->assertOk()->assertInertia(function (Assert $page) use (&$props, $deferred) {
            $props = $page->toArray()['props'];
            if ($deferred) {
                $page->loadDeferredProps(function (Assert $reload) use (&$props) {
                    $props = array_merge($props, $reload->toArray()['props']);
                });
            }
        });

        return $props;
    }

    private function kpi(array $kpis, string $key): array
    {
        return collect($kpis)->firstWhere('key', $key);
    }

    public function test_dashboard_reports_and_kpis_show_the_same_figures(): void
    {
        [$user, $company] = $this->memberOf(role: 'viewer');
        $this->seedAugust($company);

        $dashboard = $this->props($this->actingAs($user)->get('/'));
        $report = $this->props($this->actingAs($user)->get('/reportes?from=2026-08-01&to=2026-08-31'), true)['report'];
        $kpis = $this->props($this->actingAs($user)->get('/kpis?from=2026-08-01&to=2026-08-31'))['kpis'];
        $kpiActual = fn (string $metric) => collect($kpis)->firstWhere('metric', $metric)['actual'];

        // Tasa = 2 ganadas ÷ (2 + 1 perdida) = 66.7 % en las tres pantallas; la pendiente no cuenta.
        $this->assertSame(66.7, $this->kpi($dashboard['kpis'], 'award_rate')['value']);
        $this->assertSame(66.7, $report['metrics']['award_rate']);
        $this->assertSame(66.7, $kpiActual('tasa_adjudicacion'));
        $this->assertSame('2 ganadas · 1 perdida · 1 pendiente', $this->kpi($dashboard['kpis'], 'award_rate')['hint']);

        // Adjudicado = 900 + 500 (sin monto adjudicado cuenta el total).
        $this->assertEquals(1400, $this->kpi($dashboard['kpis'], 'won_amount')['value']);
        $this->assertEquals(1400, $report['metrics']['won_amount']);
        $this->assertEquals(1400, $kpiActual('adjudicaciones'));

        // Pipeline ponderado = solo etapas activas: 1000 × 50 % + 2000 × 25 %.
        $this->assertEquals(1000, $this->kpi($dashboard['kpis'], 'pipeline')['value']);
        $this->assertEquals(1000, $report['metrics']['pipeline_weighted']);
        $this->assertEquals(1000, $kpiActual('pipeline'));

        // Cartera por cobrar hoy: 400 (vencida) + 250; la pagada no cuenta.
        $receivable = $this->kpi($dashboard['kpis'], 'receivable');
        $this->assertEquals(650, $receivable['value']);
        $this->assertTrue($receivable['alert']);
        // Al cierre de julio se debían 400 + 800 (la F-3 se pagó en agosto).
        $this->assertEquals(1200, $receivable['previous']);
    }

    public function test_variation_compares_against_the_previous_period(): void
    {
        [$user, $company] = $this->memberOf();
        $this->seedAugust($company);

        $kpis = $this->props($this->actingAs($user)->get('/'))['kpis'];
        $won = $this->kpi($kpis, 'won_amount');

        $this->assertEquals(300, $won['previous']);
        $this->assertEquals(100, $this->kpi($kpis, 'award_rate')['previous']);
        $this->assertCount(6, $won['spark']);
        $this->assertEquals([0, 0, 0, 0, 300, 1400], $won['spark']);

        // El trimestre (jul–sep) suma julio y agosto, igual que Reportes con ese rango.
        $quarter = $this->props($this->actingAs($user)->get('/?periodo=trimestre'));
        $this->assertSame('trimestre', $quarter['period']['preset']);
        $this->assertEquals(1700, $this->kpi($quarter['kpis'], 'won_amount')['value']);
        $this->assertSame(['from' => '2026-04-01', 'to' => '2026-06-30'], $quarter['period']['previous']);
    }

    public function test_deferred_blocks_load_with_the_same_funnel_as_reports(): void
    {
        [$user, $company] = $this->memberOf();
        $this->seedAugust($company);
        $this->inCompany($company, fn () => Tender::factory()->for($company)->create(['status' => 'Activa', 'presentation_deadline' => now()->addDays(5)]));
        $this->inCompany($company, fn () => Tender::factory()->for($company)->create(['status' => 'Activa', 'presentation_deadline' => now()->subDay()]));

        $this->actingAs($user)->get('/')->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->missing('charts')
            ->missing('agenda')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('charts.stages.0.stage', 'prospecto')
                ->where('charts.stages.0.count', 1)
                ->where('charts.stages.3.count', 1)
                ->where('charts.stages.0.href', '/pipeline?etapa=prospecto')
                // Vigente = abierta y con la recepción de ofertas por delante (la vencida no cuenta).
                ->where('charts.open_tenders', 1)
                ->has('charts.monthly', 6)
                ->where('charts.monthly.5.won', 1400)
                ->where('charts.monthly.5.collected', 800)
                ->has('agenda.timeline')
                ->has('agenda.onboarding.steps', 4)));
    }

    public function test_figures_never_mix_companies(): void
    {
        [$user, $company] = $this->memberOf();
        [, $other] = $this->memberOf();
        $this->seedAugust($other);
        $this->inCompany($other, fn () => Document::factory()->for($other)->expired()->create());

        $props = $this->props($this->actingAs($user)->get('/'));

        $this->assertNull($this->kpi($props['kpis'], 'award_rate')['value']);
        $this->assertEquals(0, $this->kpi($props['kpis'], 'pipeline')['value']);
        $this->assertEquals(0, $this->kpi($props['kpis'], 'receivable')['value']);
        $this->assertSame([], $props['attention']);
    }

    public function test_attention_lists_today_items_with_actions_by_role(): void
    {
        [$admin, $company] = $this->memberOf(role: 'admin');
        [$viewer] = $this->memberOf($company, 'viewer');
        $this->inCompany($company, function () use ($company) {
            Tender::factory()->for($company)->create(['reference' => 'LPN-1', 'status' => 'Activa', 'clarification_deadline' => now()->addHours(3), 'presentation_deadline' => now()->addDays(10)]);
            Tender::factory()->for($company)->create(['reference' => 'LPN-2', 'status' => 'Activa', 'offer_submitted' => false, 'presentation_deadline' => now()->addDay()]);
            Document::factory()->for($company)->expired()->create();
        });
        AccessRequest::query()->forceCreate(['name' => 'Ana', 'email' => 'ana@example.test', 'position' => 'Gerente', 'company_id' => $company->id, 'password' => 'x', 'status' => AccessRequest::PENDING, 'email_verified_at' => now()]);

        $adminItems = collect($this->props($this->actingAs($admin)->get('/'))['attention']);
        $viewerItems = collect($this->props($this->actingInCompany($viewer, $company)->get('/'))['attention']);

        $this->assertSame('Cierran las aclaraciones de LPN-1', $adminItems->first(fn ($item) => str_starts_with($item['id'], 'clarification'))['title']);
        $offer = $adminItems->first(fn ($item) => str_starts_with($item['id'], 'offer'));
        $this->assertSame('Preparar oferta', $offer['action']['label']);
        $this->assertStringStartsWith('/licitaciones/', $offer['action']['href']);
        $this->assertSame('Renovar', $adminItems->firstWhere('id', 'documents-expired')['action']['label']);
        $this->assertNotNull($adminItems->firstWhere('id', 'access-requests'));

        // El lector ve lo mismo, sin acciones de escritura ni solicitudes de acceso.
        $this->assertSame('Ver licitación', $viewerItems->first(fn ($item) => str_starts_with($item['id'], 'offer'))['action']['label']);
        $this->assertSame('Ver documentos', $viewerItems->firstWhere('id', 'documents-expired')['action']['label']);
        $this->assertNull($viewerItems->firstWhere('id', 'access-requests'));

        $this->actingInCompany($viewer, $company)->get('/')->assertInertia(fn (Assert $page) => $page->where('can.create', false));
    }

    public function test_new_company_gets_the_first_steps_guide(): void
    {
        [$user] = $this->memberOf();

        $this->actingAs($user)->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('attention', [])
            ->where('kpis.1.value', null)
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('agenda.onboarding.show', true)
                ->where('agenda.onboarding.steps.0.done', false)
                ->where('agenda.onboarding.steps.0.action', 'Importar captura')
                ->where('agenda.timeline', [])
                ->where('agenda.activity', [])
                ->where('charts.stages.0.count', 0)));
    }

    public function test_saving_a_record_refreshes_the_cached_figures(): void
    {
        [$user, $company] = $this->memberOf();
        $this->seedAugust($company);
        $this->actingAs($user)->get('/')->assertInertia(fn (Assert $page) => $page->where('kpis.1.value', 66.7));

        // Registrar otra perdida cambia la tasa al instante (sin esperar los 60 s de caché).
        $this->inCompany($company, fn () => Quote::factory()->for($company)->lost()->create(['quoted_at' => '2026-08-18']));

        $this->actingAs($user)->get('/')->assertInertia(fn (Assert $page) => $page->where('kpis.1.value', 50));
        $this->actingAs($user)->get('/reportes?from=2026-08-01&to=2026-08-31')
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload->where('report.metrics.award_rate', 50)));
    }

    public function test_cotizaciones_all_time_rate_uses_the_same_definition(): void
    {
        [$user, $company] = $this->memberOf();
        $this->seedAugust($company);

        $rate = $this->inCompany($company, fn () => app(BusinessMetrics::class)->awardRate());

        // Histórica: 3 ganadas (julio y agosto) ÷ 4 decididas.
        $this->assertSame(['won' => 3, 'lost' => 1, 'decided' => 4, 'rate' => 75.0], $rate);
        $this->actingAs($user)->get('/cotizaciones')->assertInertia(fn (Assert $page) => $page->where('stats.rate', 75));
    }

    public function test_dashboard_stays_within_the_query_budget(): void
    {
        [$user, $company] = $this->memberOf();
        $this->seedAugust($company);

        DB::enableQueryLog();
        $this->actingAs($user)->get('/')->assertOk();
        $initial = count(DB::getQueryLog());
        DB::flushQueryLog();

        $this->actingAs($user)->get('/', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) Inertia::getVersion(),
            'X-Inertia-Partial-Component' => 'Dashboard',
            'X-Inertia-Partial-Data' => 'agenda',
        ])->assertOk();
        $agenda = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(15, $initial, "La primera respuesta hizo {$initial} consultas.");
        $this->assertLessThanOrEqual(15, $agenda, "La agenda diferida hizo {$agenda} consultas.");
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }
}
