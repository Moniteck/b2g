<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Opportunity extends Model
{
    use BelongsToCompany, HasFactory;

    /** Etapas del tablero comercial. */
    public const STAGES = [
        'prospecto' => 'Prospecto',
        'oportunidad' => 'Oportunidad',
        'oferta_presentada' => 'Oferta Presentada',
        'adjudicado' => 'Adjudicado',
        'no_adjudicado' => 'No Adjudicado',
    ];

    /** Etapas que cuentan como pipeline activo (aún sin resultado). */
    public const ACTIVE_STAGES = ['prospecto', 'oportunidad', 'oferta_presentada'];

    /** Etapas de resultado: no son pipeline activo. */
    public const CLOSED_STAGES = ['adjudicado', 'no_adjudicado'];

    /** Etapas en las que se puede crear una oportunidad (las demás se alcanzan moviéndola). */
    public const CREATABLE_STAGES = ['prospecto', 'oportunidad'];

    /**
     * Transiciones del tablero (Fase 8): avanzar de una en una, retroceder una etapa y, desde
     * Oferta Presentada, registrar el resultado. Salir de un resultado solo con «Revertir resultado»
     * (AdjudicationService::revert), nunca moviendo la tarjeta. Única fuente de verdad: la usan el
     * kanban (OpportunityStageService), la interfaz (prop `transitions`) y el MCP.
     *
     * @var array<string, array<int, string>>
     */
    public const TRANSITIONS = [
        'prospecto' => ['oportunidad'],
        'oportunidad' => ['prospecto', 'oferta_presentada'],
        'oferta_presentada' => ['oportunidad', 'adjudicado', 'no_adjudicado'],
        'adjudicado' => [],
        'no_adjudicado' => [],
    ];

    protected $fillable = [
        'company_id', 'institution_id', 'contact_id', 'owner_id', 'title', 'opportunity_type',
        'amount', 'probability', 'stage', 'next_action', 'expected_close_date', 'notes',
        'tender_id', 'quote_id', 'winner_name', 'awarded_amount', 'awarded_at', 'lost_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'awarded_amount' => 'decimal:2',
            'expected_close_date' => 'date',
            'awarded_at' => 'date',
            'probability' => 'integer',
        ];
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function tender()
    {
        return $this->belongsTo(Tender::class);
    }

    public function quote()
    {
        return $this->belongsTo(Quote::class);
    }

    /** Participantes del proceso registrados en la oportunidad (kanban o cotización). */
    public function participants()
    {
        return $this->hasMany(QuoteParticipant::class);
    }

    /** Cotizaciones de la oportunidad (decisión 13: puede tener varias). */
    public function quotes()
    {
        return $this->hasMany(Quote::class);
    }

    public function stageLabel(): string
    {
        return self::STAGES[$this->stage] ?? ucfirst((string) $this->stage);
    }

    public function hasOutcome(): bool
    {
        return in_array($this->stage, self::CLOSED_STAGES, true);
    }

    /** Etapas a las que se puede mover desde la actual. */
    public function allowedStages(): array
    {
        return self::TRANSITIONS[$this->stage] ?? [];
    }

    public function canMoveTo(string $stage): bool
    {
        return in_array($stage, $this->allowedStages(), true);
    }

    /**
     * Por qué no se puede mover a la etapa (null si se puede). Mensaje listo para el usuario.
     */
    public function transitionError(string $stage): ?string
    {
        if (! array_key_exists($stage, self::STAGES)) {
            return 'La etapa elegida no existe.';
        }
        if ($stage === $this->stage) {
            return null;
        }
        if ($this->hasOutcome()) {
            return "La oportunidad ya tiene resultado ({$this->stageLabel()}). Para moverla, un administrador debe revertir el resultado.";
        }
        if ($this->canMoveTo($stage)) {
            return null;
        }
        if (in_array($stage, self::CLOSED_STAGES, true)) {
            return 'Solo una oportunidad en Oferta Presentada puede pasar a '.self::STAGES[$stage].'.';
        }

        return 'No se puede pasar de '.$this->stageLabel().' a '.self::STAGES[$stage].': el tablero avanza o retrocede de una etapa en una.';
    }

    /** Búsqueda por título, licitación o institución. */
    public function scopeSearch($query, ?string $term)
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(fn ($builder) => $builder
            ->where('title', 'like', "%{$term}%")
            ->orWhereHas('institution', fn ($institution) => $institution->where('name', 'like', "%{$term}%")->orWhere('acronym', 'like', "%{$term}%"))
            ->orWhereHas('tender', fn ($tender) => $tender->where('reference', 'like', "%{$term}%")));
    }
}
