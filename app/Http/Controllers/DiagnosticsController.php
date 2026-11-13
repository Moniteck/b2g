<?php

namespace App\Http\Controllers;

use App\Services\System\SystemDiagnostics;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Diagnóstico del sistema para la raíz y los administradores de empresa.
 */
class DiagnosticsController extends Controller
{
    public function __invoke(SystemDiagnostics $diagnostics): Response
    {
        $this->authorize('view-diagnostics');

        return Inertia::render('Admin/Diagnostics', $diagnostics->report());
    }
}
