<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    use BelongsToCompany;

    /** Tipos de evento y su origen. */
    public const TYPES = [
        'Manual' => 'Evento manual',
        'Aclaraciones' => 'Cierre de aclaraciones',
        'Presentacion' => 'Cierre de presentacion',
        'Apertura' => 'Apertura de ofertas',
        'Licitacion' => 'Inicio de licitacion',
        'Contrato' => 'Vigencia de contrato',
        'Entrega' => 'Entrega de producto',
        'Documento' => 'Vencimiento documental',
    ];

    protected $fillable = [
        'company_id', 'user_id', 'institution_id', 'opportunity_id', 'tender_id', 'title',
        'description', 'type', 'starts_at', 'ends_at', 'priority', 'reference_type', 'reference_id',
        'completed', 'completed_at', 'remindable', 'reminder_offsets', 'recipients', 'origin',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'completed_at' => 'datetime',
            'completed' => 'boolean',
            'remindable' => 'boolean',
            'reminder_offsets' => 'array',
            'recipients' => 'array',
        ];
    }

    public function user() { return $this->belongsTo(User::class); }

    public function institution() { return $this->belongsTo(Institution::class); }

    public function deliveries() { return $this->hasMany(ReminderDelivery::class); }

    public function isDerived(): bool
    {
        return $this->origin !== 'manual';
    }
}
