<?php

namespace App\Http\Controllers;

use App\Services\System\SystemDiagnostics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Diagnóstico del sistema para la raíz y los administradores de empresa.
 *
 * Las comprobaciones invocan binarios (Tesseract, Poppler) y la red (~1,5 s), así que el informe se
 * guarda 5 minutos. «Volver a comprobar» manda la cabecera `X-Diagnostics-Fresh: 1` (o `?fresh=1`)
 * y rehace el informe.
 */
class DiagnosticsController extends Controller
{
    public const CACHE_KEY = 'system.diagnostics.report';

    public const CACHE_SECONDS = 300;

    public function __invoke(Request $request, SystemDiagnostics $diagnostics): Response
    {
        $this->authorize('view-diagnostics');

        if ($request->header('X-Diagnostics-Fresh') === '1' || $request->boolean('fresh')) {
            Cache::forget(self::CACHE_KEY);
        }

        return Inertia::render('Admin/Diagnostics', Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn () => $diagnostics->report()));
    }
}
