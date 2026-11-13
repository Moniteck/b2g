<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Tender;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tender>
 */
class TenderFactory extends Factory
{
    /**
     * Las fechas críticas quedan en el futuro para que la licitación esté vigente.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $presentation = now()->addDays(14)->setTime(10, 0);

        return [
            'company_id' => Company::factory(),
            'institution_id' => null,
            'owner_id' => null,
            'reference' => fake()->unique()->numerify('LPN-#####-'.now()->year),
            'process_name' => 'Licitación pública nacional',
            'title' => fake()->sentence(4),
            'purchase_type' => 'Licitación Pública',
            'status' => 'Activa',
            'estimated_amount' => fake()->randomFloat(2, 50000, 2000000),
            'published_at' => now()->subDays(3)->toDateString(),
            'clarification_deadline' => now()->addDays(7)->setTime(16, 0),
            'presentation_deadline' => $presentation,
            'opening_at' => $presentation->copy()->addMinutes(30),
            'closing_date' => $presentation->toDateString(),
            'offer_submitted' => false,
        ];
    }

    /**
     * Cambia el estado de la licitación.
     */
    public function status(string $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
        ]);
    }

    /**
     * Licitación cuyas fechas críticas ya pasaron.
     */
    public function expired(): static
    {
        return $this->state(function (array $attributes) {
            $presentation = now()->subDays(7)->setTime(10, 0);

            return [
                'published_at' => now()->subDays(30)->toDateString(),
                'clarification_deadline' => now()->subDays(14)->setTime(16, 0),
                'presentation_deadline' => $presentation,
                'opening_at' => $presentation->copy()->addMinutes(30),
                'closing_date' => $presentation->toDateString(),
            ];
        });
    }
}
