<?php

namespace Tests\Feature;

use App\Models\CollectionInvoice;
use App\Models\KpiGoal;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsAndKpisTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_exports_share_the_filtered_summary(): void
    {
        $user = User::factory()->create();
        Quote::create(['number' => 'COT-1', 'quoted_at' => '2026-08-10', 'purchase_type' => 'Compra', 'status' => 'Aceptada', 'total' => 150]);
        CollectionInvoice::create(['number' => 'FAC-1', 'concept' => 'Servicio', 'amount' => 300, 'issued_at' => '2026-08-01', 'due_at' => '2026-08-15', 'paid_at' => '2026-08-12', 'status' => 'Pagada']);

        $this->actingAs($user)->get('/reportes?from=2026-08-01&to=2026-08-31')->assertOk();
        $csv = $this->actingAs($user)->get('/reportes/csv?from=2026-08-01&to=2026-08-31');
        $csv->assertOk();
        $content = $csv->streamedContent();
        $this->assertStringContainsString('Cotizaciones aceptadas', $content);
        $this->assertStringContainsString('Cobros recibidos', $content);
    }

    public function test_only_administrators_can_mutate_kpi_goals(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $admin = User::factory()->create(['role' => 'admin']);
        $payload = ['metric' => 'cobros', 'target' => 5000, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31'];

        $this->actingAs($operator)->post('/kpis', $payload)->assertForbidden();
        $this->actingAs($admin)->post('/kpis', $payload)->assertRedirect();
        $this->assertDatabaseHas('kpi_goals', ['metric' => 'cobros', 'target' => 5000]);
        $this->assertSame(1, KpiGoal::count());
    }
}
