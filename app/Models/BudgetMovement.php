<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class BudgetMovement extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'revolving_budget_id', 'user_id', 'moved_at', 'type', 'concept', 'reference', 'amount', 'balance_after', 'source_type', 'source_id', 'synchronized'];

    protected function casts(): array
    {
        return ['moved_at' => 'date', 'amount' => 'decimal:2', 'balance_after' => 'decimal:2', 'synchronized' => 'boolean'];
    }

    public function revolvingBudget()
    {
        return $this->belongsTo(RevolvingBudget::class);
    }
}
