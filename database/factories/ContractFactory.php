<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Contract;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contract>
 */
class ContractFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'tender_id' => null,
            'quote_id' => null,
            'opportunity_id' => null,
            'institution_id' => null,
            'contact_id' => null,
            'manager_id' => null,
            'number' => fake()->unique()->numerify('CTR-'.now()->year.'-#####'),
            'name' => 'Contrato de '.fake()->words(3, true),
            'amount' => fake()->randomFloat(2, 10000, 1000000),
            'duration_business_days' => false,
            'start_trigger' => 'Orden de inicio',
            'billed_amount' => 0,
            'collected_amount' => 0,
            'retention_amount' => 0,
            'physical_progress' => 0,
            'financial_progress' => 0,
            'status' => 'Pendiente de contrato',
            'product_delivered' => false,
        ];
    }

    /**
     * Cambia el estado del expediente (claves de Contract::STATUSES).
     */
    public function status(string $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
        ]);
    }
}
