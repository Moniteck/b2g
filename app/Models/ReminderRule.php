<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * Regla de avisos por empresa y tipo de plazo. Sin regla, se usan los valores por defecto del diseño.
 *
 * `days_before`: días de anticipación; 0 es el mismo día. Todos se envían a `send_time` (HH:MM)
 * del día que toca; el del mismo día se adelanta a una hora antes si el plazo es más temprano.
 */
class ReminderRule extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'event_type', 'days_before', 'send_time', 'channels', 'recipients', 'is_active'];

    protected function casts(): array
    {
        return [
            'days_before' => 'array',
            'channels' => 'array',
            'recipients' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** La columna es `time` (HH:MM:SS); la aplicación trabaja con HH:MM. */
    protected function sendTime(): Attribute
    {
        return Attribute::get(fn (?string $value): ?string => $value === null ? null : substr($value, 0, 5));
    }

    /**
     * Reglas por defecto (tabla de la Fase 6 del diseño).
     *
     * @return array<string, array{days_before: array<int>, send_time: string, channels: array<int, string>}>
     */
    public static function defaults(): array
    {
        $both = ['correo', 'campana', 'push'];

        return [
            'Licitacion' => ['days_before' => [], 'send_time' => '07:00', 'channels' => $both],
            'Aclaraciones' => ['days_before' => [7, 3, 2, 1, 0], 'send_time' => '08:00', 'channels' => $both],
            'Presentacion' => ['days_before' => [7, 3, 2, 1, 0], 'send_time' => '07:00', 'channels' => $both],
            'Apertura' => ['days_before' => [1, 0], 'send_time' => '07:00', 'channels' => $both],
            'Oportunidad' => ['days_before' => [1], 'send_time' => '07:00', 'channels' => ['campana']],
            'InicioContrato' => ['days_before' => [1], 'send_time' => '07:00', 'channels' => $both],
            'FinContrato' => ['days_before' => [30, 15, 7, 1], 'send_time' => '07:00', 'channels' => $both],
            'Entrega' => ['days_before' => [7, 3, 1, 0], 'send_time' => '07:00', 'channels' => $both],
            'Seguimiento' => ['days_before' => [0], 'send_time' => '07:00', 'channels' => $both],
            'Documento' => ['days_before' => [30, 7, 1], 'send_time' => '07:00', 'channels' => $both],
            'Manual' => ['days_before' => [1, 0], 'send_time' => '07:00', 'channels' => $both],
        ];
    }
}
