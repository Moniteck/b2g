<?php

namespace Tests\Feature\Finance;

use App\Models\Company;
use App\Models\Institution;
use App\Models\PoaEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Alertas POA: alta, edición, ejecución idempotente, filtros de alerta,
 * permisos, validación y aislamiento entre empresas.
 */
class PoaTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'project' => 'Equipo de cómputo', 'year' => 2026, 'quarter' => 4, 'probability' => 60,
            'budgeted_amount' => 100000, 'executed_amount' => null, 'estimated_date' => today()->addDays(10)->toDateString(),
            'annual_close_alert' => false, 'status' => 'Identificada',
        ];
    }

    private function entry(Company $company, array $attributes = []): PoaEntry
    {
        return PoaEntry::query()->create($attributes + [
            'company_id' => $company->id, 'project' => 'Partida', 'year' => 2026, 'probability' => 50,
            'budgeted_amount' => 1000, 'executed_amount' => 0, 'status' => 'Identificada',
        ]);
    }

    public function test_operator_registers_and_executes_an_entry(): void
    {
        [$operator, $company] = $this->memberOf(role: 'operator');
        $institution = Institution::factory()->create();

        $this->actingAs($operator)->post('/alertas-poa', $this->payload(['institution_id' => $institution->id]))->assertSessionHas('success');
        $entry = PoaEntry::withoutGlobalScopes()->sole();
        $this->assertSame($company->id, $entry->company_id);

        $this->actingAs($operator)->put("/alertas-poa/{$entry->id}", $this->payload(['status' => 'En seguimiento', 'executed_amount' => 2000]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('poa_entries', ['id' => $entry->id, 'status' => 'En seguimiento', 'executed_amount' => 2000]);

        $this->actingAs($operator)->patch("/alertas-poa/{$entry->id}/ejecutar")->assertSessionHas('success');
        $this->actingAs($operator)->patch("/alertas-poa/{$entry->id}/ejecutar")->assertSessionHas('warning');
        $this->assertDatabaseHas('poa_entries', ['id' => $entry->id, 'status' => 'Ejecutada', 'executed_amount' => 100000]);
    }

    public function test_alert_filter_and_stats(): void
    {
        [$viewer, $company] = $this->memberOf(role: 'viewer');
        $this->entry($company, ['project' => 'Cercana', 'estimated_date' => today()->addDays(5)->toDateString(), 'budgeted_amount' => 1000, 'probability' => 50]);
        $this->entry($company, ['project' => 'Lejana', 'estimated_date' => today()->addDays(90)->toDateString()]);
        $this->entry($company, ['project' => 'Cierre anual', 'estimated_date' => today()->addDays(90)->toDateString(), 'annual_close_alert' => true]);
        $this->entry($company, ['project' => 'Ejecutada', 'estimated_date' => today()->addDays(2)->toDateString(), 'status' => 'Ejecutada', 'executed_amount' => 1000]);
        $this->entry(Company::factory()->create(), ['project' => 'Ajena', 'estimated_date' => today()->addDays(1)->toDateString()]);

        $this->actingAs($viewer)->get('/alertas-poa')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Finance/Poa')
            ->where('stats.count', 4)
            ->where('stats.alerts', 2)
            ->where('stats.budgeted', 4000)
            ->where('stats.execution', 25)
            ->where('can.create', false));

        $this->actingAs($viewer)->get('/alertas-poa?filter=alert')->assertInertia(fn (Assert $page) => $page
            ->has('records.data', 2)
            ->where('records.data', fn ($rows) => collect($rows)->pluck('project')->sort()->values()->all() === ['Cercana', 'Cierre anual']));

        $this->actingAs($viewer)->get('/alertas-poa?filter=closed&year=2026')->assertInertia(fn (Assert $page) => $page
            ->has('records.data', 1)->where('records.data.0.project', 'Ejecutada'));
    }

    public function test_validation_messages(): void
    {
        [$operator] = $this->memberOf(role: 'operator');

        $this->actingAs($operator)->post('/alertas-poa', $this->payload(['status' => 'Inventado', 'probability' => 150, 'executed_amount' => 200000, 'quarter' => 5, 'project' => '']))
            ->assertSessionHasErrors(['status', 'probability', 'quarter', 'project', 'executed_amount' => 'El monto ejecutado no puede superar el presupuestado.']);
    }

    public function test_roles_and_isolation(): void
    {
        [$admin, $company] = $this->memberOf();
        [$operator] = $this->memberOf($company, 'operator');
        [$viewer] = $this->memberOf($company, 'viewer');
        $own = $this->entry($company);
        $foreign = $this->entry(Company::factory()->create());

        $this->actingAs($viewer)->post('/alertas-poa', $this->payload())->assertForbidden();
        $this->actingAs($viewer)->patch("/alertas-poa/{$own->id}/ejecutar")->assertForbidden();
        $this->actingAs($operator)->delete("/alertas-poa/{$own->id}")->assertForbidden();

        $this->actingAs($admin)->put("/alertas-poa/{$foreign->id}", $this->payload())->assertNotFound();
        $this->actingAs($admin)->patch("/alertas-poa/{$foreign->id}/ejecutar")->assertNotFound();
        $this->actingAs($admin)->delete("/alertas-poa/{$foreign->id}")->assertNotFound();

        $this->actingAs($admin)->delete("/alertas-poa/{$own->id}")->assertSessionHas('success');
        $this->assertDatabaseMissing('poa_entries', ['id' => $own->id]);
        $this->assertDatabaseHas('poa_entries', ['id' => $foreign->id]);
    }
}
