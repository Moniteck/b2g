<?php

namespace App\Services;

use App\Models\CollectionInvoice;
use App\Models\Opportunity;
use App\Models\PoaEntry;
use App\Models\Quote;
use Carbon\Carbon;

class KpiService
{
    public const METRICS = ['pipeline' => 'Pipeline ponderado', 'adjudicaciones' => 'Adjudicaciones', 'cobros' => 'Cobros recibidos', 'tasa_adjudicacion' => 'Tasa de adjudicación', 'ejecucion_poa' => 'Ejecución POA'];

    public function actuals(string $from, string $to): array
    {
        $fromDate = Carbon::parse($from)->startOfDay(); $toDate = Carbon::parse($to)->endOfDay();
        $presented = Quote::query()->whereBetween('quoted_at', [$fromDate, $toDate])->whereIn('status', ['Presentada', 'Sustituida'])->count();
        $accepted = Quote::query()->whereBetween('quoted_at', [$fromDate, $toDate])->where('outcome', 'Ganada')->count();
        $poa = PoaEntry::query()->whereBetween('estimated_date', [$fromDate, $toDate]);
        $budgeted = (float) (clone $poa)->sum('budgeted_amount');
        return [
            'pipeline' => (float) Opportunity::query()->whereBetween('created_at', [$fromDate, $toDate])->whereNotIn('stage', ['cerrado', 'no_adjudicado'])->selectRaw('COALESCE(SUM(amount * probability / 100), 0) total')->value('total'),
            'adjudicaciones' => (float) Quote::query()->whereBetween('quoted_at', [$fromDate, $toDate])->where('outcome', 'Ganada')->sum('awarded_amount'),
            'cobros' => (float) CollectionInvoice::query()->where('status', 'Pagada')->whereBetween('paid_at', [$fromDate, $toDate])->sum('amount'),
            'tasa_adjudicacion' => $presented ? round($accepted / $presented * 100, 2) : 0,
            'ejecucion_poa' => $budgeted > 0 ? round((float) $poa->sum('executed_amount') / $budgeted * 100, 2) : 0,
        ];
    }
}
