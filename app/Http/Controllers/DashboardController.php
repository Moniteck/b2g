<?php

namespace App\Http\Controllers;

use App\Services\DashboardMetricsService;
use App\Services\Metrics\BusinessMetrics;
use App\Support\Binaries;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard de la empresa activa (`/`, la pantalla de entrada).
 *
 *   GET /?periodo=mes|trimestre|anio
 *
 * Llegan en la primera respuesta lo que se ve arriba: período, los 4 KPI y «Requiere atención hoy».
 * El resto va en dos grupos diferidos que se piden en paralelo tras pintar: `charts` (embudo y
 * adjudicado frente a cobrado) y `agenda` (plazos de 14 días, por vencer, actividad, tareas y primeros
 * pasos). Al cambiar el período, la página pide solo `period`, `kpis` y `charts`.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardMetricsService $dashboard): Response
    {
        // Cualquier miembro de la empresa activa (incluido solo lectura) ve el tablero.
        $this->authorize('view');

        $user = $request->user();
        $preset = (string) $request->query('periodo', 'mes');
        $period = $dashboard->period(array_key_exists($preset, BusinessMetrics::PERIODS) ? $preset : 'mes');

        return Inertia::render('Dashboard/Index', [
            'period' => $period,
            'periods' => collect(BusinessMetrics::PERIODS)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values(),
            'kpis' => fn () => $dashboard->kpis($period),
            'attention' => fn () => $dashboard->attention($user),
            'charts' => Inertia::defer(fn () => $dashboard->charts($period), 'charts'),
            'agenda' => Inertia::defer(fn () => $dashboard->agenda($user), 'agenda'),
            'can' => [
                'create' => $user->can(Permissions::gate(Permissions::CREATE)),
                'reports' => $user->can(Permissions::gate(Permissions::VIEW_REPORTS)),
            ],
            'maxImageMb' => (int) (Binaries::maxImageKilobytes() / 1024),
        ]);
    }
}
