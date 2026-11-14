<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Institution;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'institution_id' => Institution::factory(),
            'name' => fake()->name(),
            'position' => fake()->jobTitle(),
            'department' => 'Adquisiciones',
            'email' => fake()->safeEmail(),
            'phone' => fake()->numerify('+504 ####-####'),
        ];
    }
}
