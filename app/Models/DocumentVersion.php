<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentVersion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'document_id', 'version', 'path', 'disk', 'original_filename', 'mime_type', 'size',
        'hash', 'uploaded_by', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'size' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function document() { return $this->belongsTo(Document::class); }

    public function uploader() { return $this->belongsTo(User::class, 'uploaded_by'); }

    public function applications() { return $this->hasMany(SignatureApplication::class); }

    public function extractions() { return $this->hasMany(DocumentExtraction::class); }
}
