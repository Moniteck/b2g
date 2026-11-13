<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentVersion>
 */
class DocumentVersionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'version' => 1,
            'path' => 'documents/'.fake()->uuid().'.pdf',
            'disk' => 'local',
            'original_filename' => 'documento.pdf',
            'mime_type' => 'application/pdf',
            'size' => fake()->numberBetween(10000, 500000),
            'hash' => hash('sha256', fake()->uuid()),
            'uploaded_by' => null,
            'created_at' => now(),
        ];
    }
}
