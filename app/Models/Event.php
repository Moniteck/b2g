<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Evento del calendario: manual o derivado de un plazo de negocio.
 *
 * Los derivados se identifican por `source_key` (`origen:id:tipo`) y se actualizan en su sitio;
 * nunca se borran: si el origen se elimina o se cierra quedan con `cancelled_at`.
 */
class Event extends Model
{
    use BelongsToCompany, HasFactory;

    /** Tipos de plazo (clave guardada => etiqueta). */
    public const TYPES = [
        'Licitacion' => 'Inicio de licitación',
        'Aclaraciones' => 'Cierre de aclaraciones',
        'Presentacion' => 'Presentación de ofertas',
        'Apertura' => 'Apertura de ofertas',
        'Oportunidad' => 'Cierre esperado de oportunidad',
        'InicioContrato' => 'Inicio de contrato',
        'FinContrato' => 'Fin de contrato',
        'Entrega' => 'Entrega de contrato',
        'Seguimiento' => 'Próxima acción de seguimiento',
        'Documento' => 'Vencimiento de documento',
        'Manual' => 'Evento manual',
    ];

    /** Frase del asunto del correo por tipo: «Mañana cierra el plazo de aclaraciones: LPN-007». */
    public const SUBJECT_PHRASES = [
        'Licitacion' => 'inicia la licitación',
        'Aclaraciones' => 'cierra el plazo de aclaraciones',
        'Presentacion' => 'cierra la presentación de ofertas',
        'Apertura' => 'es la apertura de ofertas',
        'Oportunidad' => 'es el cierre esperado de la oportunidad',
        'InicioContrato' => 'inicia el contrato',
        'FinContrato' => 'vence el contrato',
        'Entrega' => 'vence la entrega',
        'Seguimiento' => 'toca la próxima acción de seguimiento',
        'Documento' => 'vence el documento',
        'Manual' => 'es el evento',
    ];

    /** Tipo de registro de origen => clave `kind` del dashboard. */
    public const KINDS = [
        'tender' => 'tender',
        'opportunity' => 'tender',
        'contract' => 'contract',
        'contract_delivery' => 'contract',
        'contract_follow_up' => 'contract',
        'document' => 'document',
        'manual' => 'event',
    ];

    protected $fillable = [
        'company_id', 'owner_id', 'created_by', 'institution_id', 'opportunity_id', 'tender_id',
        'contract_id', 'title', 'label', 'description', 'type', 'starts_at', 'ends_at', 'all_day', 'priority',
        'reference_type', 'reference_id', 'completed_at', 'remindable', 'reminder_offsets',
        'recipients', 'origin', 'source_key', 'attended_at', 'attended_by', 'cancelled_at', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'completed_at' => 'datetime',
            'attended_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'remindable' => 'boolean',
            'all_day' => 'boolean',
            'reminder_offsets' => 'array',
            'recipients' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function attendedBy()
    {
        return $this->belongsTo(User::class, 'attended_by');
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function deliveries()
    {
        return $this->hasMany(ReminderDelivery::class);
    }

    public function isDerived(): bool
    {
        return $this->origin !== 'manual';
    }

    public function isManual(): bool
    {
        return ! $this->isDerived();
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? (string) $this->type;
    }

    /**
     * Momento en que el plazo deja de estar vigente (fin del día si es de día completo).
     */
    public function deadline(): ?Carbon
    {
        if ($this->starts_at === null) {
            return null;
        }

        return $this->all_day ? $this->starts_at->copy()->endOfDay() : $this->starts_at->copy();
    }

    /** Completado en su registro de origen. */
    protected function completed(): Attribute
    {
        return Attribute::get(fn (): bool => $this->completed_at !== null);
    }

    /** Sigue generando avisos: no cancelado, no atendido, no completado y con fecha por delante. */
    public function isActive(?Carbon $now = null): bool
    {
        $deadline = $this->deadline();

        return $this->cancelled_at === null
            && $this->attended_at === null
            && ! $this->completed
            && $deadline !== null
            && $deadline->greaterThan($now ?? now());
    }

    /** Eventos visibles en el calendario: los cancelados por su origen no se muestran. */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('cancelled_at'));
    }

    /** Eventos que todavía piden atención (para plazos del dashboard y resumen diario). */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('cancelled_at'))
            ->whereNull($query->qualifyColumn('attended_at'))
            ->whereNull($query->qualifyColumn('completed_at'));
    }

    /** Enlace al registro de origen dentro de la aplicación. */
    public function sourceHref(): string
    {
        return match ($this->reference_type) {
            'tender' => '/licitaciones?licitacion='.$this->reference_id,
            'opportunity' => '/pipeline/'.$this->reference_id,
            'contract' => '/post-adjudicacion?contrato='.$this->reference_id,
            'contract_delivery', 'contract_follow_up' => '/post-adjudicacion?contrato='.$this->contract_id,
            'document' => '/directorio?documento='.$this->reference_id,
            default => '/calendario?evento='.$this->id,
        };
    }

    public function sourceLabel(): string
    {
        return match ($this->reference_type) {
            'tender' => 'Licitación',
            'opportunity' => 'Oportunidad',
            'contract' => 'Contrato',
            'contract_delivery' => 'Entrega de contrato',
            'contract_follow_up' => 'Seguimiento de contrato',
            'document' => 'Documento',
            default => 'Evento manual',
        };
    }
}
