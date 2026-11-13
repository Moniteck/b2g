<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SignatureApplication extends Model
{
    protected $fillable = [
        'document_version_id', 'signing_asset_id', 'user_id', 'company_id', 'page', 'x', 'y',
        'width', 'height', 'rotation', 'document_hash', 'authorization_id', 'result_path', 'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'page' => 'integer',
            'x' => 'decimal:2',
            'y' => 'decimal:2',
            'width' => 'decimal:2',
            'height' => 'decimal:2',
            'rotation' => 'decimal:2',
            'applied_at' => 'datetime',
        ];
    }

    public function version() { return $this->belongsTo(DocumentVersion::class, 'document_version_id'); }

    public function asset() { return $this->belongsTo(SigningAsset::class, 'signing_asset_id'); }

    public function user() { return $this->belongsTo(User::class); }

    public function authorization() { return $this->belongsTo(McpAuthorization::class, 'authorization_id'); }
}
