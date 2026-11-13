<?php

namespace App\Http\Middleware;

use App\Services\Tenancy\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inicializa el contexto empresarial antes de resolver los modelos de la ruta.
 *
 * Tiene prioridad sobre `SubstituteBindings` (ver bootstrap/app.php): así el
 * scope por empresa ya está activo cuando se resuelve `{quote}`, `{tender}`,
 * etc., y un id de otra empresa responde 404.
 *
 * La empresa activa vive en sesión y se cambia con `POST /empresa-activa`.
 */
class ResolveCompanyContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(CompanyContext::class);
        $user = $request->user();

        if ($user === null) {
            $context->initialize(null);

            return $next($request);
        }

        $allowed = $context->resolveAllowed($user);
        $session = $request->hasSession() ? $request->session() : null;
        $stored = $session?->get(CompanyContext::SESSION_KEY);
        $current = is_numeric($stored) ? (int) $stored : null;

        if ($current === null || ! in_array($current, $allowed, true)) {
            $default = $user->defaultCompanyId();
            $current = in_array($default, $allowed, true) ? $default : ($allowed[0] ?? null);

            $current !== null
                ? $session?->put(CompanyContext::SESSION_KEY, $current)
                : $session?->forget(CompanyContext::SESSION_KEY);
        }

        $context->initialize($user, $allowed, $current);

        return $next($request);
    }
}
