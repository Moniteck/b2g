<?php

namespace Tests\Feature\Quotes;

use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\Opportunity;
use App\Models\Quote;
use App\Models\QuoteParticipant;
use App\Models\Tender;
use App\Services\Business\QuoteService;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Alta, edición, cálculo de ISV, numeración, estado documental, lista y permisos.
 */
class QuoteCrudTest extends QuotesTestCase
{
    public function test_operator_creates_a_quote_with_lines_and_tax_added_to_prices_without_isv(): void
    {
        $this->as($this->operator)->post('/cotizaciones', $this->payload())
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $quote = Quote::query()->with('items')->sole();

        $this->assertSame('Borrador', $quote->status);
        $this->assertSame('Pendiente', $quote->outcome);
        $this->assertSame($this->company->id, $quote->company_id);
        $this->assertFalse($quote->tax_included);
        $this->assertSame('Entrega en 15 días hábiles.', $quote->conditions);
        // 10 × 100 = 1,000 + 15 % ; 2 × 1,000 − 10 % = 1,800 + 15 %
        $this->assertSame('2800.00', $quote->subtotal);
        $this->assertSame('420.00', $quote->tax);
        $this->assertSame('200.00', $quote->discount);
        $this->assertSame('3220.00', $quote->total);
        $this->assertSame(['1150.00', '2070.00'], $quote->items->pluck('total')->all());
        $this->assertSame('1800.00', $quote->items[1]->subtotal);
        $this->assertDatabaseHas('audit_logs', ['action' => 'cotizacion.creada', 'resource_id' => $quote->id]);
    }

    public function test_company_rule_with_prices_including_tax_extracts_the_isv(): void
    {
        $this->company->forceFill(['prices_include_tax' => true])->save();
        $payload = $this->payload(['items' => [['description' => 'Silla', 'quantity' => 1, 'unit' => 'Unidad', 'unit_price' => 1150, 'tax_rate' => 15]]]);
        unset($payload['tax_included']);

        $this->as($this->operator)->post('/cotizaciones', $payload)->assertSessionHasNoErrors();

        $quote = Quote::query()->sole();
        $this->assertTrue($quote->tax_included);
        $this->assertSame('1000.00', $quote->subtotal);
        $this->assertSame('150.00', $quote->tax);
        $this->assertSame('1150.00', $quote->total);
    }

    public function test_inventory_line_takes_its_price_from_the_pricing_rule(): void
    {
        $item = $this->inCompany($this->company, fn () => InventoryItem::factory()->for($this->company)->create(['suggested_price' => 200, 'tax_rate' => 15, 'tax_included' => false, 'sku' => 'BL-1']));

        $this->as($this->operator)->post('/cotizaciones', $this->payload(['items' => [
            ['description' => 'Producto Blessing', 'quantity' => 3, 'unit' => 'Unidad', 'inventory_item_id' => $item->id],
        ]]))->assertSessionHasNoErrors();

        $line = Quote::query()->sole()->items->sole();
        $this->assertSame('200.00', $line->unit_price);
        $this->assertSame('blessing', $line->pricing_source);
        $this->assertSame($item->id, (int) $line->inventory_item_id);
        $this->assertSame('BL-1', $line->sku);
        $this->assertSame('690.00', $line->total);
    }

    public function test_numbers_are_sequential_per_company_even_with_an_empty_table(): void
    {
        [$otherAdmin, $other] = $this->memberOf();

        $this->as($this->operator)->post('/cotizaciones', $this->payload())->assertSessionHasNoErrors();
        $this->as($this->operator)->post('/cotizaciones', $this->payload())->assertSessionHasNoErrors();
        $this->actingInCompany($otherAdmin, $other)->post('/cotizaciones', $this->payload())->assertSessionHasNoErrors();

        $year = now()->year;
        $this->assertSame(["COT-{$year}-0001", "COT-{$year}-0002"], Quote::withoutGlobalScopes()->where('company_id', $this->company->id)->orderBy('id')->pluck('number')->all());
        $this->assertSame(["COT-{$year}-0001"], Quote::withoutGlobalScopes()->where('company_id', $other->id)->pluck('number')->all());
    }

    public function test_edit_recalculates_and_is_blocked_once_presented(): void
    {
        $this->as($this->operator)->post('/cotizaciones', $this->payload());
        $quote = Quote::query()->sole();

        $this->as($this->operator)->put("/cotizaciones/{$quote->id}", $this->payload(['items' => [
            ['description' => 'Solo una línea', 'quantity' => 1, 'unit' => 'Unidad', 'unit_price' => 500, 'tax_rate' => 15],
        ]]))->assertSessionHasNoErrors();

        $quote->refresh();
        $this->assertSame('575.00', $quote->total);
        $this->assertCount(1, $quote->items);

        $quote->forceFill(['status' => 'Presentada'])->save();
        $this->as($this->operator)->put("/cotizaciones/{$quote->id}", $this->payload())->assertForbidden();
    }

    public function test_validation_messages_are_in_spanish_under_each_field(): void
    {
        $this->as($this->operator)->post('/cotizaciones', $this->payload(['institution_id' => null, 'items' => [['description' => '', 'quantity' => 0, 'unit' => 'U']]]))
            ->assertSessionHasErrors(['institution_id' => 'Elija la institución a la que va la cotización.', 'items.0.description', 'items.0.quantity' => 'La cantidad debe ser mayor que cero.']);

        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_related_records_must_belong_to_the_issuing_company(): void
    {
        $foreign = Company::factory()->create();
        $tender = Tender::factory()->for($foreign)->create();
        $item = InventoryItem::factory()->for($foreign)->create();

        $this->as($this->operator)->post('/cotizaciones', $this->payload(['tender_id' => $tender->id]))->assertSessionHasErrors('tender_id');
        $this->as($this->operator)->post('/cotizaciones', $this->payload(['items' => [['description' => 'X', 'quantity' => 1, 'unit' => 'U', 'inventory_item_id' => $item->id]]]))->assertSessionHasErrors('items.0.inventory_item_id');
        $this->as($this->operator)->post('/cotizaciones', $this->payload(['company_id' => $foreign->id]))->assertSessionHasErrors('company_id');

        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_status_rules_and_presenting_registers_our_offer_and_moves_the_card(): void
    {
        $quote = $this->quote(['status' => 'Borrador']);

        $this->as($this->operator)->patch("/cotizaciones/{$quote->id}/estado", ['status' => 'Presentada'])->assertSessionHasErrors('status');
        $this->as($this->operator)->patch("/cotizaciones/{$quote->id}/estado", ['status' => 'Emitida'])->assertSessionHasNoErrors();
        Opportunity::query()->whereKey($quote->opportunity_id)->update(['stage' => 'oportunidad']);
        $this->as($this->operator)->patch("/cotizaciones/{$quote->id}/estado", ['status' => 'Presentada'])->assertSessionHasNoErrors();

        $this->assertSame('Presentada', $quote->fresh()->status);
        $this->assertSame('oferta_presentada', Opportunity::query()->find($quote->opportunity_id)->stage);
        $ours = QuoteParticipant::query()->sole();
        $this->assertTrue($ours->is_ours);
        $this->assertSame('11500.00', $ours->amount);
        $this->assertSame('CAP Honduras', $ours->name);
        $this->assertDatabaseHas('audit_logs', ['action' => 'cotizacion.estado']);

        // Presentar de nuevo no duplica nuestra oferta.
        $quote->forceFill(['status' => 'Emitida'])->save();
        $this->as($this->operator)->patch("/cotizaciones/{$quote->id}/estado", ['status' => 'Presentada'])->assertSessionHasNoErrors();
        $this->assertSame(1, QuoteParticipant::query()->count());
    }

    public function test_only_admin_deletes_and_only_drafts(): void
    {
        $draft = $this->quote(['status' => 'Borrador']);
        $issued = $this->quote(['status' => 'Emitida']);

        $this->as($this->operator)->delete("/cotizaciones/{$draft->id}")->assertForbidden();
        $this->as($this->admin)->delete("/cotizaciones/{$issued->id}")->assertForbidden();
        $this->as($this->admin)->delete("/cotizaciones/{$draft->id}")->assertSessionHas('success');

        $this->assertSoftDeleted('quotes', ['id' => $draft->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'cotizacion.eliminada']);
    }

    public function test_viewer_sees_and_downloads_but_cannot_write(): void
    {
        $quote = $this->quote(['status' => 'Borrador']);

        $this->as($this->viewer)->get('/cotizaciones')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Quotes/Index')
                ->where('can.create', false)
                ->where('quotes.data.0.can.update', false)
                ->where('quotes.data.0.can.win', false)
                ->where('quotes.data.0.can.attach', false));
        $this->as($this->viewer)->get("/cotizaciones/{$quote->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->as($this->viewer)->getJson("/cotizaciones/{$quote->id}")->assertOk();

        $this->as($this->viewer)->post('/cotizaciones', $this->payload())->assertForbidden();
        $this->as($this->viewer)->put("/cotizaciones/{$quote->id}", $this->payload())->assertForbidden();
        $this->as($this->viewer)->patch("/cotizaciones/{$quote->id}/estado", ['status' => 'Emitida'])->assertForbidden();
        $this->as($this->viewer)->putJson("/cotizaciones/{$quote->id}/participantes", ['participants' => []])->assertForbidden();
        $this->as($this->viewer)->getJson("/adjuntos/quote/{$quote->id}")->assertOk()->assertJsonPath('can.edit', false);
    }

    public function test_index_lists_with_stats_filters_and_issuer_companies_without_overriding_shared_companies(): void
    {
        $this->quote(['number' => 'COT-A', 'status' => 'Borrador']);
        $this->quote(['number' => 'COT-B', 'status' => 'Presentada']);
        $this->quote(['number' => 'COT-C', 'status' => 'Presentada', 'outcome' => 'Ganada', 'awarded_amount' => 5000]);
        $this->quote(['number' => 'COT-D', 'status' => 'Presentada', 'outcome' => 'Perdida']);

        $this->as($this->admin)->get('/cotizaciones?segment=presentadas')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Quotes/Index')
                ->has('quotes.data', 1)
                ->where('quotes.data.0.number', 'COT-B')
                ->where('stats.preparing', 1)
                ->where('stats.presented', 1)
                ->where('stats.won', 1)
                ->where('stats.rate', 50)
                ->has('issuerCompanies', 1)
                ->where('issuerCompanies.0.id', $this->company->id)
                ->has('companies')
                ->where('quotes.data.0.can.win', true));

        $this->as($this->admin)->get('/cotizaciones?search=COT-D')->assertInertia(fn (Assert $page) => $page->has('quotes.data', 1));
        $this->as($this->admin)->get('/cotizaciones?from=2099-01-01')->assertInertia(fn (Assert $page) => $page->has('quotes.data', 0));
    }

    public function test_generated_pdf_has_the_issuer_and_the_tax_note_of_the_rule(): void
    {
        $this->as($this->operator)->post('/cotizaciones', $this->payload());
        $quote = Quote::query()->sole();

        $html = view('pdf.quote', ['quote' => $quote->load('company', 'institution', 'items'), 'document' => app(QuoteService::class)->document($quote->items)])->render();

        $this->assertStringContainsString('CAP Honduras', $html);
        $this->assertStringContainsString('Secretaría de Salud', $html);
        $this->assertStringContainsString('no incluyen el impuesto sobre ventas', $html);
        $this->assertStringContainsString('L 3,220.00', $html);

        $this->as($this->operator)->get("/cotizaciones/{$quote->id}/pdf?descargar=1")->assertOk()
            ->assertHeader('Content-Disposition', "attachment; filename=\"{$quote->number}.pdf\"");
    }
}
