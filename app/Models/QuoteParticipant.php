<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Oferente de un proceso. Pertenece a la oportunidad (`opportunity_id`) cuando la hay, y a la
 * cotización desde la que se registró (`quote_id`, opcional: el kanban los registra sin cotización).
 * Una fila es «nuestra oferta» (`is_ours`) y, conocido el resultado, una es el ganador (`is_winner`).
 */
class QuoteParticipant extends Model
{
    use HasFactory;

    protected $fillable = [
        'quote_id', 'opportunity_id', 'name', 'tax_id', 'amount', 'currency', 'includes_tax', 'is_ours',
        'is_winner', 'position', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'includes_tax' => 'boolean',
            'is_ours' => 'boolean',
            'is_winner' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function quote()
    {
        return $this->belongsTo(Quote::class);
    }

    public function opportunity()
    {
        return $this->belongsTo(Opportunity::class);
    }

    /**
     * Participantes del proceso: los de la oportunidad (si la hay) más los guardados desde la cotización.
     */
    public function scopeOfProcess(Builder $query, ?int $quoteId, ?int $opportunityId): Builder
    {
        return $query->where(function (Builder $builder) use ($quoteId, $opportunityId) {
            if ($opportunityId !== null) {
                $builder->orWhere('opportunity_id', $opportunityId);
            }
            if ($quoteId !== null) {
                $builder->orWhere('quote_id', $quoteId);
            }
            if ($opportunityId === null && $quoteId === null) {
                $builder->whereRaw('1 = 0');
            }
        });
    }
}
