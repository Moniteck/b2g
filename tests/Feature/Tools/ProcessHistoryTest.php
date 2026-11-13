<?php

namespace Tests\Feature\Tools;

use App\Models\Company;
use App\Models\Institution;
use App\Models\ProcessHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProcessHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return [
            'process_number' => 'LPN-001-2026',
            'name' => 'Suministro de llantas',
            'institution_id' => Institution::factory()->create()->id,
            'participated' => true,
            'estimated_amount' => 150000,
            'offered_amount' => 140000,
            'result' => 'Adjudicado',
            'published_at' => '2026-01-10',
            'closed_at' => '2026-02-10',
            ...$overrides,
        ];
    }

    public function test_operator_registers_edits_and_lists_a_process(): void
    {
        [$operator, $company] = $this->memberOf(null, 'operator');

        $this->actingInCompany($operator, $company)->post('/historial', $this->payload())
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $record = ProcessHistory::query()->withoutGlobalScopes()->sole();
        $this->assertSame($company->id, $record->company_id);

        $this->actingInCompany($operator, $company)->put("/historial/{$record->id}", $this->payload(['result' => 'No adjudicado', 'cause' => 'Precio']))
            ->assertSessionHasNoErrors();
        $this->assertSame('No adjudicado', $record->fresh()->result);

        $this->actingInCompany($operator, $company)->get('/historial?filter=not_awarded')
            ->assertInertia(fn (Assert $page) => $page->component('ProcessHistory')
                ->has('records.data', 1)
                ->where('records.data.0.cause', 'Precio')
                ->where('counts.participated', 1)
                ->where('can.delete', false));
    }

    public function test_validation_rejects_bad_data(): void
    {
        [$operator, $company] = $this->memberOf(null, 'operator');

        $this->actingInCompany($operator, $company)->post('/historial', $this->payload([
            'process_number' => '', 'result' => 'Ganada a medias', 'closed_at' => '2025-01-01', 'estimated_amount' => -5,
        ]))->assertSessionHasErrors(['process_number', 'result', 'closed_at', 'estimated_amount']);

        $this->assertSame(0, ProcessHistory::query()->withoutGlobalScopes()->count());
    }

    public function test_viewer_reads_but_cannot_write_and_only_admin_deletes(): void
    {
        [$admin, $company] = $this->memberOf();
        [$viewer] = $this->memberOf($company, 'viewer');
        [$operator] = $this->memberOf($company, 'operator');
        $record = $this->inCompany($company, fn () => ProcessHistory::create($this->payload()));

        $this->actingInCompany($viewer, $company)->get('/historial')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.create', false)->where('can.update', false));
        $this->actingInCompany($viewer, $company)->post('/historial', $this->payload())->assertForbidden();
        $this->actingInCompany($viewer, $company)->put("/historial/{$record->id}", $this->payload())->assertForbidden();
        $this->actingInCompany($operator, $company)->delete("/historial/{$record->id}")->assertForbidden();

        $this->actingInCompany($admin, $company)->delete("/historial/{$record->id}")->assertSessionHas('success');
        $this->assertModelMissing($record);
    }

    public function test_records_of_another_company_are_invisible(): void
    {
        [$admin, $company] = $this->memberOf();
        $other = Company::factory()->create();
        $foreign = $this->inCompany($other, fn () => ProcessHistory::create($this->payload(['process_number' => 'AJENO-1'])));

        $this->actingInCompany($admin, $company)->get('/historial')
            ->assertInertia(fn (Assert $page) => $page->has('records.data', 0));
        $this->actingInCompany($admin, $company)->put("/historial/{$foreign->id}", $this->payload())->assertNotFound();
        $this->actingInCompany($admin, $company)->delete("/historial/{$foreign->id}")->assertNotFound();
    }
}
