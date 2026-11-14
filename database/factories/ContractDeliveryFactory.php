<?php

namespace Database\Factories;

use App\Models\Contract;
use App\Models\ContractDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContractDelivery>
 */
class ContractDeliveryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contract_id' => Contract::factory(),
            'description' => 'Entrega de '.fake()->words(3, true),
            'quantity' => fake()->numberBetween(1, 100),
            'unit' => 'Unidad',
            'due_date' => now()->addDays(15)->toDateString(),
            'status' => 'Pendiente',
            'evidence_document_id' => null,
        ];
    }

    /**
     * Entrega ya realizada.
     */
    public function delivered(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'Entregada',
            'delivered_at' => now(),
        ]);
    }
}
