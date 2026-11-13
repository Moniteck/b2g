<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ReminderRule extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'event_type', 'days_before', 'channels', 'recipients', 'is_active'];

    protected function casts(): array
    {
        return [
            'days_before' => 'array',
            'channels' => 'array',
            'recipients' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Reglas por defecto cuando la empresa no define las suyas.
     *
     * @return array{event_type: string, days_before: array<int>, channels: array<int>}
     */
    public static function defaults(): array
    {
        return [
            ['event_type' => 'Aclaraciones', 'days_before' => [7, 3, 1], 'channels' => ['correo', 'bandeja']],
            ['event_type' => 'Presentacion', 'days_before' => [7, 3, 1], 'channels' => ['correo', 'bandeja']],
            ['event_type' => 'Apertura', 'days_before' => [1], 'channels' => ['correo', 'bandeja']],
            ['event_type' => 'Contrato', 'days_before' => [7, 1], 'channels' => ['correo', 'bandeja']],
            ['event_type' => 'Entrega', 'days_before' => [3, 1], 'channels' => ['correo', 'bandeja']],
            ['event_type' => 'Documento', 'days_before' => [30, 7, 1], 'channels' => ['correo', 'bandeja']],
        ];
    }
}
