<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class McpConnection extends Model
{
    protected $fillable = [
        'user_id', 'name', 'client_id', 'client_name', 'company_ids', 'scopes',
        'token_hash', 'last_used_at', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'company_ids' => 'array',
            'scopes' => 'array',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user() { return $this->belongsTo(User::class); }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    public function allowsCompany(?int $companyId): bool
    {
        if ($companyId === null) {
            return false;
        }

        if ($this->company_ids === null || $this->company_ids === []) {
            return true;
        }

        return in_array($companyId, array_map('intval', $this->company_ids), true);
    }
}
