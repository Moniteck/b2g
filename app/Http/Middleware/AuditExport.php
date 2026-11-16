<?php

namespace App\Http\Middleware;

use App\Services\AuditLogger;
use App\Services\Tenancy\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deja en la auditoría cada exporte (PDF, XLSX, CSV, ZIP) que se descarga con éxito (hallazgo B6):
 * qué ruta, con qué filtros y para qué empresa. Se aplica con el alias `audit.export`, junto al
 * limitador `throttle:exports`.
 */
class AuditExport
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->isSuccessful()) {
            $this->audit->record('exporte.generado', null, array_filter([
                'ruta' => $request->route()?->getName(),
                'parametros' => $request->route()?->originalParameters() ?: null,
                'filtros' => $request->isMethod('GET') ? ($request->query() ?: null) : null,
                'archivo' => $this->filename($response),
            ]), $request->user(), app(CompanyContext::class)->currentCompanyId());
        }

        return $response;
    }

    private function filename(Response $response): ?string
    {
        $disposition = (string) $response->headers->get('Content-Disposition', '');

        return preg_match('/filename="?([^";]+)"?/i', $disposition, $match) ? $match[1] : null;
    }
}
