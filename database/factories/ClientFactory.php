<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'institution_id' => null,
            'source_quote_id' => null,
            'name' => fake()->company(),
            'tax_id' => fake()->unique()->numerify('##############'),
            'type' => 'Gobierno',
            'sector' => 'Público',
            'contact_name' => fake()->name(),
            'phone' => fake()->numerify('+504 ####-####'),
            'email' => fake()->safeEmail(),
            'status' => 'Activo',
            'accumulated_amount' => 0,
        ];
    }
}
