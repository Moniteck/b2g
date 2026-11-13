<?php

namespace App\Services;

use App\Models\{BudgetMovement, CollectionInvoice, Quote, RevolvingBudget, Tender};
use Illuminate\Support\Facades\DB;

class RevolvingBudgetService
{
    public function budget(): RevolvingBudget { return RevolvingBudget::firstOrCreate([], ['initial_amount' => 0, 'current_balance' => 0]); }

    public function sync(): RevolvingBudget
    {
        return DB::transaction(function () {
            $budget = $this->budget();
            $sources = [
                ['quote', Quote::query()->where('outcome', 'Ganada')->get(), 'Egreso', 'awarded_amount', 'number'],
                ['tender', Tender::query()->whereIn('status', ['Adjudicada', 'Ganada'])->get(), 'Ingreso proyectado', 'estimated_amount', 'reference'],
                ['collection', CollectionInvoice::query()->where('status', 'Pagada')->get(), 'Ingreso', 'amount', 'number'],
            ];
            foreach ($sources as [$sourceType, $rows, $type, $amount, $reference]) foreach ($rows as $row) {
                $value = (float) ($row->{$amount} ?: 0);
                if ($value <= 0) continue;
                BudgetMovement::updateOrCreate(
                    ['source_type' => $sourceType, 'source_id' => $row->id],
                    ['revolving_budget_id' => $budget->id, 'user_id' => null, 'moved_at' => $row->updated_at->toDateString(), 'type' => $type, 'concept' => $row->{$reference}, 'reference' => strtoupper($sourceType).'-'.$row->id, 'amount' => $value, 'synchronized' => true]
                );
            }
            return $this->recalculate($budget);
        });
    }

    public function recalculate(RevolvingBudget $budget): RevolvingBudget
    {
        $balance = (float) $budget->initial_amount;
        $budget->movements()->orderBy('moved_at')->orderBy('id')->get()->each(function (BudgetMovement $movement) use (&$balance) {
            $balance += str_contains(mb_strtolower($movement->type), 'egreso') ? -(float) $movement->amount : (float) $movement->amount;
            $movement->update(['balance_after' => $balance]);
        });
        $budget->update(['current_balance' => $balance]);
        return $budget->refresh();
    }
}
