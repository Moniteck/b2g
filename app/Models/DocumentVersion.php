<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Versión inmutable del archivo de un documento. Nunca se sobrescribe: reemplazar, renovar o
 * firmar crean una versión nueva y la anterior queda disponible en el historial.
 */
class DocumentVersion extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'document_id', 'version', 'path', 'disk', 'original_filename', 'mime_type', 'size',
        'hash', 'issued_at', 'expires_at', 'note', 'signed_at', 'signed_by', 'uploaded_by', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'size' => 'integer',
            'issued_at' => 'date',
            'expires_at' => 'date',
            'signed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected function issuedAt(): Attribute
    {
        return Attribute::set(fn ($value) => Document::dateOnly($value));
    }

    protected function expiresAt(): Attribute
    {
        return Attribute::set(fn ($value) => Document::dateOnly($value));
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function signer()
    {
        return $this->belongsTo(User::class, 'signed_by');
    }

    public function applications()
    {
        return $this->hasMany(SignatureApplication::class);
    }

    public function extractions()
    {
        return $this->hasMany(DocumentExtraction::class);
    }

    public function isSigned(): bool
    {
        return $this->signed_at !== null;
    }
}
