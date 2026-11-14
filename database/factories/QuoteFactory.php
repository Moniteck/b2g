<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Quote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quote>
 */
class QuoteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 1000, 500000);
        $tax = round($subtotal * 0.15, 2);

        return [
            'company_id' => Company::factory(),
            'client_id' => null,
            'institution_id' => null,
            'opportunity_id' => null,
            'tender_id' => null,
            'contact_id' => null,
            'created_by' => null,
            'number' => fake()->unique()->numerify('COT-'.now()->year.'-#####'),
            'quoted_at' => now()->toDateString(),
            'purchase_type' => 'Compra Menor',
            'validity_days' => 30,
            'status' => 'Borrador',
            'outcome' => 'Pendiente',
            'revision' => 1,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'discount' => 0,
            'tax_included' => true,
            'pricing_source' => 'manual',
            'total' => round($subtotal + $tax, 2),
        ];
    }

    /**
     * Cambia el estado documental (valores de Quote::STATUSES).
     */
    public function status(string $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
        ]);
    }

    /**
     * Cotización presentada y adjudicada a nuestra empresa.
     */
    public function won(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'Presentada',
            'outcome' => 'Ganada',
            'awarded_amount' => $attributes['total'],
            'awarded_at' => now()->toDateString(),
        ]);
    }

    /**
     * Cotización presentada y adjudicada a otro oferente.
     */
    public function lost(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'Presentada',
            'outcome' => 'Perdida',
            'winner_name' => fake()->company(),
            'lost_reason' => 'Precio superior al del adjudicatario.',
        ]);
    }
}
