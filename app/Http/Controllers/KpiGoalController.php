<?php

namespace App\Http\Controllers;

use App\Models\KpiGoal;
use App\Services\KpiService;
use App\Services\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class KpiGoalController extends Controller
{
    public function index(Request $request, KpiService $kpis)
    {
        $this->authorize('viewAny', KpiGoal::class);

        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = $data['from'] ?? today()->startOfMonth()->toDateString();
        $to = $data['to'] ?? today()->endOfMonth()->toDateString();
        $actuals = $kpis->actuals($from, $to);
        $goals = KpiGoal::query()->where('period_start', '<=', $from)->where('period_end', '>=', $to)->get()->keyBy('metric');

        return Inertia::render('Kpis', [
            'period' => ['from' => $from, 'to' => $to],
            'kpis' => collect(KpiService::METRICS)->map(fn ($label, $metric) => [
                'metric' => $metric,
                'label' => $label,
                'actual' => $actuals[$metric] ?? 0,
                'goal' => (float) ($goals->get($metric)?->target ?? 0),
                'goalId' => $goals->get($metric)?->id,
            ])->values()->all(),
            'canManage' => $request->user()->can('create', KpiGoal::class),
        ]);
    }

    public function store(Request $request, CompanyContext $context): RedirectResponse
    {
        $this->authorize('create', KpiGoal::class);

        $data = $this->validated($request);

        // La meta es de la empresa activa: una por métrica y período.
        KpiGoal::updateOrCreate(
            [
                'company_id' => $context->currentCompanyId(),
                'metric' => $data['metric'],
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
            ],
            ['target' => $data['target'], 'updated_by' => $request->user()->id],
        );

        return back()->with('success', 'Meta actualizada.');
    }

    public function update(Request $request, KpiGoal $kpiGoal): RedirectResponse
    {
        $this->authorize('update', $kpiGoal);

        $kpiGoal->update($this->validated($request) + ['updated_by' => $request->user()->id]);

        return back()->with('success', 'Meta actualizada.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'metric' => ['required', 'in:'.implode(',', array_keys(KpiService::METRICS))],
            'target' => ['required', 'numeric', 'min:0'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
        ]);

        if (Carbon::parse($data['period_start'])->diffInDays(Carbon::parse($data['period_end'])) > 366) {
            abort(422, 'El período no puede exceder un año.');
        }

        return $data;
    }
}
