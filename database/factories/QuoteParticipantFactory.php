<?php

namespace Database\Factories;

use App\Models\Quote;
use App\Models\QuoteParticipant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuoteParticipant>
 */
class QuoteParticipantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quote_id' => Quote::factory(),
            'name' => fake()->company(),
            'tax_id' => fake()->numerify('##############'),
            'amount' => fake()->randomFloat(2, 1000, 500000),
            'currency' => 'HNL',
            'includes_tax' => true,
            'is_ours' => false,
            'position' => 1,
        ];
    }

    /**
     * Marca al participante como la oferta de nuestra empresa.
     */
    public function ours(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_ours' => true,
        ]);
    }
}
