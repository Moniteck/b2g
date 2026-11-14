<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'institution_id' => null,
            'uploaded_by' => null,
            'signed_by' => null,
            'name' => 'Solvencia '.fake()->numerify('####'),
            'kind' => 'empresa',
            'category' => 'Solvencia',
            'version' => 1,
            'status' => 'Vigente',
            'disk' => 'local',
            'path' => 'documents/'.fake()->uuid().'.pdf',
            'original_filename' => 'solvencia.pdf',
            'mime_type' => 'application/pdf',
            'size' => fake()->numberBetween(10000, 500000),
            'issued_at' => now()->subMonth()->toDateString(),
            'expires_at' => now()->addMonths(6)->toDateString(),
        ];
    }

    /**
     * Cambia el tipo de expediente (claves de Document::KINDS).
     */
    public function kind(string $kind): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => $kind,
        ]);
    }

    /**
     * Documento con la vigencia vencida.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'Vencido',
            'expires_at' => now()->subDay()->toDateString(),
        ]);
    }
}
