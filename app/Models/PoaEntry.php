<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Partida del Plan Operativo Anual de una institución que la empresa sigue
 * como oportunidad de compra. Tiene alerta cuando su fecha estimada se acerca
 * o cuando está marcada para el cierre del año fiscal.
 */
class PoaEntry extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['Identificada', 'En seguimiento', 'Comprometida', 'Ejecutada', 'Descartada'];

    public const STATUS_EXECUTED = 'Ejecutada';

    /** Estados que ya no generan alerta. */
    public const CLOSED_STATUSES = ['Ejecutada', 'Descartada'];

    /** Días de anticipación de la alerta por fecha estimada. */
    public const ALERT_DAYS = 30;

    protected $fillable = ['company_id', 'institution_id', 'project', 'year', 'budget_line', 'quarter', 'probability', 'budgeted_amount', 'executed_amount', 'estimated_date', 'annual_close_alert', 'status', 'notes'];

    protected function casts(): array
    {
        return ['budgeted_amount' => 'decimal:2', 'executed_amount' => 'decimal:2', 'estimated_date' => 'date', 'annual_close_alert' => 'boolean', 'year' => 'integer', 'quarter' => 'integer', 'probability' => 'integer'];
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED_STATUSES, true);
    }

    /** Abierta y con fecha estimada vencida o dentro de los próximos 30 días, o marcada para el cierre anual. */
    public function hasAlert(): bool
    {
        if ($this->isClosed()) {
            return false;
        }

        return $this->annual_close_alert
            || ($this->estimated_date !== null && $this->estimated_date->lte(today()->addDays(self::ALERT_DAYS)));
    }

    /** @param  Builder<static>  $query */
    public function scopeWithAlert(Builder $query): Builder
    {
        return $query->whereNotIn('status', self::CLOSED_STATUSES)->where(fn (Builder $inner) => $inner
            ->where('annual_close_alert', true)
            ->orWhereDate('estimated_date', '<=', today()->addDays(self::ALERT_DAYS)));
    }
}
