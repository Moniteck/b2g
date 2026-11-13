<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    use BelongsToCompany, HasFactory;

    /** Tipo de expediente documental. */
    public const KINDS = [
        'empresa' => 'Documento de empresa',
        'institucion' => 'Documento de institucion',
        'proceso' => 'Documento de proceso',
        'contrato' => 'Documento de contrato',
    ];

    /** Categorias del directorio documental de la empresa. */
    public const CATEGORIES = [
        'RTN' => 'RTN',
        'DNI' => 'DNI del representante',
        'Solvencia' => 'Solvencia',
        'Escritura' => 'Escritura de constitucion',
        'Poder' => 'Poder del representante',
        'Registro' => 'Registro y constancias',
        'Otro' => 'Otro documento',
    ];

    protected $fillable = [
        'company_id', 'institution_id', 'name', 'kind', 'owner_name', 'required_for', 'category',
        'version', 'parent_id', 'is_current', 'hash', 'path', 'issued_at', 'expires_at', 'status',
        'original_filename', 'disk', 'mime_type', 'size', 'uploaded_by', 'signed_at', 'signed_by',
        'sealed_at', 'renewal_owner_id',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'date',
            'expires_at' => 'date',
            'signed_at' => 'datetime',
            'sealed_at' => 'datetime',
            'is_current' => 'boolean',
            'version' => 'integer',
            'size' => 'integer',
        ];
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function versions()
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version');
    }

    public function currentVersion()
    {
        return $this->hasOne(DocumentVersion::class)->where('is_current', true);
    }

    public function links()
    {
        return $this->hasMany(DocumentLink::class);
    }

    public function signer()
    {
        return $this->belongsTo(User::class, 'signed_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }
}
