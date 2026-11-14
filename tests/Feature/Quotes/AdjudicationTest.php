<?php

namespace Tests\Feature\Quotes;

use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractDelivery;
use App\Models\Opportunity;
use App\Models\Quote;
use App\Models\QuoteParticipant;
use App\Models\Tender;
use App\Services\Business\AdjudicationService;
use Illuminate\Testing\TestResponse;

/**
 * Ganada, Perdida y Revertir: idempotencia, cliente, contrato y efectos sobre la oportunidad.
 */
class AdjudicationTest extends QuotesTestCase
{
    private function win(Quote $quote, float $amount = 11000): TestResponse
    {
        return $this->as($this->operator)->postJson("/cotizaciones/{$quote->id}/ganada", ['awarded_amount' => $amount, 'awarded_at' => now()->toDateString()]);
    }

    public function test_winning_creates_client_and_contract_and_moves_the_opportunity(): void
    {
        $quote = $this->quote();
        $tender = $this->inCompany($this->company, fn () => Tender::factory()->for($this->company)->create(['status' => 'Presentada']));
        $quote->forceFill(['tender_id' => $tender->id])->save();

        $response = $this->win($quote)->assertOk()
            ->assertJsonPath('result.outcome', 'Ganada')
            ->assertJsonPath('result.already_applied', false);

        $quote->refresh();
        $client = Client::query()->sole();
        $contract = Contract::query()->sole();

        $this->assertSame('Ganada', $quote->outcome);
        $this->assertSame('11000.00', $quote->awarded_amount);
        $this->assertSame($client->id, $quote->client_id);
        $this->assertSame('Secretaría de Salud', $client->name);
        $this->assertSame('11000.00', $client->accumulated_amount);
        $this->assertSame('Pendiente de contrato', $contract->status);
        $this->assertSame('11000.00', $contract->awarded_amount);
        $this->assertSame($client->id, $contract->client_id);
        $this->assertSame($quote->id, $contract->quote_id);
        $this->assertSame($quote->opportunity_id, $contract->opportunity_id);
        $this->assertSame($this->institution->id, $contract->institution_id);
        $this->assertSame('adjudicado', Opportunity::query()->find($quote->opportunity_id)->stage);
        $this->assertSame('Adjudicada', $tender->fresh()->status);
        $this->assertTrue(QuoteParticipant::query()->where('is_ours', true)->sole()->is_winner);
        $response->assertJsonPath('result.client.url', "/clientes/{$client->id}")
            ->assertJsonPath('result.contract.number', $contract->number);
        $this->assertDatabaseHas('audit_logs', ['action' => 'adjudicacion.ganada', 'resource_id' => $quote->id]);
    }

    public function test_winning_twice_does_not_duplicate_anything(): void
    {
        $quote = $this->quote();

        $this->win($quote)->assertOk();
        $this->win($quote)->assertOk()->assertJsonPath('result.already_applied', true);

        // Concurrencia simulada: una segunda petición que cargó la cotización antes de la primera
        // (instancia en memoria todavía «Pendiente») pasa por el bloqueo y no repite nada.
        $stale = Quote::query()->find($quote->id)->forceFill(['outcome' => 'Pendiente']);
        $result = app(AdjudicationService::class)->win($stale, ['awarded_amount' => 11000]);

        $this->assertTrue($result->alreadyApplied);
        $this->assertSame(1, Client::query()->count());
        $this->assertSame(1, Contract::query()->count());
        $this->assertSame('11000.00', Client::query()->sole()->accumulated_amount);
    }

    public function test_existing_client_is_reused_and_a_deleted_one_restored(): void
    {
        $dedupe = Client::dedupeKeyFor($this->company->id, $this->institution->id, $this->institution->name);
        $client = $this->inCompany($this->company, fn () => Client::factory()->for($this->company)->create(['dedupe_key' => $dedupe, 'accumulated_amount' => 500, 'institution_id' => $this->institution->id]));
        $client->delete();

        $this->win($this->quote())->assertOk();

        $client = Client::withTrashed()->sole();
        $this->assertFalse($client->trashed());
        $this->assertSame('11500.00', $client->accumulated_amount);
        $this->assertSame('Activo', $client->status);
    }

    public function test_other_live_quotes_of_the_opportunity_become_substituted(): void
    {
        $quote = $this->quote();
        $revision = Quote::factory()->for($this->company)->create(['opportunity_id' => $quote->opportunity_id, 'status' => 'Emitida', 'institution_id' => $this->institution->id]);
        $annulled = Quote::factory()->for($this->company)->create(['opportunity_id' => $quote->opportunity_id, 'status' => 'Anulada']);

        $this->win($quote)->assertOk();

        $this->assertSame('Sustituida', $revision->fresh()->status);
        $this->assertSame('Pendiente', $revision->fresh()->outcome);
        $this->assertSame('Anulada', $annulled->fresh()->status);
    }

    public function test_cannot_win_a_draft_an_annulled_or_a_lost_quote(): void
    {
        $this->win($this->quote(['status' => 'Borrador']))->assertStatus(422)->assertJsonValidationErrors('outcome');
        $this->win($this->quote(['status' => 'Anulada']))->assertStatus(422);
        $lost = $this->quote(['outcome' => 'Perdida']);
        $this->win($lost)->assertStatus(422)->assertJsonValidationErrors('outcome');

        $this->as($this->operator)->postJson("/cotizaciones/{$lost->id}/perdida", ['winner_name' => 'X', 'winner_amount' => 1])
            ->assertOk()->assertJsonPath('result.already_applied', true);
        $won = $this->quote(['outcome' => 'Ganada']);
        $this->as($this->operator)->postJson("/cotizaciones/{$won->id}/perdida", ['winner_name' => 'X', 'winner_amount' => 1])->assertStatus(422);

        $this->assertSame(0, Contract::query()->count());
    }

    public function test_win_requires_amount_and_date(): void
    {
        $this->as($this->operator)->postJson("/cotizaciones/{$this->quote()->id}/ganada", [])
            ->assertStatus(422)->assertJsonValidationErrors(['awarded_amount', 'awarded_at']);
    }

    public function test_losing_requires_winner_and_amount_and_registers_the_winner(): void
    {
        $quote = $this->quote();
        $sibling = Quote::factory()->for($this->company)->create(['opportunity_id' => $quote->opportunity_id, 'status' => 'Emitida']);

        $this->as($this->operator)->postJson("/cotizaciones/{$quote->id}/perdida", [])
            ->assertStatus(422)->assertJsonValidationErrors(['winner_name', 'winner_amount']);

        $this->as($this->operator)->postJson("/cotizaciones/{$quote->id}/perdida", [
            'winner_name' => 'Distribuidora Central', 'winner_tax_id' => '08019999', 'winner_amount' => 9800, 'lost_reason' => 'Precio más bajo',
        ])->assertOk()->assertJsonPath('result.outcome', 'Perdida');

        $quote->refresh();
        $opportunity = Opportunity::query()->find($quote->opportunity_id);
        $winner = QuoteParticipant::query()->where('is_winner', true)->sole();

        $this->assertSame('Perdida', $quote->outcome);
        $this->assertSame('Distribuidora Central', $quote->winner_name);
        $this->assertSame('no_adjudicado', $opportunity->stage);
        $this->assertSame('Distribuidora Central', $opportunity->winner_name);
        $this->assertSame('9800.00', $opportunity->awarded_amount);
        $this->assertSame('Precio más bajo', $opportunity->lost_reason);
        $this->assertSame('9800.00', $winner->amount);
        $this->assertSame($opportunity->id, $winner->opportunity_id);
        $this->assertSame('Perdida', $sibling->fresh()->outcome);
        $this->assertSame(0, Client::query()->count());
    }

    public function test_losing_with_a_chosen_participant(): void
    {
        $quote = $this->quote();
        $rival = QuoteParticipant::factory()->create(['quote_id' => $quote->id, 'opportunity_id' => $quote->opportunity_id, 'name' => 'Rival SA', 'amount' => 9000]);

        $this->as($this->operator)->postJson("/cotizaciones/{$quote->id}/perdida", ['winner_participant_id' => $rival->id, 'winner_amount' => 9000])->assertOk();

        $this->assertTrue($rival->fresh()->is_winner);
        $this->assertSame(1, QuoteParticipant::query()->count());
        $this->assertSame('Rival SA', $quote->fresh()->winner_name);
    }

    public function test_admin_reverts_a_win_contract_cancelled_and_client_amount_discounted(): void
    {
        $quote = $this->quote();
        $this->win($quote, 11000)->assertOk();

        $this->as($this->operator)->postJson("/cotizaciones/{$quote->id}/revertir", ['reason' => 'Error de registro'])->assertForbidden();
        $this->as($this->admin)->postJson("/cotizaciones/{$quote->id}/revertir", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->as($this->admin)->postJson("/cotizaciones/{$quote->id}/revertir", ['reason' => 'Error de registro'])->assertOk();

        $quote->refresh();
        $this->assertSame('Pendiente', $quote->outcome);
        $this->assertSame('Presentada', $quote->status);
        $this->assertNull($quote->awarded_amount);
        $this->assertSame('Cancelado', Contract::query()->sole()->status);
        $this->assertSame('0.00', Client::query()->sole()->accumulated_amount);
        $this->assertSame('oferta_presentada', Opportunity::query()->find($quote->opportunity_id)->stage);
        $this->assertDatabaseHas('audit_logs', ['action' => 'adjudicacion.revertida']);

        // Ganar de nuevo reutiliza el contrato cancelado en lugar de crear otro.
        $this->win($quote, 11000)->assertOk();
        $this->assertSame(1, Contract::query()->count());
        $this->assertSame('Pendiente de contrato', Contract::query()->sole()->status);
        $this->assertSame('11000.00', Client::query()->sole()->accumulated_amount);
    }

    public function test_revert_is_blocked_when_the_contract_has_activity(): void
    {
        $quote = $this->quote();
        $this->win($quote)->assertOk();
        ContractDelivery::factory()->create(['contract_id' => Contract::query()->sole()->id]);

        $this->as($this->admin)->postJson("/cotizaciones/{$quote->id}/revertir", ['reason' => 'Error de registro'])
            ->assertStatus(422)->assertJsonValidationErrors('outcome');

        $this->assertSame('Ganada', $quote->fresh()->outcome);
        $this->assertSame('Pendiente de contrato', Contract::query()->sole()->status);
    }

    public function test_revert_a_loss_restores_the_opportunity(): void
    {
        $quote = $this->quote();
        $this->as($this->operator)->postJson("/cotizaciones/{$quote->id}/perdida", ['winner_name' => 'Rival', 'winner_amount' => 100])->assertOk();

        $this->as($this->admin)->postJson("/cotizaciones/{$quote->id}/revertir", ['reason' => 'Se impugnó'])->assertOk();

        $this->assertSame('Pendiente', $quote->fresh()->outcome);
        $this->assertNull($quote->fresh()->winner_name);
        $this->assertSame('oferta_presentada', Opportunity::query()->find($quote->opportunity_id)->stage);
        $this->assertFalse(QuoteParticipant::query()->where('name', 'Rival')->sole()->is_winner);
    }

    public function test_an_opportunity_without_quote_can_be_won_and_lost(): void
    {
        $opportunity = $this->inCompany($this->company, fn () => Opportunity::factory()->for($this->company)->stage('oferta_presentada')->create(['institution_id' => $this->institution->id]));

        $this->as($this->operator)->postJson("/oportunidades/{$opportunity->id}/ganada", ['awarded_amount' => 7000, 'awarded_at' => now()->toDateString()])
            ->assertOk()->assertJsonPath('result.quote', null);

        $this->assertSame('adjudicado', $opportunity->fresh()->stage);
        $this->assertSame($opportunity->id, Contract::query()->sole()->opportunity_id);
        $this->assertSame('7000.00', Client::query()->sole()->accumulated_amount);

        $this->as($this->admin)->postJson("/oportunidades/{$opportunity->id}/revertir", ['reason' => 'Prueba de reversión'])->assertOk();
        $this->as($this->operator)->postJson("/oportunidades/{$opportunity->id}/perdida", ['winner_name' => 'Otro', 'winner_amount' => 6500])->assertOk();
        $this->assertSame('no_adjudicado', $opportunity->fresh()->stage);
    }

    public function test_opportunity_with_several_live_quotes_requires_choosing_one(): void
    {
        $quote = $this->quote();
        Quote::factory()->for($this->company)->create(['opportunity_id' => $quote->opportunity_id, 'status' => 'Emitida']);

        $this->as($this->operator)->postJson("/oportunidades/{$quote->opportunity_id}/ganada", ['awarded_amount' => 1, 'awarded_at' => now()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('quote_id');
        $this->as($this->operator)->postJson("/oportunidades/{$quote->opportunity_id}/ganada", ['awarded_amount' => 1, 'awarded_at' => now()->toDateString(), 'quote_id' => $quote->id])
            ->assertOk();

        $this->assertSame('Ganada', $quote->fresh()->outcome);
    }

    public function test_viewer_and_other_company_cannot_adjudicate(): void
    {
        $quote = $this->quote();
        [$stranger, $other] = $this->memberOf();

        $this->as($this->viewer)->postJson("/cotizaciones/{$quote->id}/ganada", ['awarded_amount' => 1, 'awarded_at' => now()->toDateString()])->assertForbidden();
        $this->actingInCompany($stranger, $other)->postJson("/cotizaciones/{$quote->id}/ganada", ['awarded_amount' => 1, 'awarded_at' => now()->toDateString()])->assertNotFound();
        $this->actingInCompany($stranger, $other)->postJson("/oportunidades/{$quote->opportunity_id}/perdida", ['winner_name' => 'X', 'winner_amount' => 1])->assertNotFound();

        $this->assertSame('Pendiente', $quote->fresh()->outcome);
    }
}
