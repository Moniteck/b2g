<?php

namespace App\Http\Controllers;

use App\Http\Requests\Finance\KpiGoalRequest;
use App\Http\Requests\Finance\PeriodRequest;
use App\Models\KpiGoal;
use App\Services\AuditLogger;
use App\Services\KpiService;
use App\Services\Tenancy\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * KPI y metas de la empresa activa. Todos los roles consultan; solo el
 * administrador de la empresa (`manage_kpis`) fija, cambia o quita metas.
 */
class KpiGoalController extends Controller
{
    public function index(PeriodRequest $request, KpiService $kpis): Response
    {
        $this->authorize('viewAny', KpiGoal::class);

        $period = $request->period(today()->startOfMonth()->toDateString(), today()->endOfMonth()->toDateString());
        $actuals = $kpis->actuals($period['from'], $period['to']);

        // La meta que aplica es la que cubre todo el período; si hay varias, la más ajustada.
        $goals = KpiGoal::query()
            ->whereDate('period_start', '<=', $period['from'])
            ->whereDate('period_end', '>=', $period['to'])
            ->orderByDesc('period_start')->orderBy('period_end')
            ->get(['id', 'metric', 'target', 'period_start', 'period_end'])
            ->unique('metric')->keyBy('metric');

        return Inertia::render('Finance/Kpis', [
            'period' => ['from' => $period['from'], 'to' => $period['to']],
            'kpis' => collect(KpiService::METRICS)->map(function (string $label, string $metric) use ($actuals, $goals) {
                $goal = $goals->get($metric);

                return [
                    'metric' => $metric,
                    'label' => $label,
                    'description' => KpiService::DESCRIPTIONS[$metric],
                    'unit' => in_array($metric, KpiService::PERCENT_METRICS, true) ? 'percent' : 'money',
                    'actual' => $actuals[$metric],
                    'goal' => $goal ? (float) $goal->target : null,
                    'goal_id' => $goal?->id,
                    'goal_period' => $goal ? ['from' => $goal->period_start->toDateString(), 'to' => $goal->period_end->toDateString()] : null,
                ];
            })->values(),
            'goals' => KpiGoal::query()
                ->orderByDesc('period_start')->orderBy('metric')
                ->select(['id', 'metric', 'target', 'period_start', 'period_end', 'updated_at'])
                ->paginate(25, ['*'], 'goals_page')->withQueryString()
                ->through(fn (KpiGoal $goal) => [
                    'id' => $goal->id,
                    'metric' => $goal->metric,
                    'label' => KpiService::METRICS[$goal->metric] ?? $goal->metric,
                    'unit' => in_array($goal->metric, KpiService::PERCENT_METRICS, true) ? 'percent' : 'money',
                    'target' => (float) $goal->target,
                    'period_start' => $goal->period_start->toDateString(),
                    'period_end' => $goal->period_end->toDateString(),
                ]),
            'metrics' => collect(KpiService::METRICS)->map(fn ($label, $value) => [
                'value' => $value,
                'label' => $label,
                'unit' => in_array($value, KpiService::PERCENT_METRICS, true) ? 'percent' : 'money',
            ])->values(),
            'can' => ['manage' => $request->user()->can('create', KpiGoal::class)],
        ]);
    }

    public function store(KpiGoalRequest $request, CompanyContext $context, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('create', KpiGoal::class);

        $data = $request->validated();

        // Una meta por métrica y período en la empresa activa: guardar dos veces la actualiza.
        $goal = DB::transaction(function () use ($data, $context, $request) {
            $goal = KpiGoal::query()
                ->where('metric', $data['metric'])
                ->whereDate('period_start', $data['period_start'])
                ->whereDate('period_end', $data['period_end'])
                ->lockForUpdate()->first()
                ?? new KpiGoal(['company_id' => $context->currentCompanyId()] + $data);

            $goal->fill(['target' => $data['target'], 'updated_by' => $request->user()->id])->save();

            return $goal;
        });

        $audit->record($goal->wasRecentlyCreated ? 'kpi_goal.created' : 'kpi_goal.updated', $goal, ['metric' => $goal->metric, 'target' => $data['target']]);

        return back()->with('success', 'Meta guardada.');
    }

    public function update(KpiGoalRequest $request, KpiGoal $kpiGoal, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('update', $kpiGoal);

        $kpiGoal->update($request->validated() + ['updated_by' => $request->user()->id]);
        $audit->record('kpi_goal.updated', $kpiGoal, ['metric' => $kpiGoal->metric, 'target' => $kpiGoal->target]);

        return back()->with('success', 'Meta actualizada.');
    }

    public function destroy(KpiGoal $kpiGoal, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('delete', $kpiGoal);

        $kpiGoal->delete();
        $audit->record('kpi_goal.deleted', $kpiGoal, ['metric' => $kpiGoal->metric]);

        return back()->with('success', 'Meta eliminada.');
    }
}
