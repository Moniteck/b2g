<?php

namespace Tests\Feature\Finance;

use App\Models\BudgetMovement;
use App\Models\CollectionInvoice;
use App\Models\Company;
use App\Models\Quote;
use App\Models\RevolvingBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Presupuesto revolvente: uno por empresa, saldo recalculado, referencia única
 * por empresa, sincronización idempotente, permisos y exportes.
 */
class RevolvingBudgetTest extends TestCase
{
    use RefreshDatabase;

    private function movement(array $overrides = []): array
    {
        return $overrides + ['moved_at' => '2026-10-05', 'type' => 'Egreso', 'concept' => 'Compra de materiales', 'amount' => 250];
    }

    public function test_each_company_has_its_own_budget_and_balance(): void
    {
        [$adminA, $companyA] = $this->memberOf();
        [$adminB, $companyB] = $this->memberOf();

        $this->actingAs($adminA)->post('/presupuesto-revolvente/inicializar', ['initial_amount' => 1000])->assertSessionHas('success');
        $this->actingAs($adminA)->post('/presupuesto-revolvente', $this->movement())->assertSessionHasNoErrors();
        $this->actingAs($adminA)->post('/presupuesto-revolvente', $this->movement(['type' => 'Ingreso', 'amount' => 100, 'moved_at' => '2026-10-06']))->assertSessionHasNoErrors();

        $this->actingAs($adminB)->get('/presupuesto-revolvente')->assertInertia(fn (Assert $page) => $page
            ->component('Finance/RevolvingBudget')
            ->where('budget.current_balance', 0)
            ->has('records.data', 0));

        $this->actingAs($adminA)->get('/presupuesto-revolvente')->assertInertia(fn (Assert $page) => $page
            ->where('budget.initial_amount', 1000)
            ->where('budget.current_balance', 850)
            ->where('stats.expense', 250)
            ->where('stats.income', 100)
            ->has('records.data', 2)
            ->where('records.data.0.balance_after', 850));

        $this->assertSame(2, RevolvingBudget::withoutGlobalScopes()->count());
        $this->assertDatabaseHas('revolving_budgets', ['company_id' => $companyA->id, 'current_balance' => 850]);
        $this->assertDatabaseHas('revolving_budgets', ['company_id' => $companyB->id, 'current_balance' => 0]);
    }

    public function test_validation_and_reference_unique_per_company(): void
    {
        [$admin] = $this->memberOf();
        [$otherAdmin] = $this->memberOf();

        $this->actingAs($otherAdmin)->post('/presupuesto-revolvente', $this->movement(['reference' => 'CHQ-1']))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/presupuesto-revolvente', $this->movement(['reference' => 'CHQ-1']))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/presupuesto-revolvente', $this->movement(['reference' => 'CHQ-1']))
            ->assertSessionHasErrors(['reference' => 'Ya hay un movimiento con esa referencia en la empresa.']);

        $this->actingAs($admin)->post('/presupuesto-revolvente', ['type' => 'Ingreso proyectado', 'amount' => 0, 'concept' => '', 'moved_at' => 'ayer'])
            ->assertSessionHasErrors(['type', 'amount' => 'El monto debe ser mayor que cero.', 'concept', 'moved_at']);
    }

    public function test_sync_is_idempotent_and_drops_sources_that_no_longer_apply(): void
    {
        [$admin, $company] = $this->memberOf();
        $quote = Quote::factory()->for($company)->won()->create(['total' => 400, 'awarded_amount' => 400]);
        CollectionInvoice::query()->create(['company_id' => $company->id, 'number' => 'FAC-9', 'concept' => 'Servicio', 'amount' => 1000, 'issued_at' => '2026-10-01', 'due_at' => '2026-10-15', 'paid_at' => '2026-10-10', 'status' => 'Pagada']);
        Quote::factory()->for(Company::factory()->create())->won()->create(['total' => 999, 'awarded_amount' => 999]);

        $this->actingAs($admin)->post('/presupuesto-revolvente/sincronizar')->assertSessionHas('success');
        $this->actingAs($admin)->post('/presupuesto-revolvente/sincronizar')->assertSessionHas('success', 'Las fuentes ya estaban sincronizadas.');

        $this->assertSame(2, BudgetMovement::withoutGlobalScopes()->count());
        $this->assertDatabaseHas('revolving_budgets', ['company_id' => $company->id, 'current_balance' => 600]);

        // La cotización deja de estar ganada: su egreso sale en la próxima sincronización.
        $quote->forceFill(['outcome' => 'Pendiente'])->save();
        $this->actingAs($admin)->post('/presupuesto-revolvente/sincronizar');
        $this->assertDatabaseMissing('budget_movements', ['source_type' => 'quote', 'source_id' => $quote->id]);
        $this->assertDatabaseHas('revolving_budgets', ['company_id' => $company->id, 'current_balance' => 1000]);

        $synced = BudgetMovement::withoutGlobalScopes()->where('synchronized', true)->sole();
        $this->actingAs($admin)->delete("/presupuesto-revolvente/{$synced->id}")->assertSessionHas('error');
        $this->assertDatabaseHas('budget_movements', ['id' => $synced->id]);
    }

    public function test_roles_isolation_and_exports(): void
    {
        [$admin, $company] = $this->memberOf();
        [$operator] = $this->memberOf($company, 'operator');
        [$viewer] = $this->memberOf($company, 'viewer');
        [$foreignAdmin] = $this->memberOf();

        $this->actingAs($viewer)->post('/presupuesto-revolvente', $this->movement())->assertForbidden();
        $this->actingAs($viewer)->post('/presupuesto-revolvente/sincronizar')->assertForbidden();
        $this->actingAs($viewer)->post('/presupuesto-revolvente/inicializar', ['initial_amount' => 5])->assertForbidden();
        $this->actingAs($operator)->post('/presupuesto-revolvente', $this->movement(['concept' => 'Viáticos']))->assertSessionHasNoErrors();

        $movement = BudgetMovement::withoutGlobalScopes()->sole();
        $this->actingAs($operator)->delete("/presupuesto-revolvente/{$movement->id}")->assertForbidden();
        $this->actingAs($foreignAdmin)->delete("/presupuesto-revolvente/{$movement->id}")->assertNotFound();

        $this->actingAs($viewer)->get('/presupuesto-revolvente/export/pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($viewer)->get('/presupuesto-revolvente/export/xlsx?filter=expense')->assertOk()->assertDownload();

        $this->actingAs($admin)->delete("/presupuesto-revolvente/{$movement->id}")->assertSessionHas('success');
        $this->assertDatabaseCount('budget_movements', 0);
        $this->assertDatabaseHas('revolving_budgets', ['company_id' => $company->id, 'current_balance' => 0]);
    }
}
