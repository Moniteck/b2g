<?php

namespace Tests\Feature\Quotes;

use App\Mcp\Tools\UpdateQuoteTool;
use App\Models\Company;
use App\Models\Institution;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Mcp\McpTestCase;

/**
 * `update_quote` por MCP conserva el costo y el margen de las líneas.
 */
class McpQuoteCostTest extends McpTestCase
{
    private Company $company;

    private User $admin;

    private Quote $quote;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        [$this->admin, $this->company] = $this->memberOf(role: 'admin');
        $institution = Institution::factory()->create();

        $this->actingInCompany($this->admin, $this->company)->post('/cotizaciones', [
            'institution_id' => $institution->id,
            'quoted_at' => '2026-11-13',
            'validity_days' => 30,
            'purchase_type' => 'Cotización',
            'items' => [['description' => 'Llanta 11R22.5', 'quantity' => 4, 'unit' => 'Unidad', 'unit_cost' => 800, 'margin' => 20, 'unit_price' => 1000, 'tax_rate' => 15]],
        ])->assertSessionHasNoErrors();
        $this->quote = $this->inCompany($this->company, fn () => Quote::query()->latest('id')->firstOrFail());

        $this->inSession($this->admin, $this->connect($this->admin, [$this->company]));
    }

    /** @return array{0: float, 1: float} */
    private function cost(): array
    {
        $item = QuoteItem::query()->where('quote_id', $this->quote->id)->firstOrFail();

        return [(float) $item->unit_cost, (float) $item->margin];
    }

    public function test_update_without_items_keeps_costs(): void
    {
        $this->tool(UpdateQuoteTool::class, ['quote_id' => $this->quote->id, 'notes' => 'Entrega en 15 días'])->assertHasNoErrors();

        $this->assertSame([800.0, 20.0], $this->cost());
    }

    public function test_update_with_items_without_costs_keeps_them(): void
    {
        $this->tool(UpdateQuoteTool::class, ['quote_id' => $this->quote->id, 'items' => [
            ['description' => 'Llanta 11R22.5', 'quantity' => 6, 'unit' => 'Unidad', 'unit_price' => 1000, 'tax_rate' => 15],
        ]])->assertHasNoErrors();

        $this->assertSame([800.0, 20.0], $this->cost());
    }

    public function test_update_with_a_new_cost_saves_it(): void
    {
        $this->tool(UpdateQuoteTool::class, ['quote_id' => $this->quote->id, 'items' => [
            ['description' => 'Llanta 11R22.5', 'quantity' => 4, 'unit' => 'Unidad', 'unit_cost' => 850, 'margin' => 15, 'unit_price' => 1000, 'tax_rate' => 15],
        ]])->assertHasNoErrors();

        $this->assertSame([850.0, 15.0], $this->cost());
    }
}
