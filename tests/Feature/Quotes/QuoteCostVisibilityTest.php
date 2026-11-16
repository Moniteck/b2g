<?php

namespace Tests\Feature\Quotes;

use App\Models\Quote;

/**
 * El costo y el margen de cada línea solo los ve quien puede ver costos (operador y administrador).
 */
class QuoteCostVisibilityTest extends QuotesTestCase
{
    private function quoteWithCosts(): Quote
    {
        $this->as($this->operator)->post('/cotizaciones', $this->payload([
            'items' => [
                ['description' => 'Resma de papel carta', 'quantity' => 10, 'unit' => 'Resma', 'unit_cost' => 80, 'margin' => 20, 'unit_price' => 100, 'tax_rate' => 15],
            ],
        ]))->assertSessionHasNoErrors();

        return $this->inCompany($this->company, fn () => Quote::query()->latest('id')->firstOrFail());
    }

    public function test_viewer_does_not_receive_costs_or_margins(): void
    {
        $quote = $this->quoteWithCosts();

        $response = $this->as($this->viewer)->getJson("/cotizaciones/{$quote->id}")->assertOk()
            ->assertJsonPath('data.can_see_cost', false);

        $item = $response->json('data.items.0');
        $this->assertArrayNotHasKey('unit_cost', $item);
        $this->assertArrayNotHasKey('margin', $item);
        $this->assertStringNotContainsString('unit_cost', $response->getContent());
        $this->assertStringNotContainsString('"margin"', $response->getContent());
    }

    public function test_operator_and_admin_receive_costs_and_margins(): void
    {
        $quote = $this->quoteWithCosts();

        foreach ([$this->operator, $this->admin] as $user) {
            $this->as($user)->getJson("/cotizaciones/{$quote->id}")->assertOk()
                ->assertJsonPath('data.can_see_cost', true)
                ->assertJsonPath('data.items.0.unit_cost', 80)
                ->assertJsonPath('data.items.0.margin', 20);
        }
    }

    public function test_viewer_list_and_pdf_do_not_expose_costs(): void
    {
        $quote = $this->quoteWithCosts();

        $list = $this->as($this->viewer)->get('/cotizaciones')->assertOk();
        $this->assertStringNotContainsString('unit_cost', $list->getContent());

        $this->as($this->viewer)->get("/cotizaciones/{$quote->id}/pdf")->assertOk();
    }
}
