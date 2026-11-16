<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Solicitud de acceso de una persona que aún no tiene cuenta. Estados: pending → approved | rejected.
 * Solo llega a los revisores cuando la persona confirma su correo (`email_verified_at`).
 * La contraseña se guarda cifrada y nunca sale hacia el navegador.
 */
class AccessRequest extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $fillable = [
        'name', 'email', 'phone', 'position', 'company_id', 'company_name', 'password', 'status', 'ip_address', 'email_verified_at',
        'reviewed_by', 'reviewed_at', 'rejection_reason', 'user_id', 'approved_company_id', 'approved_role',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'reviewed_at' => 'datetime',
            'email_verified_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @param  Builder<AccessRequest>  $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', self::PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    /** Huella del correo que viaja en el enlace firmado de confirmación. */
    public function emailHash(): string
    {
        return hash('sha256', 'access-request-email:'.$this->getKey().':'.$this->email);
    }

    /** @param  Builder<AccessRequest>  $query */
    public function scopeVerified(Builder $query): void
    {
        $query->whereNotNull('email_verified_at');
    }
}
