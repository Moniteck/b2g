<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Opportunity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Opportunity>
 */
class OpportunityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'institution_id' => null,
            'tender_id' => null,
            'quote_id' => null,
            'contact_id' => null,
            'owner_id' => null,
            'title' => fake()->sentence(4),
            'amount' => fake()->randomFloat(2, 10000, 1000000),
            'probability' => fake()->numberBetween(10, 90),
            'stage' => 'prospecto',
            'expected_close_date' => now()->addDays(30)->toDateString(),
        ];
    }

    /**
     * Coloca la oportunidad en una etapa del tablero (claves de Opportunity::STAGES).
     */
    public function stage(string $stage): static
    {
        return $this->state(fn (array $attributes) => [
            'stage' => $stage,
        ]);
    }
}
