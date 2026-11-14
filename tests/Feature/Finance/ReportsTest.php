<?php

namespace Tests\Feature\Finance;

use App\Models\CollectionInvoice;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Reportes e Indicadores: cifras del kanban actual, tasa de adjudicación,
 * exportes con filtros y empresa, permisos y validación del período.
 */
class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '?from=2026-08-01&to=2026-08-31';

    public function test_summary_reflects_the_current_kanban_and_award_rate(): void
    {
        [$viewer, $company] = $this->memberOf(role: 'viewer');
        $this->seedCompany($company);

        $this->actingAs($viewer)->get('/reportes'.self::PERIOD)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Finance/Reports')
                ->where('report.metrics.won_count', 2)
                ->where('report.metrics.lost_count', 1)
                // Tasa = ganadas / (ganadas + perdidas): 2 / 3.
                ->where('report.metrics.award_rate', 66.7)
                ->where('report.metrics.won_amount', 300)
                ->where('report.metrics.collected', 500)
                // Pipeline ponderado: solo etapas activas (1000 × 50 % + 2000 × 25 %).
                ->where('report.metrics.pipeline_weighted', 1000)
                ->where('report.metrics.pipeline_count', 2)
                ->where('report.funnel.0.stage', 'prospecto')
                ->where('report.funnel.3.stage', 'adjudicado')
                ->where('report.funnel.3.count', 1)
                ->where('report.funnel.4.stage', 'no_adjudicado')
                ->has('report.funnel', 5)
                ->where('report.monthly.0.month', '2026-08')
                ->where('report.monthly.0.collected', 500));
    }

    public function test_award_rate_is_null_without_decided_quotes(): void
    {
        [$viewer, $company] = $this->memberOf(role: 'viewer');
        Quote::factory()->for($company)->status('Presentada')->create(['quoted_at' => '2026-08-05']);

        $this->actingAs($viewer)->get('/reportes'.self::PERIOD)
            ->assertInertia(fn (Assert $page) => $page->where('report.metrics.award_rate', null)->where('report.metrics.pending_count', 1));
    }

    public function test_figures_never_mix_companies(): void
    {
        [$viewer, $company] = $this->memberOf(role: 'viewer');
        $other = Company::factory()->create();
        $this->seedCompany($other);
        Quote::factory()->for($company)->won()->create(['quoted_at' => '2026-08-10', 'total' => 40, 'awarded_amount' => 40]);

        $this->actingAs($viewer)->get('/reportes'.self::PERIOD)
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.metrics.won_count', 1)
                ->where('report.metrics.won_amount', 40)
                ->where('report.metrics.collected', 0)
                ->where('report.metrics.pipeline_count', 0));
    }

    public function test_module_filter_limits_the_rows(): void
    {
        [$viewer, $company] = $this->memberOf(role: 'viewer');
        $this->seedCompany($company);

        $this->actingAs($viewer)->get('/reportes'.self::PERIOD.'&module=cobros')
            ->assertInertia(fn (Assert $page) => $page
                ->has('report.rows', 2)
                ->where('report.rows.0.module', 'cobros')
                ->where('report.rows.0.amount', 500));
    }

    public function test_exports_follow_filters_and_company(): void
    {
        [$viewer, $company] = $this->memberOf(role: 'viewer');
        $this->seedCompany($company);
        $this->seedCompany(Company::factory()->create());

        $csv = $this->actingAs($viewer)->get('/reportes/csv'.self::PERIOD.'&module=cotizaciones');
        $csv->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $content = $csv->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('"Cotizaciones ganadas",cotizaciones,2,300', $content);
        $this->assertStringNotContainsString('Cobros recibidos', $content);

        $xlsx = $this->actingAs($viewer)->get('/reportes/xlsx'.self::PERIOD);
        $xlsx->assertOk()->assertDownload();
        $sheet = IOFactory::load($xlsx->baseResponse->getFile()->getPathname())->getActiveSheet();
        $this->assertSame($company->name, $sheet->getCell('A1')->getValue());
        $values = collect($sheet->toArray())->flatten()->filter()->values()->all();
        $this->assertContains('Cobros recibidos', $values);

        $pdf = $this->actingAs($viewer)->get('/reportes/pdf'.self::PERIOD);
        $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('reporte-indicadores', (string) $pdf->headers->get('content-disposition'));
    }

    public function test_invalid_period_returns_validation_errors(): void
    {
        [$viewer] = $this->memberOf(role: 'viewer');

        $this->actingAs($viewer)->from('/reportes')->get('/reportes?from=2026-08-31&to=2026-08-01')
            ->assertRedirect('/reportes')
            ->assertSessionHasErrors(['to' => 'La fecha final debe ser igual o posterior a la inicial.']);

        $this->actingAs($viewer)->from('/reportes')->get('/reportes/csv?from=2020-01-01&to=2026-01-01')
            ->assertSessionHasErrors(['to' => 'El período no puede pasar de tres años.']);

        $this->actingAs($viewer)->from('/reportes')->get('/reportes?module=garantias')->assertSessionHasErrors('module');
    }

    public function test_reports_require_company_membership(): void
    {
        [$user] = $this->memberOf();
        $outsider = User::factory()->create();

        $this->actingAs($user)->get('/reportes')->assertOk();
        $this->actingAs($outsider)->get('/reportes')->assertForbidden();
        $this->actingAs($outsider)->get('/reportes/csv')->assertForbidden();
    }

    private function seedCompany(Company $company): void
    {
        Quote::factory()->for($company)->won()->create(['quoted_at' => '2026-08-10', 'total' => 100, 'awarded_amount' => 100]);
        Quote::factory()->for($company)->won()->create(['quoted_at' => '2026-08-11', 'total' => 250, 'awarded_amount' => 200]);
        Quote::factory()->for($company)->lost()->create(['quoted_at' => '2026-08-12', 'total' => 80]);
        // Fuera del período: no cuenta.
        Quote::factory()->for($company)->lost()->create(['quoted_at' => '2026-07-30', 'total' => 80]);

        $created = ['created_at' => '2026-08-05 10:00:00'];
        Opportunity::factory()->for($company)->stage('prospecto')->create(['amount' => 1000, 'probability' => 50] + $created);
        Opportunity::factory()->for($company)->stage('oferta_presentada')->create(['amount' => 2000, 'probability' => 25] + $created);
        Opportunity::factory()->for($company)->stage('adjudicado')->create(['amount' => 9000, 'probability' => 100] + $created);

        CollectionInvoice::query()->create([
            'company_id' => $company->id, 'number' => 'FAC-1', 'concept' => 'Servicio', 'amount' => 500,
            'issued_at' => '2026-08-01', 'due_at' => '2026-08-15', 'paid_at' => '2026-08-12', 'status' => 'Pagada',
        ]);
    }
}
