<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class BudgetMovement extends Model
{
    use BelongsToCompany;

    public const TYPE_INCOME = 'Ingreso';

    public const TYPE_EXPENSE = 'Egreso';

    public const TYPE_PROJECTED = 'Ingreso proyectado';

    /** Tipos que se registran a mano; el proyectado solo llega por sincronización. */
    public const MANUAL_TYPES = [self::TYPE_INCOME, self::TYPE_EXPENSE];

    /** Origen de los movimientos sincronizados. */
    public const SOURCES = ['quote' => 'Cotización ganada', 'tender' => 'Licitación adjudicada', 'collection' => 'Cobro recibido'];

    protected $fillable = ['company_id', 'revolving_budget_id', 'user_id', 'moved_at', 'type', 'concept', 'reference', 'amount', 'balance_after', 'source_type', 'source_id', 'synchronized'];

    protected function casts(): array
    {
        return ['moved_at' => 'date', 'amount' => 'decimal:2', 'balance_after' => 'decimal:2', 'synchronized' => 'boolean'];
    }

    public function revolvingBudget()
    {
        return $this->belongsTo(RevolvingBudget::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isExpense(): bool
    {
        return self::isExpenseType($this->type);
    }

    public static function isExpenseType(?string $type): bool
    {
        return str_contains(mb_strtolower((string) $type), 'egreso');
    }
}
