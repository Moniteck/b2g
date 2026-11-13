<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\InventoryItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryItem>
 */
class InventoryItemFactory extends Factory
{
    /**
     * Precio sugerido SIN ISV y 15 % (supuesto vigente; ver App\Services\Pricing\TaxRules).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $cost = fake()->randomFloat(2, 10, 5000);
        $stock = fake()->numberBetween(0, 500);

        return [
            'company_id' => Company::factory(),
            'blessing_id' => fake()->unique()->bothify('BL-#######'),
            'sku' => fake()->unique()->bothify('SKU-#####??'),
            'description' => fake()->words(3, true),
            'category' => 'General',
            'brand' => fake()->company(),
            'unit' => 'Unidad',
            'stock' => $stock,
            'stock_available' => $stock,
            'cost' => $cost,
            'price' => round($cost * 1.45, 2),
            'suggested_price' => round($cost * 1.45, 2),
            'tax_rate' => 15,
            'tax_included' => false,
            'currency' => 'HNL',
            'is_active' => true,
            'synced_at' => now(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false, 'deactivated_at' => now()]);
    }
}
