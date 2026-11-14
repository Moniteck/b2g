<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'code' => fake()->unique()->bothify('EMP-####??'),
            'tax_id' => fake()->unique()->numerify('##############'),
            'representative' => fake()->name(),
            'contact_name' => fake()->name(),
            'contact_email' => fake()->safeEmail(),
            'contact_phone' => fake()->numerify('+504 ####-####'),
            'is_active' => true,
        ];
    }

    /**
     * Empresa desactivada.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Vincula un usuario a la empresa con el rol indicado al crearla.
     */
    public function withUser(User $user, string $role = 'operator', bool $default = true): static
    {
        return $this->afterCreating(function (Company $company) use ($user, $role, $default) {
            $company->users()->attach($user->id, ['role' => $role, 'is_default' => $default]);
        });
    }
}
