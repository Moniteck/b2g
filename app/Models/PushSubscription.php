<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Suscripción Web Push de un usuario en un dispositivo. Ver `App\Services\Pwa\WebPushService`.
 */
class PushSubscription extends Model
{
    protected $fillable = [
        'user_id', 'endpoint', 'endpoint_hash', 'public_key', 'auth_token', 'content_encoding',
        'device_label', 'last_success_at', 'last_failure_at', 'failures',
    ];

    protected $hidden = ['endpoint', 'public_key', 'auth_token'];

    protected function casts(): array
    {
        return [
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
            'failures' => 'integer',
        ];
    }

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
