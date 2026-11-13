<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quote extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    public const STATUSES = ['Borrador', 'Emitida', 'Presentada', 'Sustituida', 'Anulada'];

    public const OUTCOMES = ['Pendiente', 'Ganada', 'Perdida'];

    /** Estados en los que el contenido (líneas, montos, datos) se puede editar. */
    public const EDITABLE_STATUSES = ['Borrador', 'Emitida'];

    /** Estados que admiten PDF adjuntos (una presentada sigue recibiendo documentos). */
    public const ATTACHABLE_STATUSES = ['Borrador', 'Emitida', 'Presentada'];

    /** Estados desde los que se puede registrar Ganada o Perdida. */
    public const ADJUDICABLE_STATUSES = ['Emitida', 'Presentada'];

    /** Estados finales: la cotización ya no cuenta como viva. */
    public const CLOSED_STATUSES = ['Sustituida', 'Anulada'];

    /**
     * Cambios de estado documental permitidos (el resultado comercial va aparte).
     *
     * @var array<string, array<int, string>>
     */
    public const TRANSITIONS = [
        'Borrador' => ['Emitida', 'Anulada'],
        'Emitida' => ['Borrador', 'Presentada', 'Sustituida', 'Anulada'],
        'Presentada' => ['Sustituida', 'Anulada'],
        'Sustituida' => [],
        'Anulada' => [],
    ];

    protected $fillable = [
        'company_id', 'institution_id', 'contact_id', 'created_by', 'prepared_by', 'number',
        'quoted_at', 'purchase_type', 'validity_days', 'status', 'outcome', 'awarded_amount',
        'awarded_at', 'winner_name', 'lost_reason', 'revision', 'subtotal', 'tax', 'discount',
        'total', 'notes', 'conditions', 'opportunity_id', 'tender_id', 'client_id', 'tax_included',
        'pricing_source',
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
            'validity_days' => 'integer',
            'tax_included' => 'boolean',
        ];
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function items()
    {
        return $this->hasMany(QuoteItem::class)->orderBy('position')->orderBy('id');
    }

    public function deliveryAlarms()
    {
        return $this->hasMany(DeliveryAlarm::class);
    }

    /** Participantes guardados desde esta cotización (para el proceso completo: QuoteParticipant::ofProcess). */
    public function participants()
    {
        return $this->hasMany(QuoteParticipant::class)->orderBy('position');
    }

    public function opportunity()
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function tender()
    {
        return $this->belongsTo(Tender::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /** Contrato vigente nacido de esta cotización (los cancelados por reversión no cuentan). */
    public function contract()
    {
        return $this->hasOne(Contract::class)->where('status', '!=', 'Cancelado')->latestOfMany();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function documents()
    {
        return $this->morphMany(DocumentLink::class, 'linkable');
    }

    public function isWon(): bool
    {
        return $this->outcome === 'Ganada';
    }

    public function isLost(): bool
    {
        return $this->outcome === 'Perdida';
    }

    public function isPending(): bool
    {
        return $this->outcome === 'Pendiente';
    }

    /** Líneas, montos y datos solo se editan en Borrador o Emitida y sin resultado. */
    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE_STATUSES, true) && $this->isPending();
    }

    public function acceptsAttachments(): bool
    {
        return in_array($this->status, self::ATTACHABLE_STATUSES, true);
    }

    public function isAdjudicable(): bool
    {
        return $this->isPending() && in_array($this->status, self::ADJUDICABLE_STATUSES, true);
    }

    /** Cotización viva: cuenta para el resultado de su oportunidad. */
    public function isLive(): bool
    {
        return ! in_array($this->status, self::CLOSED_STATUSES, true);
    }

    /**
     * Estados a los que puede pasar ahora (ninguno si ya tiene resultado).
     *
     * @return array<int, string>
     */
    public function nextStatuses(): array
    {
        return $this->isPending() ? (self::TRANSITIONS[$this->status] ?? []) : [];
    }

    /** Fecha hasta la que vale la oferta. */
    public function validUntil(): ?CarbonInterface
    {
        return $this->quoted_at?->copy()->addDays((int) $this->validity_days);
    }
}
