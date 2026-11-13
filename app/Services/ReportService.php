<?php

namespace App\Services;

use App\Models\CollectionInvoice;
use App\Models\Opportunity;
use App\Models\PoaEntry;
use App\Models\Quote;
use App\Models\Tender;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ReportService
{
    public function filters(Request $request): array
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'module' => ['nullable', 'in:all,pipeline,cotizaciones,cobros,licitaciones,poa']]);

        return ['from' => $data['from'] ?? today()->startOfYear()->toDateString(), 'to' => $data['to'] ?? today()->toDateString(), 'module' => $data['module'] ?? 'all'];
    }

    public function summary(array $filters): array
    {
        $from = Carbon::parse($filters['from'])->startOfDay();
        $to = Carbon::parse($filters['to'])->endOfDay();
        $module = $filters['module'];
        $rows = [];
        if (in_array($module, ['all', 'pipeline'], true)) {
            $rows[] = $this->row('Pipeline ponderado', 'pipeline', Opportunity::query()->whereBetween('created_at', [$from, $to])->whereNotIn('stage', Opportunity::CLOSED_STAGES)->selectRaw('COALESCE(SUM(amount * probability / 100), 0) total')->value('total'), Opportunity::query()->whereBetween('created_at', [$from, $to])->whereNotIn('stage', Opportunity::CLOSED_STAGES)->count());
        }
        if (in_array($module, ['all', 'cotizaciones'], true)) {
            $rows[] = $this->row('Cotizaciones ganadas', 'cotizaciones', Quote::query()->whereBetween('quoted_at', [$from, $to])->where('outcome', 'Ganada')->sum('awarded_amount'), Quote::query()->whereBetween('quoted_at', [$from, $to])->where('outcome', 'Ganada')->count());
        }
        if (in_array($module, ['all', 'cotizaciones'], true)) {
            $rows[] = $this->row('Cotizaciones presentadas', 'cotizaciones', Quote::query()->whereBetween('quoted_at', [$from, $to])->whereIn('status', ['Presentada', 'Sustituida'])->sum('total'), Quote::query()->whereBetween('quoted_at', [$from, $to])->whereIn('status', ['Presentada', 'Sustituida'])->count());
        }
        if (in_array($module, ['all', 'cobros'], true)) {
            $rows[] = $this->row('Cobros recibidos', 'cobros', CollectionInvoice::query()->where('status', 'Pagada')->whereBetween('paid_at', [$from, $to])->sum('amount'), CollectionInvoice::query()->where('status', 'Pagada')->whereBetween('paid_at', [$from, $to])->count());
        }
        if (in_array($module, ['all', 'licitaciones'], true)) {
            $rows[] = $this->row('Licitaciones adjudicadas', 'licitaciones', Tender::query()->whereBetween('closing_date', [$from, $to])->where('status', 'Adjudicada')->sum('estimated_amount'), Tender::query()->whereBetween('closing_date', [$from, $to])->where('status', 'Adjudicada')->count());
        }
        if (in_array($module, ['all', 'poa'], true)) {
            $rows[] = $this->row('Ejecución POA', 'poa', PoaEntry::query()->whereBetween('estimated_date', [$from, $to])->sum('executed_amount'), PoaEntry::query()->whereBetween('estimated_date', [$from, $to])->count());
        }

        return ['filters' => $filters, 'rows' => $rows, 'total' => (float) collect($rows)->sum('amount')];
    }

    private function row(string $label, string $module, mixed $amount, int $count): array
    {
        return ['label' => $label, 'module' => $module, 'amount' => (float) $amount, 'count' => $count];
    }
}
