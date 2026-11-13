<?php

namespace App\Http\Controllers;

use App\Services\AlertService;
use App\Services\DashboardMetricsService;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(DashboardMetricsService $metrics, AlertService $alerts): Response
    {
        // Cualquier miembro de la empresa activa (incluido solo lectura) ve el tablero.
        $this->authorize('view');

        return Inertia::render('Dashboard', [
            'dashboard' => $metrics->metrics(),
            'alerts' => $alerts->active(),
        ]);
    }
}
