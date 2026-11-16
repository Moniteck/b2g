<?php

namespace Tests\Feature\Tools;

use App\Models\Company;
use App\Models\Competitor;
use App\Models\Quote;
use App\Models\QuoteParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CompetitorTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_creates_and_updates_a_competitor(): void
    {
        [$operator, $company] = $this->memberOf(null, 'operator');

        $this->actingInCompany($operator, $company)->post('/inteligencia-competitiva', [
            'name' => 'Distribuidora Rival', 'tax_id' => '08019000000001', 'wins' => 3, 'losses' => 1,
            'institutions' => ['SESAL', ' SESAL ', 'ENEE'],
        ])->assertSessionHasNoErrors();

        $competitor = Competitor::sole();
        $this->assertSame(['SESAL', 'ENEE'], $competitor->institutions);

        $this->actingInCompany($operator, $company)->put("/inteligencia-competitiva/{$competitor->id}", [
            'name' => 'Distribuidora Rival', 'tax_id' => '08019000000001', 'wins' => 4, 'losses' => 1,
        ])->assertSessionHasNoErrors();

        $this->actingInCompany($operator, $company)->get('/inteligencia-competitiva')
            ->assertInertia(fn (Assert $page) => $page->component('Competitors/Index')
                ->where('records.data.0.wins', 4)->where('records.data.0.win_rate', 80)
                ->where('can.delete', false));
    }

    public function test_validation_and_duplicates(): void
    {
        [$operator, $company] = $this->memberOf(null, 'operator');
        Competitor::create(['name' => 'Existente', 'tax_id' => '1', 'wins' => 0, 'losses' => 0]);

        $this->actingInCompany($operator, $company)->post('/inteligencia-competitiva', ['name' => 'Existente', 'tax_id' => '1', 'wins' => -1])
            ->assertSessionHasErrors(['name', 'tax_id', 'wins', 'losses']);
    }

    public function test_viewer_cannot_write_and_only_root_deletes_the_shared_catalog(): void
    {
        [$admin, $company] = $this->memberOf();
        [$viewer] = $this->memberOf($company, 'viewer');
        $competitor = Competitor::create(['name' => 'Rival', 'wins' => 0, 'losses' => 0]);

        $this->actingInCompany($viewer, $company)->get('/inteligencia-competitiva')->assertOk();
        $this->actingInCompany($viewer, $company)->post('/inteligencia-competitiva', ['name' => 'Otro', 'wins' => 0, 'losses' => 0])->assertForbidden();
        $this->actingInCompany($admin, $company)->delete("/inteligencia-competitiva/{$competitor->id}")->assertForbidden();

        $root = User::factory()->root()->create();
        $this->actingInCompany($root, $company)->delete("/inteligencia-competitiva/{$competitor->id}")->assertSessionHas('success');
        $this->assertModelMissing($competitor);
    }

    public function test_participations_only_come_from_the_active_company(): void
    {
        [$admin, $company] = $this->memberOf();
        $other = Company::factory()->create();
        $competitor = Competitor::create(['name' => 'Rival SA', 'tax_id' => '0801', 'wins' => 0, 'losses' => 0]);

        $ours = $this->inCompany($company, fn () => Quote::factory()->for($company)->create(['winner_name' => 'Rival SA']));
        $theirs = $this->inCompany($other, fn () => Quote::factory()->for($other)->create());

        QuoteParticipant::factory()->for($ours)->create(['name' => 'Rival SA', 'tax_id' => '0801', 'amount' => 1000]);
        QuoteParticipant::factory()->for($ours)->create(['name' => 'rival sa', 'tax_id' => null, 'amount' => 2000]);
        QuoteParticipant::factory()->for($ours)->create(['name' => 'Otro', 'tax_id' => '9999']);
        QuoteParticipant::factory()->for($theirs)->create(['name' => 'Rival SA', 'tax_id' => '0801']);

        $this->actingInCompany($admin, $company)->getJson("/inteligencia-competitiva/{$competitor->id}/participaciones")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('summary.count', 2)
            ->assertJsonPath('summary.won', 2);
    }
}
