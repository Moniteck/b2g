<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quote extends Model
{
    use BelongsToCompany, SoftDeletes;

    public const STATUSES = ['Borrador', 'Emitida', 'Presentada', 'Sustituida', 'Anulada'];

    public const OUTCOMES = ['Pendiente', 'Ganada', 'Perdida'];

    protected $fillable = [
        'company_id', 'institution_id', 'contact_id', 'created_by', 'prepared_by', 'number',
        'quoted_at', 'purchase_type', 'validity_days', 'status', 'outcome', 'awarded_amount',
        'awarded_at', 'winner_name', 'lost_reason', 'revision', 'subtotal', 'tax', 'discount',
        'total', 'notes', 'opportunity_id', 'tender_id',
    ];

    protected function casts(): array
    {
        return [
            'quoted_at' => 'date',
            'awarded_at' => 'date',
            'subtotal' => 'decimal:2',
            'tax' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
            'awarded_amount' => 'decimal:2',
            'revision' => 'integer',
        ];
    }

    public function institution() { return $this->belongsTo(Institution::class); }

    public function contact() { return $this->belongsTo(Contact::class); }

    public function items() { return $this->hasMany(QuoteItem::class); }

    public function deliveryAlarms() { return $this->hasMany(DeliveryAlarm::class); }

    public function participants() { return $this->hasMany(QuoteParticipant::class)->orderBy('position'); }

    public function opportunity() { return $this->belongsTo(Opportunity::class); }

    public function tender() { return $this->belongsTo(Tender::class); }

    public function creator() { return $this->belongsTo(User::class, 'created_by'); }

    public function documents() { return $this->morphMany(DocumentLink::class, 'linkable'); }

    public function isWon(): bool { return $this->outcome === 'Ganada'; }

    public function isLost(): bool { return $this->outcome === 'Perdida'; }

    public function isEditable(): bool { return in_array($this->status, ['Borrador', 'Emitida'], true); }
}
