<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'company_id', 'channel', 'actor_name', 'action', 'resource_type',
        'resource_id', 'request_id', 'changes', 'ip', 'user_agent', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user() { return $this->belongsTo(User::class); }

    public function company() { return $this->belongsTo(Company::class); }
}
