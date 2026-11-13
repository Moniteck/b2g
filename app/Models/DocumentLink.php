<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class DocumentLink extends Model
{
    protected $fillable = ['document_id', 'document_version_id', 'linkable_type', 'linkable_id', 'context'];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function version()
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }

    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }
}
