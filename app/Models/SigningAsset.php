<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SigningAsset extends Model
{
    public const TYPES = ['firma' => 'Firma', 'sello' => 'Sello'];

    protected $fillable = [
        'company_id', 'type', 'name', 'path', 'disk', 'mime_type', 'width', 'height',
        'is_active', 'uploaded_by', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'width' => 'integer',
            'height' => 'integer',
            'revoked_at' => 'datetime',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isUsable(): bool
    {
        return $this->is_active && $this->revoked_at === null;
    }
}
