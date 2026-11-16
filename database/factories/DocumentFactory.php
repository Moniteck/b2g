<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentVersion;
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
            'signed_by' => null,
            'name' => 'Solvencia '.fake()->numerify('####'),
            'kind' => 'empresa',
            'category' => 'Solvencia',
            'version' => 1,
            'status' => 'Vigente',
            'issued_at' => now()->subMonth()->toDateString(),
            'expires_at' => now()->addMonths(6)->toDateString(),
        ];
    }

    /** Con archivo: el archivo vive en la versión 1. */
    public function withFile(): static
    {
        return $this->afterCreating(fn (Document $document) => DocumentVersion::create([
            'document_id' => $document->id,
            'version' => $document->version ?? 1,
            'disk' => 'local',
            'path' => 'documents/'.fake()->uuid().'.pdf',
            'original_filename' => 'solvencia.pdf',
            'mime_type' => 'application/pdf',
            'size' => fake()->numberBetween(10000, 500000),
            'hash' => hash('sha256', (string) $document->id),
            'issued_at' => $document->issued_at,
            'expires_at' => $document->expires_at,
            'created_at' => now(),
        ]));
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
