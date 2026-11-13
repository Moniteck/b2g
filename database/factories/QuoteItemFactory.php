<?php

namespace Database\Factories;

use App\Models\Quote;
use App\Models\QuoteItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuoteItem>
 */
class QuoteItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 50);
        $unitCost = fake()->randomFloat(2, 10, 5000);
        $margin = 45;
        $unitPrice = round($unitCost * (1 + $margin / 100), 2);

        return [
            'quote_id' => Quote::factory(),
            'description' => fake()->sentence(3),
            'quantity' => $quantity,
            'unit' => 'Unidad',
            'unit_cost' => $unitCost,
            'margin' => $margin,
            'tax_rate' => 15,
            'pricing_source' => 'manual',
            'inventory_item_id' => null,
            'unit_price' => $unitPrice,
            'total' => round($unitPrice * $quantity, 2),
        ];
    }
}
