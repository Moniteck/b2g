<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RevolvingBudget extends Model
{
    protected $fillable = ['initial_amount', 'current_balance'];

    protected function casts(): array
    {
        return ['initial_amount' => 'decimal:2', 'current_balance' => 'decimal:2'];
    }

    public function movements()
    {
        return $this->hasMany(BudgetMovement::class);
    }
}
