<?php

namespace Database\Factories;

use App\Models\Institution;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Institution>
 */
class InstitutionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Institución '.fake()->unique()->company(),
            'acronym' => strtoupper(fake()->lexify('????')),
            'type' => 'Gobierno central',
            'potential' => fake()->randomElement(['ALTO', 'MEDIO', 'BAJO']),
            'location' => fake()->city(),
            'status' => 'Activa',
        ];
    }
}
