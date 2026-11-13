<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Services\Contracts\ContractStatus;
use App\Services\Contracts\ContractTerm;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Contract extends Model
{
    use BelongsToCompany, HasFactory;

    /** Estados del expediente post-adjudicación (clave = valor). Reglas en `ContractStatus`. */
    public const STATUSES = [
        ContractStatus::PENDING => ContractStatus::PENDING,
        ContractStatus::IN_PROGRESS => ContractStatus::IN_PROGRESS,
        ContractStatus::PARTIAL => ContractStatus::PARTIAL,
        ContractStatus::DELIVERED => ContractStatus::DELIVERED,
        ContractStatus::CLOSED => ContractStatus::CLOSED,
        ContractStatus::SUSPENDED => ContractStatus::SUSPENDED,
        ContractStatus::CANCELLED => ContractStatus::CANCELLED,
    ];

    /** Hitos desde los que corre el plazo. */
    public const START_TRIGGERS = [
        'Firma del contrato', 'Orden de inicio', 'Orden de compra', 'Pago de anticipo', 'Fecha fija',
    ];

    protected $fillable = [
        'company_id', 'client_id', 'tender_id', 'quote_id', 'opportunity_id', 'institution_id', 'contact_id',
        'manager_id', 'number', 'name', 'amount', 'awarded_amount', 'billed_amount',
        'collected_amount', 'physical_progress', 'financial_progress', 'retention_amount',
        'status', 'signed_at', 'duration_days', 'duration_business_days', 'start_trigger',
        'start_date', 'end_date', 'is_end_date_manual', 'product_delivered',
        'official_in_charge', 'completed_at', 'closed_at', 'notes', 'created_by', 'updated_by',
    ];

    protected $attributes = [
        'status' => ContractStatus::PENDING,
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'awarded_amount' => 'decimal:2',
            'billed_amount' => 'decimal:2',
            'collected_amount' => 'decimal:2',
            'retention_amount' => 'decimal:2',
            'physical_progress' => 'integer',
            'financial_progress' => 'integer',
            'signed_at' => 'date',
            'start_date' => 'date',
            'end_date' => 'date',
            'completed_at' => 'date',
            'closed_at' => 'datetime',
            'product_delivered' => 'boolean',
            'duration_business_days' => 'boolean',
            'is_end_date_manual' => 'boolean',
        ];
    }

    /** Compatibilidad: las columnas antiguas `starts_at`/`ends_at` se unificaron en `start_date`/`end_date`. */
    public function getStartsAtAttribute()
    {
        return $this->start_date;
    }

    public function getEndsAtAttribute()
    {
        return $this->end_date;
    }

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function deliveryCertificates()
    {
        return $this->hasMany(DeliveryCertificate::class);
    }

    public function deliveries()
    {
        return $this->hasMany(ContractDelivery::class);
    }

    public function followUps()
    {
        return $this->hasMany(ContractFollowUp::class);
    }

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function quote()
    {
        return $this->belongsTo(Quote::class);
    }

    public function opportunity()
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function tender()
    {
        return $this->belongsTo(Tender::class);
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function isOpen(): bool
    {
        return ContractStatus::isOpen($this->status);
    }

    /**
     * Fecha fin según el plazo (días calendario, o hábiles sin feriados de la empresa).
     */
    public function calculateEndDate(): ?string
    {
        return app(ContractTerm::class)->endDate(
            $this->start_date,
            $this->duration_days !== null ? (int) $this->duration_days : null,
            (bool) $this->duration_business_days,
            $this->company_id,
        );
    }
}
