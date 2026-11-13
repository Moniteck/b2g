<?php

namespace Tests\Feature\Finance;

use App\Models\Company;
use App\Models\KpiGoal;
use App\Models\Quote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * KPI y metas: valores reales, meta que cubre el período, administración de
 * metas solo por el administrador, validación y aislamiento entre empresas.
 */
class KpisTest extends TestCase
{
    use RefreshDatabase;

    private const PAYLOAD = ['metric' => 'cobros', 'target' => 5000, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31'];

    public function test_screen_shows_actuals_against_the_covering_goal(): void
    {
        [$viewer, $company] = $this->memberOf(role: 'viewer');
        Quote::factory()->for($company)->won()->create(['quoted_at' => '2026-08-10', 'total' => 300, 'awarded_amount' => 300]);
        Quote::factory()->for($company)->lost()->create(['quoted_at' => '2026-08-11']);
        Quote::factory()->for($company)->lost()->create(['quoted_at' => '2026-08-12']);
        Quote::factory()->for($company)->lost()->create(['quoted_at' => '2026-08-13']);
        KpiGoal::query()->create(['company_id' => $company->id, 'metric' => 'tasa_adjudicacion', 'target' => 50, 'period_start' => '2026-01-01', 'period_end' => '2026-12-31']);

        $this->actingAs($viewer)->get('/kpis?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Finance/Kpis')
                ->where('can.manage', false)
                ->where('kpis.1.metric', 'adjudicaciones')
                ->where('kpis.1.actual', 300)
                ->where('kpis.3.metric', 'tasa_adjudicacion')
                ->where('kpis.3.actual', 25)
                ->where('kpis.3.goal', 50)
                ->where('kpis.3.unit', 'percent')
                ->where('kpis.2.goal', null)
                ->has('goals.data', 1));
    }

    public function test_only_company_administrators_manage_goals(): void
    {
        [$admin, $company] = $this->memberOf();
        [$operator] = $this->memberOf($company, 'operator');
        [$viewer] = $this->memberOf($company, 'viewer');

        $this->actingAs($viewer)->post('/kpis', self::PAYLOAD)->assertForbidden();
        $this->actingAs($operator)->post('/kpis', self::PAYLOAD)->assertForbidden();
        $this->actingAs($admin)->post('/kpis', self::PAYLOAD)->assertRedirect()->assertSessionHas('success');

        $goal = KpiGoal::withoutGlobalScopes()->sole();
        $this->assertSame($company->id, $goal->company_id);

        $this->actingAs($operator)->put("/kpis/{$goal->id}", ['target' => 1] + self::PAYLOAD)->assertForbidden();
        $this->actingAs($operator)->delete("/kpis/{$goal->id}")->assertForbidden();

        $this->actingAs($admin)->put("/kpis/{$goal->id}", ['target' => 7500] + self::PAYLOAD)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('kpi_goals', ['id' => $goal->id, 'target' => 7500]);

        $this->actingAs($admin)->delete("/kpis/{$goal->id}")->assertSessionHas('success');
        $this->assertDatabaseCount('kpi_goals', 0);
    }

    public function test_saving_the_same_goal_twice_updates_it(): void
    {
        [$admin] = $this->memberOf();

        $this->actingAs($admin)->post('/kpis', self::PAYLOAD)->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/kpis', ['target' => 9000] + self::PAYLOAD)->assertSessionHasNoErrors();

        $this->assertDatabaseCount('kpi_goals', 1);
        $this->assertDatabaseHas('kpi_goals', ['target' => 9000]);
    }

    public function test_goal_validation_messages(): void
    {
        [$admin, $company] = $this->memberOf();

        $this->actingAs($admin)->post('/kpis', ['metric' => 'garantias', 'target' => -1, 'period_start' => '2026-08-31', 'period_end' => '2026-08-01'])
            ->assertSessionHasErrors(['metric', 'target', 'period_end' => 'El fin del período debe ser igual o posterior al inicio.']);

        $this->actingAs($admin)->post('/kpis', ['metric' => 'tasa_adjudicacion', 'target' => 120] + self::PAYLOAD)
            ->assertSessionHasErrors(['target' => 'La meta de un porcentaje no puede pasar de 100.']);

        $this->actingAs($admin)->post('/kpis', ['period_start' => '2025-01-01', 'period_end' => '2026-08-31'] + self::PAYLOAD)
            ->assertSessionHasErrors(['period_end' => 'El período de una meta no puede pasar de un año.']);

        $first = KpiGoal::query()->create(['company_id' => $company->id, 'updated_by' => $admin->id] + self::PAYLOAD);
        $second = KpiGoal::query()->create(['company_id' => $company->id, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30'] + array_diff_key(self::PAYLOAD, ['period_start' => 1, 'period_end' => 1]));

        // Editar la de septiembre al período de agosto chocaría con la existente: 422, no 500.
        $this->actingAs($admin)->put("/kpis/{$second->id}", self::PAYLOAD)
            ->assertSessionHasErrors(['metric' => 'Ya hay una meta de este indicador para el mismo período.']);
        $this->assertNotNull($first->fresh());
    }

    public function test_goals_of_another_company_are_out_of_reach(): void
    {
        [$admin] = $this->memberOf();
        $other = Company::factory()->create();
        $foreign = KpiGoal::query()->create(['company_id' => $other->id] + self::PAYLOAD);

        $this->actingAs($admin)->get('/kpis')->assertInertia(fn (Assert $page) => $page->has('goals.data', 0));
        $this->actingAs($admin)->put("/kpis/{$foreign->id}", ['target' => 1] + self::PAYLOAD)->assertNotFound();
        $this->actingAs($admin)->delete("/kpis/{$foreign->id}")->assertNotFound();

        // La misma meta en la empresa propia no choca con la de la otra empresa.
        $this->actingAs($admin)->post('/kpis', self::PAYLOAD)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('kpi_goals', 2);
    }
}
