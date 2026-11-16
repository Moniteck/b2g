<?php

namespace App\Services\Finance;

use App\Models\BudgetMovement;
use App\Models\CollectionInvoice;
use App\Models\Quote;
use App\Models\RevolvingBudget;
use App\Models\Tender;
use App\Services\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Presupuesto revolvente de la empresa activa: monto inicial, movimientos
 * manuales y movimientos sincronizados desde cotizaciones ganadas (egreso),
 * licitaciones adjudicadas (ingreso proyectado) y cobros recibidos (ingreso).
 */
class RevolvingBudgetService
{
    public function __construct(private CompanyContext $context) {}

    /** Presupuesto de la empresa activa; se crea en cero la primera vez. */
    public function budget(): RevolvingBudget
    {
        $companyId = $this->context->currentCompanyId();

        if ($companyId === null) {
            throw new RuntimeException('No hay una empresa activa para el presupuesto revolvente.');
        }

        return RevolvingBudget::query()->firstOrCreate(['company_id' => $companyId], ['initial_amount' => 0, 'current_balance' => 0]);
    }

    public function setInitialAmount(float $amount): RevolvingBudget
    {
        return DB::transaction(function () use ($amount) {
            $budget = RevolvingBudget::query()->lockForUpdate()->findOrFail($this->budget()->id);
            $budget->update(['initial_amount' => $amount]);

            return $this->recalculate($budget);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function record(array $data, int $userId): BudgetMovement
    {
        return DB::transaction(function () use ($data, $userId) {
            $budget = RevolvingBudget::query()->lockForUpdate()->findOrFail($this->budget()->id);
            $movement = $budget->movements()->create($data + ['user_id' => $userId, 'synchronized' => false]);
            $this->recalculate($budget);

            return $movement->refresh();
        });
    }

    public function remove(BudgetMovement $movement): void
    {
        DB::transaction(function () use ($movement) {
            $budget = RevolvingBudget::query()->lockForUpdate()->findOrFail($movement->revolving_budget_id ?? $this->budget()->id);
            $movement->delete();
            $this->recalculate($budget);
        });
    }

    /**
     * Trae los movimientos de las fuentes del sistema. Es idempotente: cada
     * origen tiene un solo movimiento (se actualiza) y los que ya no califican
     * (p. ej. una cotización que dejó de estar ganada) se quitan.
     *
     * @return array{budget: RevolvingBudget, created: int, updated: int, removed: int}
     */
    public function sync(): array
    {
        return DB::transaction(function () {
            $budget = RevolvingBudget::query()->lockForUpdate()->findOrFail($this->budget()->id);
            $sources = [
                'quote' => [Quote::query()->where('outcome', 'Ganada'), BudgetMovement::TYPE_EXPENSE, 'COALESCE(awarded_amount, total)', 'number', 'COALESCE(awarded_at, quoted_at, updated_at)'],
                'tender' => [Tender::query()->where('status', 'Adjudicada'), BudgetMovement::TYPE_PROJECTED, 'estimated_amount', 'reference', 'COALESCE(closing_date, updated_at)'],
                'collection' => [CollectionInvoice::query()->where('status', CollectionInvoice::STATUS_PAID), BudgetMovement::TYPE_INCOME, 'amount', 'number', 'COALESCE(paid_at, updated_at)'],
            ];
            $labels = BudgetMovement::SOURCES;
            $counts = ['created' => 0, 'updated' => 0, 'removed' => 0];

            foreach ($sources as $sourceType => [$query, $type, $amount, $reference, $date]) {
                $rows = $query->toBase()->selectRaw("id, {$amount} as value, {$reference} as label, {$date} as moved")->get();
                $existing = BudgetMovement::query()->where('source_type', $sourceType)->get()->keyBy('source_id');
                $kept = [];

                foreach ($rows as $row) {
                    $value = round((float) $row->value, 2);
                    if ($value <= 0) {
                        continue;
                    }

                    $kept[] = (int) $row->id;
                    $attributes = [
                        'revolving_budget_id' => $budget->id,
                        'moved_at' => substr((string) $row->moved, 0, 10),
                        'type' => $type,
                        'concept' => trim($labels[$sourceType].' '.($row->label ?? '')),
                        'reference' => strtoupper($sourceType).'-'.$row->id,
                        'amount' => $value,
                        'synchronized' => true,
                    ];

                    $movement = $existing->get($row->id);
                    if ($movement === null) {
                        BudgetMovement::query()->create($attributes + ['source_type' => $sourceType, 'source_id' => $row->id, 'user_id' => null]);
                        $counts['created']++;
                    } else {
                        $movement->fill($attributes);
                        if ($movement->isDirty()) {
                            $movement->save();
                            $counts['updated']++;
                        }
                    }
                }

                $stale = $existing->keys()->diff($kept);
                if ($stale->isNotEmpty()) {
                    $counts['removed'] += BudgetMovement::query()->where('source_type', $sourceType)->whereIn('source_id', $stale->all())->delete();
                }
            }

            return ['budget' => $this->recalculate($budget)] + $counts;
        });
    }

    /**
     * Recalcula el saldo acumulado de cada movimiento en orden de fecha y el
     * saldo actual. Solo escribe los movimientos cuyo saldo cambió.
     */
    public function recalculate(RevolvingBudget $budget): RevolvingBudget
    {
        $balance = (float) $budget->initial_amount;
        $movements = $budget->movements()->orderBy('moved_at')->orderBy('id')->get(['id', 'type', 'amount', 'balance_after']);

        foreach ($movements as $movement) {
            $balance = round($balance + ($movement->isExpense() ? -1 : 1) * (float) $movement->amount, 2);

            if ($movement->balance_after === null || (float) $movement->balance_after !== $balance) {
                BudgetMovement::query()->whereKey($movement->id)->update(['balance_after' => $balance]);
            }
        }

        $budget->update(['current_balance' => $balance]);

        return $budget->refresh();
    }
}
