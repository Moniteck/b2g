<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Registro de una firma aplicada: quién, cuándo, sobre qué documento y versión,
 * en qué páginas y en qué posiciones (porcentajes de la página).
 *
 * Guarda una instantánea del firmante y del documento: borrar la firma, el
 * usuario o el documento no borra el historial.
 */
class SignatureApplication extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'user_id', 'signer_name', 'document_id', 'source_version_id', 'document_version_id',
        'document_name', 'used_signature', 'used_seal', 'all_pages', 'pages', 'placements', 'page_count',
        'source_hash', 'result_hash', 'result_path', 'result_disk', 'normalized', 'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'used_signature' => 'boolean',
            'used_seal' => 'boolean',
            'all_pages' => 'boolean',
            'normalized' => 'boolean',
            'pages' => 'array',
            'placements' => 'array',
            'page_count' => 'integer',
            'applied_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function version()
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }

    public function sourceVersion()
    {
        return $this->belongsTo(DocumentVersion::class, 'source_version_id');
    }
}
