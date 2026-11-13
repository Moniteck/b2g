<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PoaEntry extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'institution_id', 'project', 'year', 'partida', 'quarter', 'probability', 'budgeted_amount', 'executed_amount', 'estimated_date', 'annual_close_alert', 'status', 'notes'];

    protected function casts(): array
    {
        return ['budgeted_amount' => 'decimal:2', 'executed_amount' => 'decimal:2', 'estimated_date' => 'date', 'annual_close_alert' => 'boolean'];
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }
}
