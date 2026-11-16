<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Tender extends Model
{
    use BelongsToCompany, HasFactory;

    /** Estados internos del proceso (no la etapa de HonduCompras). */
    public const STATUSES = ['Activa', 'En preparación', 'Presentada', 'Adjudicada', 'No adjudicada', 'Perdida', 'Desierta', 'Cancelada', 'Vencida'];

    /** Estados en los que la licitación sigue viva y sus plazos importan. */
    public const OPEN_STATUSES = ['Activa', 'En preparación'];

    /** Modalidades de contratación habituales en HonduCompras (lista abierta). */
    public const MODALITIES = [
        'Licitación Pública Nacional', 'Licitación Pública Internacional', 'Licitación Privada',
        'Concurso Público', 'Concurso privado', 'Compra Menor', 'Contratación Directa',
        'Subasta Inversa', 'Convenio Marco',
    ];

    protected $fillable = [
        'company_id', 'institution_id', 'owner_id', 'reference', 'process_name', 'title',
        'purchase_type', 'status', 'estimated_amount', 'published_at', 'closing_date',
        'clarification_deadline', 'presentation_deadline', 'opening_at', 'source_url',
        'contact_name', 'contact_phone', 'contact_email', 'requirements', 'delivery_terms',
        'payment_terms', 'documents', 'observations', 'offer_submitted', 'result', 'notes',
        'purchasing_unit', 'started_at', 'funding_source_type', 'funding_source',
        'honducompras_stage', 'acquisition_type', 'offer_reception_place', 'bid_document_fee',
        'offer_validity_days',
    ];

    /**
     * Una licitación «Vencida» a la que luego se le registra la oferta (p. ej. al presentar su
     * cotización) vuelve a «Presentada»: la oferta manda sobre el estado automático (B-19).
     */
    protected static function booted(): void
    {
        static::saving(function (Tender $tender) {
            if ($tender->status === 'Vencida' && $tender->offer_submitted) {
                $tender->status = 'Presentada';
            }
        });
    }

    protected function casts(): array
    {
        return [
            'estimated_amount' => 'decimal:2',
            'bid_document_fee' => 'decimal:2',
            'offer_validity_days' => 'integer',
            'published_at' => 'date',
            'started_at' => 'datetime',
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

    /** La oportunidad del pipeline de esta licitación (decisión 13: una por licitación). */
    public function opportunity(): HasOne
    {
        return $this->hasOne(Opportunity::class)->oldestOfMany();
    }

    public function quotes()
    {
        return $this->hasMany(Quote::class);
    }

    public function documentLinks(): MorphMany
    {
        return $this->morphMany(DocumentLink::class, 'linkable');
    }

    public function extractions(): HasMany
    {
        return $this->hasMany(DocumentExtraction::class);
    }

    public function complianceMatrices(): HasMany
    {
        return $this->hasMany(ComplianceMatrix::class);
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
            'Inicio' => $this->started_at?->toDateTimeString(),
            'Aclaraciones' => $this->clarification_deadline?->toDateTimeString(),
            'Presentacion' => $this->presentation_deadline?->toDateTimeString(),
            'Cierre' => $this->closing_date?->toDateString(),
            'Apertura' => $this->opening_at?->toDateTimeString(),
        ]);
    }
}
