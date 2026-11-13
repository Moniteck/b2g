<?php

namespace Database\Factories;

use App\Models\Contract;
use App\Models\ContractFollowUp;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContractFollowUp>
 */
class ContractFollowUpFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contract_id' => Contract::factory(),
            'user_id' => User::factory(),
            'type' => 'Seguimiento',
            'description' => fake()->sentence(),
            'occurred_at' => now(),
        ];
    }
}
