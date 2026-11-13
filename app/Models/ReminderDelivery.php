<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ReminderDelivery extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['Programado', 'Encolado', 'Enviado', 'Fallido', 'Leido', 'Cancelado'];

    protected $fillable = [
        'event_id', 'company_id', 'channel', 'recipient', 'status', 'scheduled_for', 'sent_at',
        'read_at', 'attempts', 'error', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'sent_at' => 'datetime',
            'read_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['Programado', 'Encolado'], true);
    }
}
