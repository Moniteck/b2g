<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Un aviso concreto: evento × anticipación × canal × destinatario × fecha del plazo.
 *
 * Estados: Programado → Encolado (reclamado por el despachador) → Enviado | Fallido | Cancelado.
 * La campana se marca leída con `read_at`.
 */
class ReminderDelivery extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['Programado', 'Encolado', 'Enviado', 'Fallido', 'Cancelado'];

    public const PENDING = ['Programado', 'Encolado'];

    public const CHANNELS = ['correo' => 'Correo', 'campana' => 'Campana'];

    protected $fillable = [
        'event_id', 'company_id', 'user_id', 'channel', 'offset_key', 'recipient', 'status', 'scheduled_for',
        'due_at', 'sent_at', 'read_at', 'attempts', 'error', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'due_at' => 'datetime',
            'sent_at' => 'datetime',
            'read_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return in_array($this->status, self::PENDING, true);
    }

    /** «7 días antes», «el mismo día», «aviso inmediato». */
    public function offsetLabel(): string
    {
        return self::labelForOffset($this->offset_key);
    }

    public static function labelForOffset(?string $offset): string
    {
        if ($offset === 'now') {
            return 'Aviso inmediato';
        }

        $days = (int) ltrim((string) $offset, 'd');

        return match ($days) {
            0 => 'El mismo día',
            1 => '1 día antes',
            default => "{$days} días antes",
        };
    }
}
