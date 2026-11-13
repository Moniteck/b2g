<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Contract extends Model
{
    use BelongsToCompany, HasFactory;

    /** Estados del expediente post-adjudicacion. */
    public const STATUSES = [
        'Pendiente de contrato' => 'Pendiente de contrato',
        'En ejecucion' => 'En ejecucion',
        'Entrega parcial' => 'Entrega parcial',
        'Entregado' => 'Entregado',
        'Cerrado' => 'Cerrado',
        'Suspendido' => 'Suspendido',
        'Cancelado' => 'Cancelado',
    ];

    protected $fillable = [
        'company_id', 'tender_id', 'quote_id', 'opportunity_id', 'institution_id', 'contact_id',
        'manager_id', 'number', 'name', 'amount', 'awarded_amount', 'billed_amount',
        'collected_amount', 'physical_progress', 'financial_progress', 'retention_amount',
        'status', 'signed_at', 'duration_days', 'duration_business_days', 'start_trigger',
        'start_date', 'end_date', 'starts_at', 'ends_at', 'product_delivered',
        'official_in_charge', 'completed_at', 'closed_at', 'notes',
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
            'starts_at' => 'date',
            'ends_at' => 'date',
            'completed_at' => 'date',
            'closed_at' => 'datetime',
            'product_delivered' => 'boolean',
            'duration_business_days' => 'boolean',
        ];
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

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function quote()
    {
        return $this->belongsTo(Quote::class);
    }

    public function opportunity()
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    /**
     * Calcula el vencimiento a partir de la duracion pactada y la fecha de inicio.
     */
    public function calculateEndDate(): ?string
    {
        if ($this->start_date === null || $this->duration_days === null) {
            return null;
        }

        $end = $this->duration_business_days
            ? $this->addBusinessDays($this->start_date->copy(), (int) $this->duration_days)
            : $this->start_date->copy()->addDays((int) $this->duration_days);

        return $end->toDateString();
    }

    private function addBusinessDays(Carbon $date, int $days): Carbon
    {
        $added = 0;
        $cursor = $date->copy();

        while ($added < $days) {
            $cursor->addDay();
            if ($cursor->isWeekday()) {
                $added++;
            }
        }

        return $cursor;
    }
}
