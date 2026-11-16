<?php

namespace Tests\Feature\Quotes;

use App\Models\Quote;
use App\Models\QuoteItem;

/**
 * Editar una cotización no pierde el costo ni el margen escritos a mano en sus líneas.
 */
class QuoteCostPreservationTest extends QuotesTestCase
{
    private function draftWithCosts(): Quote
    {
        $this->as($this->operator)->post('/cotizaciones', $this->payload([
            'items' => [
                ['description' => 'Resma de papel carta', 'quantity' => 10, 'unit' => 'Resma', 'unit_cost' => 80, 'margin' => 20, 'unit_price' => 100, 'tax_rate' => 15],
                ['description' => 'Tóner', 'quantity' => 2, 'unit' => 'Unidad', 'unit_cost' => 700, 'margin' => 30, 'unit_price' => 1000, 'tax_rate' => 15],
            ],
        ]))->assertSessionHasNoErrors();

        return $this->inCompany($this->company, fn () => Quote::query()->with('items')->latest('id')->firstOrFail());
    }

    /** @return array<int, array{0: float, 1: float}> */
    private function costsOf(Quote $quote): array
    {
        return QuoteItem::query()->where('quote_id', $quote->id)->orderBy('position')->get()
            ->map(fn (QuoteItem $item) => [(float) $item->unit_cost, (float) $item->margin])->all();
    }

    /**
     * Líneas como las envía un formulario sin costos: con `id`, sin costo ni margen.
     *
     * @param  array<int, array<string, mixed>>  $changes
     * @return array<int, array<string, mixed>>
     */
    private function linesWithoutCosts(Quote $quote, array $changes = []): array
    {
        return $quote->items->sortBy('position')->values()->map(fn (QuoteItem $item, int $index) => ($changes[$index] ?? []) + [
            'id' => $item->id,
            'description' => $item->description,
            'quantity' => (float) $item->quantity,
            'unit' => $item->unit,
            'unit_price' => (float) $item->unit_price,
            'tax_rate' => (float) $item->tax_rate,
        ])->all();
    }

    public function test_editing_without_costs_keeps_them(): void
    {
        $quote = $this->draftWithCosts();

        $this->as($this->operator)->put("/cotizaciones/{$quote->id}", $this->payload(['items' => $this->linesWithoutCosts($quote, [0 => ['quantity' => 12]])]))
            ->assertSessionHasNoErrors();

        $this->assertSame([[80.0, 20.0], [700.0, 30.0]], $this->costsOf($quote));
    }

    public function test_lines_without_id_are_matched_by_description(): void
    {
        $quote = $this->draftWithCosts();
        $lines = array_map(fn (array $line) => array_diff_key($line, ['id' => true]), $this->linesWithoutCosts($quote));

        $this->as($this->admin)->put("/cotizaciones/{$quote->id}", $this->payload(['items' => array_reverse($lines)]))->assertSessionHasNoErrors();

        $this->assertSame([[700.0, 30.0], [80.0, 20.0]], $this->costsOf($quote));
    }

    public function test_editing_the_cost_saves_it_and_new_lines_start_empty(): void
    {
        $quote = $this->draftWithCosts();
        $lines = $this->linesWithoutCosts($quote, [0 => ['unit_cost' => 85, 'margin' => 15]]);
        $lines[] = ['description' => 'Grapas', 'quantity' => 1, 'unit' => 'Caja', 'unit_price' => 50, 'tax_rate' => 15];

        $this->as($this->operator)->put("/cotizaciones/{$quote->id}", $this->payload(['items' => $lines]))->assertSessionHasNoErrors();

        $this->assertSame([[85.0, 15.0], [700.0, 30.0], [0.0, 0.0]], $this->costsOf($quote));
    }

    public function test_line_ids_of_another_quote_are_ignored(): void
    {
        $quote = $this->draftWithCosts();
        $other = $this->draftWithCosts();

        $this->as($this->operator)->put("/cotizaciones/{$quote->id}", $this->payload(['items' => [
            ['id' => $other->items->first()->id, 'description' => 'Otra cosa', 'quantity' => 1, 'unit' => 'Unidad', 'unit_price' => 10, 'tax_rate' => 15],
        ]]))->assertSessionHasNoErrors();

        $this->assertSame([[0.0, 0.0]], $this->costsOf($quote));
    }

    public function test_index_tells_the_form_who_sees_costs(): void
    {
        $this->as($this->operator)->get('/cotizaciones')->assertInertia(fn ($page) => $page->where('can.viewCost', true));
        $this->as($this->viewer)->get('/cotizaciones')->assertInertia(fn ($page) => $page->where('can.viewCost', false));
    }
}
