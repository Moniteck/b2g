<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Adjunto: un documento enlazado a una entidad (cotización, contrato, licitación, oportunidad,
 * cliente). `category` y `description` describen el papel del archivo en esa entidad.
 */
class DocumentLink extends Model
{
    protected $fillable = [
        'document_id', 'document_version_id', 'linkable_type', 'linkable_id', 'context',
        'category', 'description', 'created_by',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function version()
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }
}
