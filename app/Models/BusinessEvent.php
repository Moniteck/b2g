<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Bandeja de eventos de negocio. Permite reintentar efectos secundarios
 * (recordatorios, sincronizaciones, notificaciones) sin perderlos.
 */
class BusinessEvent extends Model
{
    protected $fillable = [
        'type', 'company_id', 'payload', 'processed_at', 'attempts', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function isPending(): bool
    {
        return $this->processed_at === null;
    }
}
