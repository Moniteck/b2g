<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Presupuesto revolvente: uno por empresa. El saldo se recalcula a partir del
 * monto inicial y de sus movimientos (ver RevolvingBudgetService).
 */
class RevolvingBudget extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'initial_amount', 'current_balance'];

    protected function casts(): array
    {
        return ['initial_amount' => 'decimal:2', 'current_balance' => 'decimal:2'];
    }

    public function movements()
    {
        return $this->hasMany(BudgetMovement::class);
    }
}
