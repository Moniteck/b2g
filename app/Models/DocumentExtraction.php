<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentExtraction extends Model
{
    protected $fillable = [
        'document_version_id', 'status', 'extracted', 'evidence', 'confidence', 'method',
        'error', 'reviewed_at', 'reviewed_by',
    ];

    protected function casts(): array
    {
        return [
            'extracted' => 'array',
            'evidence' => 'array',
            'confidence' => 'decimal:2',
            'reviewed_at' => 'datetime',
        ];
    }

    public function version() { return $this->belongsTo(DocumentVersion::class, 'document_version_id'); }

    public function reviewer() { return $this->belongsTo(User::class, 'reviewed_by'); }
}
