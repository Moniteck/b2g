<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CollectionInvoice extends Model
{
    use BelongsToCompany;

    public const STATUS_PENDING = 'Pendiente';

    public const STATUS_PAID = 'Pagada';

    protected $fillable = ['company_id', 'client_id', 'institution_id', 'number', 'concept', 'amount', 'issued_at', 'due_at', 'paid_at', 'status', 'interest_amount', 'interest_rate', 'collection_note', 'payment_recorded_at', 'notes'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'interest_amount' => 'decimal:2', 'interest_rate' => 'decimal:2', 'issued_at' => 'date', 'due_at' => 'date', 'paid_at' => 'date', 'payment_recorded_at' => 'datetime'];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isOverdue(): bool
    {
        return ! $this->isPaid() && $this->due_at !== null && $this->due_at->lt(today());
    }

    /** Estado que se muestra: una pendiente con el vencimiento pasado es «Vencida». */
    public function displayStatus(): string
    {
        return $this->isOverdue() ? 'Vencida' : (string) $this->status;
    }

    /** Interés moratorio simple (tasa anual) por los días de atraso a la fecha de pago. */
    public function interestFor(DateTimeInterface $paidAt): float
    {
        $days = max(0, (int) $this->due_at->diffInDays($paidAt, false));

        return round((float) $this->amount * ((float) $this->interest_rate / 100) * $days / 365, 2);
    }

    /** @param  Builder<static>  $query */
    public function scopeUnpaid(Builder $query): Builder
    {
        return $query->where('status', '!=', self::STATUS_PAID);
    }

    /** @param  Builder<static>  $query */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', '!=', self::STATUS_PAID)->whereDate('due_at', '<', today());
    }
}
