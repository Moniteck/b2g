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

    public function stageLabel(): string
    {
        return self::STAGES[$this->stage] ?? ucfirst((string) $this->stage);
    }
}
