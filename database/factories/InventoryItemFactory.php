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
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $cost = fake()->randomFloat(2, 10, 5000);
        $stock = fake()->numberBetween(0, 500);

        return [
            'company_id' => Company::factory(),
            'sku' => fake()->unique()->bothify('SKU-#####??'),
            'description' => fake()->words(3, true),
            'category' => 'General',
            'brand' => fake()->company(),
            'unit' => 'Unidad',
            'stock' => $stock,
            'stock_available' => $stock,
            'cost' => $cost,
            'price' => round($cost * 1.45, 2),
            'tax_rate' => 15,
            'tax_included' => true,
            'is_active' => true,
        ];
    }
}
