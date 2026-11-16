<?php

namespace Tests\Feature\Finance;

use App\Models\CollectionInvoice;
use App\Models\KpiGoal;
use App\Models\Quote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsAndKpisTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_exports_share_the_filtered_summary(): void
    {
        [$user, $company] = $this->memberOf(role: 'viewer');

        $this->inCompany($company, function () use ($company) {
            Quote::factory()->for($company)->won()->create(['quoted_at' => '2026-08-10', 'total' => 150, 'awarded_amount' => 150]);
            CollectionInvoice::create(['number' => 'FAC-1', 'concept' => 'Servicio', 'amount' => 300, 'issued_at' => '2026-08-01', 'due_at' => '2026-08-15', 'paid_at' => '2026-08-12', 'status' => 'Pagada']);
        });

        $this->actingAs($user)->get('/reportes?from=2026-08-01&to=2026-08-31')->assertOk();

        $csv = $this->actingAs($user)->get('/reportes/csv?from=2026-08-01&to=2026-08-31');
        $csv->assertOk();
        $content = $csv->streamedContent();

        $this->assertStringContainsString('"Cotizaciones ganadas",Cotizaciones,1,150', $content);
        $this->assertStringContainsString('"Cobros recibidos",Cobros,1,300', $content);
    }

    public function test_only_company_administrators_can_mutate_kpi_goals(): void
    {
        [$admin, $company] = $this->memberOf(role: 'admin');
        [$operator] = $this->memberOf($company, 'operator');
        $payload = ['metric' => 'cobros', 'target' => 5000, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31'];

        $this->actingAs($operator)->post('/kpis', $payload)->assertForbidden();
        $this->actingAs($admin)->post('/kpis', $payload)->assertRedirect();

        $this->assertDatabaseHas('kpi_goals', ['metric' => 'cobros', 'target' => 5000, 'company_id' => $company->id]);
        $this->assertSame(1, KpiGoal::count());
    }

    public function test_each_company_keeps_its_own_goal_for_the_same_period(): void
    {
        [$adminA, $companyA] = $this->memberOf();
        [$adminB, $companyB] = $this->memberOf();
        $payload = ['metric' => 'cobros', 'target' => 5000, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31'];

        $this->actingAs($adminA)->post('/kpis', $payload)->assertRedirect();
        $this->actingAs($adminB)->post('/kpis', ['target' => 9000] + $payload)->assertRedirect();

        $this->assertDatabaseHas('kpi_goals', ['company_id' => $companyA->id, 'target' => 5000]);
        $this->assertDatabaseHas('kpi_goals', ['company_id' => $companyB->id, 'target' => 9000]);
    }
}
