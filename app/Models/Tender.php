<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tender extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'institution_id', 'owner_id', 'reference', 'process_name', 'title',
        'purchase_type', 'status', 'estimated_amount', 'published_at', 'closing_date',
        'clarification_deadline', 'presentation_deadline', 'opening_at', 'source_url',
        'contact_name', 'contact_phone', 'contact_email', 'requirements', 'delivery_terms',
        'payment_terms', 'documents', 'observations', 'offer_submitted', 'result', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'estimated_amount' => 'decimal:2',
            'published_at' => 'date',
            'closing_date' => 'date',
            'clarification_deadline' => 'datetime',
            'presentation_deadline' => 'datetime',
            'opening_at' => 'datetime',
            'documents' => 'array',
            'offer_submitted' => 'boolean',
        ];
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function opportunities()
    {
        return $this->hasMany(Opportunity::class);
    }

    public function quotes()
    {
        return $this->hasMany(Quote::class);
    }

    /**
     * Fechas relevantes del proceso, en orden cronologico.
     *
     * @return array<string, string>
     */
    public function deadlines(): array
    {
        return array_filter([
            'Publicacion' => $this->published_at?->toDateString(),
            'Aclaraciones' => $this->clarification_deadline?->toDateTimeString(),
            'Presentacion' => $this->presentation_deadline?->toDateTimeString(),
            'Cierre' => $this->closing_date?->toDateString(),
            'Apertura' => $this->opening_at?->toDateTimeString(),
        ]);
    }
}
