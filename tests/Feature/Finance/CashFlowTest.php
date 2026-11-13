<?php

namespace Tests\Feature\Finance;

use App\Models\CollectionInvoice;
use App\Models\Company;
use App\Models\ManualCashIncome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Flujo de caja: totales y proyección por mes, ingresos manuales con permisos,
 * validación y aislamiento entre empresas.
 */
class CashFlowTest extends TestCase
{
    use RefreshDatabase;

    private const PAYLOAD = ['concept' => 'Anticipo de contrato', 'amount' => 1500, 'received_at' => '2026-11-02', 'notes' => null];

    public function test_totals_and_monthly_projection(): void
    {
        [$viewer, $company] = $this->memberOf(role: 'viewer');
        $base = ['company_id' => $company->id, 'concept' => 'Servicio', 'issued_at' => today()->subMonths(2)->toDateString()];
        CollectionInvoice::query()->create($base + ['number' => 'A', 'amount' => 400, 'due_at' => today()->toDateString(), 'paid_at' => today()->toDateString(), 'status' => 'Pagada']);
        CollectionInvoice::query()->create($base + ['number' => 'B', 'amount' => 300, 'due_at' => today()->subMonth()->toDateString(), 'status' => 'Pendiente']);
        CollectionInvoice::query()->create($base + ['number' => 'C', 'amount' => 200, 'due_at' => today()->addMonthNoOverflow()->toDateString(), 'status' => 'Pendiente']);
        ManualCashIncome::query()->create(['company_id' => $company->id, 'user_id' => $viewer->id, 'concept' => 'Aporte', 'amount' => 50, 'received_at' => today()->toDateString()]);
        ManualCashIncome::query()->create(['company_id' => Company::factory()->create()->id, 'user_id' => $viewer->id, 'concept' => 'Ajeno', 'amount' => 9999, 'received_at' => today()->toDateString()]);

        $current = today()->format('Y-m');
        $next = today()->addMonthNoOverflow()->format('Y-m');

        $this->actingAs($viewer)->get('/flujo-caja')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Finance/CashFlow')
            ->where('totals.received', 400)
            ->where('totals.pending', 500)
            ->where('totals.overdue', 300)
            ->where('totals.manual', 50)
            ->where('totals.manual_month', 50)
            ->has('records.data', 1)
            ->has('pendingInvoices', 2)
            ->where('pendingInvoices.0.display_status', 'Vencida')
            ->has('chart', 12)
            ->where('chart', fn ($months) => collect($months)->firstWhere('month', $current) == ['month' => $current, 'collected' => 400, 'manual' => 50, 'projected' => 300]
                && collect($months)->firstWhere('month', $next)['projected'] == 200));
    }

    public function test_manual_incomes_follow_roles_and_validation(): void
    {
        [$admin, $company] = $this->memberOf();
        [$operator] = $this->memberOf($company, 'operator');
        [$viewer] = $this->memberOf($company, 'viewer');

        $this->actingAs($viewer)->post('/flujo-caja', self::PAYLOAD)->assertForbidden();
        $this->actingAs($operator)->post('/flujo-caja', ['amount' => -5, 'concept' => '', 'received_at' => '05/11/2026'])
            ->assertSessionHasErrors(['amount' => 'El monto debe ser mayor que cero.', 'concept', 'received_at']);
        $this->actingAs($operator)->post('/flujo-caja', self::PAYLOAD)->assertSessionHas('success');

        $income = ManualCashIncome::withoutGlobalScopes()->sole();
        $this->assertSame($company->id, $income->company_id);
        $this->assertSame($operator->id, $income->user_id);

        $this->actingAs($viewer)->put("/flujo-caja/{$income->id}", ['amount' => 1] + self::PAYLOAD)->assertForbidden();
        $this->actingAs($operator)->put("/flujo-caja/{$income->id}", ['amount' => 1750] + self::PAYLOAD)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('manual_cash_incomes', ['id' => $income->id, 'amount' => 1750]);

        $this->actingAs($operator)->delete("/flujo-caja/{$income->id}")->assertForbidden();
        $this->actingAs($admin)->delete("/flujo-caja/{$income->id}")->assertSessionHas('success');
        $this->assertDatabaseCount('manual_cash_incomes', 0);
    }

    public function test_incomes_of_another_company_are_out_of_reach(): void
    {
        [$admin] = $this->memberOf();
        $other = Company::factory()->create();
        $foreign = ManualCashIncome::query()->create(['company_id' => $other->id, 'user_id' => $admin->id] + self::PAYLOAD);

        $this->actingAs($admin)->get('/flujo-caja')->assertInertia(fn (Assert $page) => $page->has('records.data', 0)->where('totals.manual', 0));
        $this->actingAs($admin)->put("/flujo-caja/{$foreign->id}", self::PAYLOAD)->assertNotFound();
        $this->actingAs($admin)->delete("/flujo-caja/{$foreign->id}")->assertNotFound();
    }
}
