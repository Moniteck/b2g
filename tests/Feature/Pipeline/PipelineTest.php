<?php

namespace Tests\Feature\Pipeline;

use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractDelivery;
use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\Quote;
use App\Models\QuoteParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Pipeline (Fase 8): tablero, transiciones, participantes, resultado, reversión, roles y aislamiento.
 */
class PipelineTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private User $operator;

    private User $viewer;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        [$this->admin, $this->company] = $this->memberOf(role: 'admin');
        $this->company->forceFill(['name' => 'CAP Honduras'])->save();
        [$this->operator] = $this->memberOf($this->company, 'operator');
        [$this->viewer] = $this->memberOf($this->company, 'viewer');
        $this->institution = Institution::factory()->create(['name' => 'Secretaría de Salud']);
    }

    private function as(User $user): static
    {
        return $this->actingInCompany($user, $this->company);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function opportunity(string $stage = 'prospecto', array $attributes = [], ?Company $company = null): Opportunity
    {
        $company ??= $this->company;

        return $this->inCompany($company, fn () => Opportunity::factory()->for($company)->stage($stage)->create($attributes + [
            'institution_id' => $this->institution->id,
            'amount' => 50000,
        ]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function quoteFor(Opportunity $opportunity, array $attributes = []): Quote
    {
        return $this->inCompany($this->company, fn () => Quote::factory()->for($this->company)->create($attributes + [
            'institution_id' => $this->institution->id,
            'opportunity_id' => $opportunity->id,
            'status' => 'Emitida',
            'subtotal' => 40000,
            'tax' => 6000,
            'total' => 46000,
        ]));
    }

    private function move(Opportunity $opportunity, string $stage, ?User $user = null)
    {
        return $this->as($user ?? $this->operator)->patchJson("/pipeline/{$opportunity->id}/etapa", ['stage' => $stage]);
    }

    private function saveOurOffer(Opportunity $opportunity, float $amount = 48000, array $others = []): void
    {
        $this->as($this->operator)->putJson("/oportunidades/{$opportunity->id}/participantes", ['participants' => [
            ['name' => 'CAP Honduras', 'amount' => $amount, 'is_ours' => true],
            ...$others,
        ]])->assertOk();
    }

    // ───────────────────────────── Tablero ─────────────────────────────

    public function test_board_lists_all_active_opportunities_with_column_totals(): void
    {
        foreach (range(1, 35) as $index) {
            $this->opportunity('prospecto', ['amount' => 1000]);
        }
        $this->opportunity('oportunidad', ['amount' => 2500]);
        $this->opportunity('no_adjudicado', ['awarded_at' => now()->subDays(10)->toDateString(), 'winner_name' => 'Rival S.A.', 'awarded_amount' => 900]);
        $this->opportunity('adjudicado', ['awarded_at' => now()->subDays(200)->toDateString(), 'awarded_amount' => 7000]);

        $this->as($this->viewer)->get('/pipeline')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Pipeline/Index')
            ->has('stages', 5)
            ->where('stages.0.id', 'prospecto')
            ->where('stages.4.id', 'no_adjudicado')
            ->has('opportunities', 37) // 35 + 1 activas + 1 cerrada reciente; la cerrada de hace 200 días se colapsa
            ->where('summary.prospecto.count', 35)
            ->where('summary.prospecto.amount', 35000)
            ->where('summary.adjudicado.count', 1)
            ->where('summary.adjudicado.amount', 7000)
            ->where('can.create', false)
            ->where('can.update', false));

        $this->as($this->admin)->get('/pipeline?cerradas=1')->assertInertia(fn (Assert $page) => $page
            ->has('opportunities', 38)
            ->where('can.create', true)
            ->where('can.revert', true));
    }

    public function test_board_filters_by_search_owner_institution_and_close_range(): void
    {
        $other = Institution::factory()->create(['name' => 'Hospital Escuela']);
        $this->opportunity('prospecto', ['title' => 'Compra de tóner', 'owner_id' => $this->operator->id, 'expected_close_date' => '2026-12-01']);
        $this->opportunity('prospecto', ['title' => 'Mobiliario', 'institution_id' => $other->id, 'expected_close_date' => '2027-02-01']);

        $this->as($this->admin)->get('/pipeline?search=tóner')->assertInertia(fn (Assert $page) => $page->has('opportunities', 1)->where('opportunities.0.title', 'Compra de tóner'));
        $this->as($this->admin)->get("/pipeline?owner_id={$this->operator->id}")->assertInertia(fn (Assert $page) => $page->has('opportunities', 1));
        $this->as($this->admin)->get("/pipeline?institution_id={$other->id}")->assertInertia(fn (Assert $page) => $page->has('opportunities', 1)->where('opportunities.0.title', 'Mobiliario')->where('filterOptions.institution.name', 'Hospital Escuela'));
        $this->as($this->admin)->get('/pipeline?close_from=2027-01-01&close_to=2027-03-01')->assertInertia(fn (Assert $page) => $page->has('opportunities', 1)->where('summary.prospecto.count', 1));
    }

    public function test_detail_url_opens_the_board_with_the_opportunity_focused_and_returns_json_detail(): void
    {
        $opportunity = $this->opportunity('oportunidad', ['title' => 'Equipo médico']);

        $this->as($this->viewer)->get("/pipeline/{$opportunity->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Pipeline/Index')
            ->where('focus', $opportunity->id));

        $this->as($this->viewer)->getJson("/pipeline/{$opportunity->id}")->assertOk()
            ->assertJsonPath('data.title', 'Equipo médico')
            ->assertJsonPath('data.stage', 'oportunidad')
            ->assertJsonPath('form.institution_id', $this->institution->id)
            ->assertJsonPath('can.update', false)
            ->assertJsonPath('can.delete', false);
    }

    // ───────────────────────────── Transiciones ─────────────────────────────

    public function test_valid_transitions_advance_and_go_back_one_stage(): void
    {
        $opportunity = $this->opportunity('prospecto');

        $this->move($opportunity, 'oportunidad')->assertOk()->assertJsonPath('card.stage', 'oportunidad');
        $this->move($opportunity, 'prospecto')->assertOk()->assertJsonPath('card.stage', 'prospecto');
        $this->move($opportunity, 'prospecto')->assertOk()->assertJsonPath('message', 'La oportunidad ya estaba en Prospecto.');

        $this->assertDatabaseHas('audit_logs', ['action' => 'oportunidad.etapa', 'resource_id' => $opportunity->id]);
    }

    public function test_invalid_transitions_are_rejected_with_a_message(): void
    {
        $prospect = $this->opportunity('prospecto');
        $this->move($prospect, 'oferta_presentada')->assertStatus(422)->assertJsonValidationErrors('stage');
        $this->move($prospect, 'adjudicado')->assertStatus(422)->assertJsonValidationErrors('stage');

        $presented = $this->opportunity('oferta_presentada');
        $this->move($presented, 'prospecto')->assertStatus(422);
        $this->move($presented, 'adjudicado')->assertStatus(422)->assertJsonPath('errors.stage.0', fn ($message) => str_contains($message, 'Ganada'));
        $this->move($presented, 'no_adjudicado')->assertStatus(422)->assertJsonPath('errors.stage.0', fn ($message) => str_contains($message, 'Perdida'));

        $won = $this->opportunity('adjudicado');
        $this->move($won, 'prospecto')->assertStatus(422)->assertJsonPath('errors.stage.0', fn ($message) => str_contains($message, 'revertir'));
        $this->move($won, 'oferta_presentada', $this->admin)->assertStatus(422);

        $this->move($prospect, 'cualquiera')->assertStatus(422);

        $this->assertSame('prospecto', $prospect->fresh()->stage);
        $this->assertSame('oferta_presentada', $presented->fresh()->stage);
        $this->assertSame('adjudicado', $won->fresh()->stage);
    }

    public function test_moving_to_offer_presented_requires_our_offer_with_amount(): void
    {
        $opportunity = $this->opportunity('oportunidad');

        $this->move($opportunity, 'oferta_presentada')->assertStatus(422)->assertJsonPath('errors.stage.0', fn ($message) => str_contains($message, 'nuestra oferta'));

        $this->saveOurOffer($opportunity, 0, [['name' => 'Rival S.A.', 'amount' => 45000]]);
        $this->move($opportunity, 'oferta_presentada')->assertStatus(422)->assertJsonPath('errors.stage.0', fn ($message) => str_contains($message, 'monto'));

        $this->saveOurOffer($opportunity, 48000, [['name' => 'Rival S.A.', 'amount' => 45000]]);
        $this->move($opportunity, 'oferta_presentada')->assertOk()
            ->assertJsonPath('card.stage', 'oferta_presentada')
            ->assertJsonPath('card.participantsCount', 2);
    }

    public function test_moving_to_offer_presented_presents_the_linked_quote(): void
    {
        $opportunity = $this->opportunity('oportunidad');
        $quote = $this->quoteFor($opportunity, ['status' => 'Borrador']);
        $this->saveOurOffer($opportunity);

        $this->move($opportunity, 'oferta_presentada')->assertOk()
            ->assertJsonPath('card.quote.number', $quote->number)
            ->assertJsonPath('card.quote.status', 'Presentada');

        $this->assertSame('Presentada', $quote->fresh()->status);
        $this->assertSame($quote->id, $opportunity->fresh()->quote_id);
        $this->assertSame(1, QuoteParticipant::query()->where('is_ours', true)->count());
    }

    // ───────────────────────────── Resultado ─────────────────────────────

    public function test_won_without_quote_creates_client_and_contract_once(): void
    {
        $opportunity = $this->opportunity('oferta_presentada');

        $payload = ['awarded_amount' => 47000, 'awarded_at' => now()->toDateString()];
        $this->as($this->operator)->postJson("/oportunidades/{$opportunity->id}/ganada", $payload)->assertOk()->assertJsonPath('result.already_applied', false);
        $this->as($this->operator)->postJson("/oportunidades/{$opportunity->id}/ganada", $payload)->assertOk()->assertJsonPath('result.already_applied', true);

        $this->assertSame(1, Client::query()->count());
        $this->assertSame(1, Contract::query()->count());
        $this->assertSame('adjudicado', $opportunity->fresh()->stage);

        $this->as($this->admin)->getJson("/pipeline/{$opportunity->id}")->assertOk()
            ->assertJsonPath('outcome.result', 'Ganada')
            ->assertJsonPath('outcome.awardedAmount', 47000)
            ->assertJsonPath('outcome.contract.number', Contract::query()->sole()->number)
            ->assertJsonPath('outcome.client.name', 'Secretaría de Salud')
            ->assertJsonPath('can.delete', false);
    }

    public function test_won_or_lost_from_an_earlier_stage_is_rejected(): void
    {
        $opportunity = $this->opportunity('prospecto');

        $this->as($this->operator)->postJson("/oportunidades/{$opportunity->id}/ganada", ['awarded_amount' => 47000, 'awarded_at' => now()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('stage');
        $this->as($this->operator)->postJson("/oportunidades/{$opportunity->id}/perdida", ['winner_name' => 'Competidor', 'winner_amount' => 40000])
            ->assertUnprocessable()->assertJsonValidationErrors('stage');

        $this->assertSame('prospecto', $opportunity->fresh()->stage);
        $this->assertSame(0, Contract::query()->count());
    }

    public function test_won_with_quote_syncs_the_quote(): void
    {
        $opportunity = $this->opportunity('oportunidad');
        $quote = $this->quoteFor($opportunity);
        $this->saveOurOffer($opportunity);
        $this->move($opportunity, 'oferta_presentada')->assertOk();

        $this->as($this->operator)->postJson("/oportunidades/{$opportunity->id}/ganada", ['awarded_amount' => 46000, 'awarded_at' => now()->toDateString()])->assertOk();

        $this->assertSame('Ganada', $quote->fresh()->outcome);
        $this->assertSame($quote->id, Contract::query()->sole()->quote_id);
        $this->assertSame('adjudicado', $opportunity->fresh()->stage);
    }

    public function test_lost_requires_winner_and_amount_and_the_card_shows_them(): void
    {
        $opportunity = $this->opportunity('oferta_presentada');
        $this->saveOurOffer($opportunity, 48000);

        $this->as($this->operator)->postJson("/oportunidades/{$opportunity->id}/perdida", [])->assertStatus(422)
            ->assertJsonValidationErrors(['winner_name', 'winner_amount']);

        $this->as($this->operator)->postJson("/oportunidades/{$opportunity->id}/perdida", ['winner_name' => 'Distribuidora Rival', 'winner_amount' => 45000, 'lost_reason' => 'Precio'])->assertOk();

        $this->assertSame('no_adjudicado', $opportunity->fresh()->stage);

        $this->as($this->viewer)->get('/pipeline')->assertInertia(fn (Assert $page) => $page
            ->where('opportunities.0.winner.name', 'Distribuidora Rival')
            ->where('opportunities.0.winner.amount', 45000)
            ->where('opportunities.0.participantsCount', 2));

        $this->as($this->viewer)->getJson("/pipeline/{$opportunity->id}")->assertOk()
            ->assertJsonPath('outcome.result', 'Perdida')
            ->assertJsonPath('outcome.winnerName', 'Distribuidora Rival')
            ->assertJsonPath('outcome.ourAmount', 48000)
            ->assertJsonPath('outcome.difference', 3000)
            ->assertJsonPath('outcome.lostReason', 'Precio');
    }

    public function test_revert_is_admin_only_and_blocked_by_contract_activity(): void
    {
        $opportunity = $this->opportunity('oferta_presentada');
        $this->as($this->operator)->postJson("/oportunidades/{$opportunity->id}/ganada", ['awarded_amount' => 47000, 'awarded_at' => now()->toDateString()])->assertOk();

        $this->as($this->operator)->postJson("/oportunidades/{$opportunity->id}/revertir", ['reason' => 'Error de registro'])->assertForbidden();
        $this->as($this->admin)->postJson("/oportunidades/{$opportunity->id}/revertir", ['reason' => 'Error de registro'])->assertOk();
        $this->assertSame('oferta_presentada', $opportunity->fresh()->stage);
        $this->assertSame('Cancelado', Contract::query()->sole()->status);

        $this->as($this->operator)->postJson("/oportunidades/{$opportunity->id}/ganada", ['awarded_amount' => 47000, 'awarded_at' => now()->toDateString()])->assertOk();
        ContractDelivery::factory()->create(['contract_id' => Contract::query()->sole()->id]);

        $this->as($this->admin)->getJson("/pipeline/{$opportunity->id}")->assertJsonPath('outcome.revertBlocker', fn ($message) => str_contains((string) $message, 'No se puede revertir'));
        $this->as($this->admin)->postJson("/oportunidades/{$opportunity->id}/revertir", ['reason' => 'Error de registro'])->assertStatus(422);
        $this->assertSame('adjudicado', $opportunity->fresh()->stage);
    }

    public function test_participants_belong_to_the_opportunity(): void
    {
        $opportunity = $this->opportunity('oportunidad');
        $this->saveOurOffer($opportunity, 48000, [['name' => 'Rival S.A.', 'amount' => 45000]]);

        $this->as($this->viewer)->getJson("/oportunidades/{$opportunity->id}/participantes")->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('summary.ours_position', 2)
            ->assertJsonPath('summary.difference', 3000)
            ->assertJsonPath('can.edit', false);

        $this->assertSame(2, QuoteParticipant::query()->where('opportunity_id', $opportunity->id)->whereNull('quote_id')->count());
        $this->as($this->viewer)->putJson("/oportunidades/{$opportunity->id}/participantes", ['participants' => []])->assertForbidden();
    }

    // ───────────────────────────── Crear, editar, eliminar ─────────────────────────────

    public function test_operator_creates_and_edits_an_opportunity(): void
    {
        $this->as($this->operator)->post('/pipeline', [
            'title' => 'Suministro de papelería',
            'institution_id' => $this->institution->id,
            'amount' => 120000,
            'probability' => 40,
            'stage' => 'oportunidad',
            'expected_close_date' => '2026-12-15',
            'owner_id' => $this->admin->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $opportunity = Opportunity::query()->sole();
        $this->assertSame($this->company->id, $opportunity->company_id);
        $this->assertSame('oportunidad', $opportunity->stage);
        $this->assertSame($this->admin->id, $opportunity->owner_id);

        $this->as($this->operator)->put("/pipeline/{$opportunity->id}", [
            'title' => 'Suministro de papelería 2027',
            'institution_id' => $this->institution->id,
            'amount' => 130000,
            'probability' => 50,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Suministro de papelería 2027', $opportunity->fresh()->title);
        $this->assertSame('oportunidad', $opportunity->fresh()->stage);
        $this->assertDatabaseHas('audit_logs', ['action' => 'oportunidad.actualizada', 'resource_id' => $opportunity->id]);
    }

    public function test_create_validation(): void
    {
        [$stranger] = $this->memberOf(role: 'admin');

        $this->as($this->operator)->post('/pipeline', ['title' => '', 'amount' => -1, 'probability' => 150, 'stage' => 'adjudicado', 'owner_id' => $stranger->id])
            ->assertSessionHasErrors(['title', 'institution_id', 'amount', 'probability', 'stage', 'owner_id']);

        $opportunity = $this->opportunity('prospecto');
        $this->as($this->operator)->put("/pipeline/{$opportunity->id}", ['title' => 'X', 'institution_id' => $this->institution->id, 'amount' => 1, 'probability' => 1, 'stage' => 'adjudicado'])
            ->assertSessionHasErrors('stage');

        $this->assertSame(1, Opportunity::query()->count());
    }

    public function test_roles(): void
    {
        $opportunity = $this->opportunity('prospecto');
        $data = ['title' => 'Nueva', 'institution_id' => $this->institution->id, 'amount' => 1, 'probability' => 10];

        $this->as($this->viewer)->post('/pipeline', $data)->assertForbidden();
        $this->as($this->viewer)->put("/pipeline/{$opportunity->id}", $data)->assertForbidden();
        $this->move($opportunity, 'oportunidad', $this->viewer)->assertForbidden();
        $this->as($this->viewer)->postJson("/oportunidades/{$opportunity->id}/ganada", ['awarded_amount' => 1, 'awarded_at' => now()->toDateString()])->assertForbidden();
        $this->as($this->viewer)->delete("/pipeline/{$opportunity->id}")->assertForbidden();
        $this->as($this->operator)->delete("/pipeline/{$opportunity->id}")->assertForbidden();

        $this->as($this->admin)->delete("/pipeline/{$opportunity->id}")->assertRedirect('/pipeline');
        $this->assertDatabaseMissing('opportunities', ['id' => $opportunity->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'oportunidad.eliminada', 'resource_id' => $opportunity->id]);
    }

    public function test_an_opportunity_with_outcome_cannot_be_deleted(): void
    {
        $opportunity = $this->opportunity('no_adjudicado', ['winner_name' => 'Rival', 'awarded_amount' => 10]);

        $this->as($this->admin)->delete("/pipeline/{$opportunity->id}")->assertRedirect()->assertSessionHas('error');
        $this->assertDatabaseHas('opportunities', ['id' => $opportunity->id]);
    }

    public function test_other_company_opportunities_are_isolated(): void
    {
        [, $otherCompany] = $this->memberOf(role: 'admin');
        $foreign = $this->opportunity('prospecto', ['title' => 'Ajena'], $otherCompany);
        $this->opportunity('prospecto', ['title' => 'Propia']);

        $this->as($this->admin)->get('/pipeline')->assertInertia(fn (Assert $page) => $page->has('opportunities', 1)->where('opportunities.0.title', 'Propia')->where('summary.prospecto.count', 1));
        $this->as($this->admin)->getJson("/pipeline/{$foreign->id}")->assertNotFound();
        $this->move($foreign, 'oportunidad', $this->admin)->assertNotFound();
        $this->as($this->admin)->put("/pipeline/{$foreign->id}", ['title' => 'X', 'institution_id' => $this->institution->id, 'amount' => 1, 'probability' => 1])->assertNotFound();
        $this->as($this->admin)->getJson("/oportunidades/{$foreign->id}/participantes")->assertNotFound();
        $this->assertSame('prospecto', $foreign->fresh()->stage);
    }

    public function test_exports_follow_the_new_stages(): void
    {
        $this->opportunity('oferta_presentada', ['title' => 'Exportable']);
        $this->opportunity('no_adjudicado', ['winner_name' => 'Rival', 'awarded_amount' => 10]);

        $this->as($this->viewer)->get('/pipeline/export/pdf')->assertOk();
        $this->as($this->viewer)->get('/pipeline/export/xlsx')->assertOk();
    }
}
