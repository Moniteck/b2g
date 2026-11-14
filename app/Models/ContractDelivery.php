<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Entrega comprometida en un contrato. Su estado es independiente del estado del contrato.
 */
class ContractDelivery extends Model
{
    use HasFactory;

    public const PENDING = 'Pendiente';

    public const DELIVERED = 'Entregada';

    public const REJECTED = 'Rechazada';

    public const CANCELLED = 'Cancelada';

    /** Coinciden con `CalendarSyncService::DONE_DELIVERY_STATUSES` (Entregada y Cancelada cierran el plazo). */
    public const STATUSES = [self::PENDING, self::DELIVERED, self::REJECTED, self::CANCELLED];

    /** Valores antiguos que todavía cuentan como entregada. */
    public const DELIVERED_VALUES = [self::DELIVERED, 'Entregado', 'Recibida'];

    protected $fillable = [
        'contract_id', 'description', 'quantity', 'unit', 'due_date', 'delivered_at',
        'status', 'evidence_document_id', 'notes', 'created_by', 'updated_by',
    ];

    protected $attributes = [
        'status' => self::PENDING,
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'due_date' => 'date',
            'delivered_at' => 'datetime',
        ];
    }

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    public function evidence()
    {
        return $this->belongsTo(Document::class, 'evidence_document_id');
    }

    public function isDelivered(): bool
    {
        return in_array($this->status, self::DELIVERED_VALUES, true);
    }

    /** Cuenta para el avance: las canceladas no se esperan. */
    public function isCounted(): bool
    {
        return $this->status !== self::CANCELLED;
    }
}
