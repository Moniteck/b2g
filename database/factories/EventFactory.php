<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = now()->addDays(3)->setTime(9, 0);

        return [
            'company_id' => Company::factory(),
            'user_id' => null,
            'institution_id' => null,
            'opportunity_id' => null,
            'tender_id' => null,
            'title' => fake()->sentence(3),
            'type' => 'Manual',
            'origin' => 'manual',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHour(),
            'priority' => 'Normal',
            'remindable' => true,
            'completed' => false,
        ];
    }

    /**
     * Cambia el tipo de evento (claves de Event::TYPES).
     */
    public function type(string $type): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => $type,
        ]);
    }

    /**
     * Evento ya atendido.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'completed' => true,
            'completed_at' => now(),
        ]);
    }
}
