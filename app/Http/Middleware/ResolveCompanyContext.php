<?php

namespace App\Http\Middleware;

use App\Services\Tenancy\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inicializa el contexto empresarial antes de ejecutar cualquier peticion.
 *
 * La empresa activa se toma de la sesion y puede cambiarse con el parametro
 * `empresa`, siempre que el usuario tenga acceso a ella.
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
        $requested = $request->integer('empresa') ?: null;

        if ($requested !== null && in_array($requested, $allowed, true)) {
            $request->session()->put('company_id', $requested);
        }

        $sessionCompany = $request->session()->get('company_id');
        $current = is_int($sessionCompany) || ctype_digit((string) $sessionCompany)
            ? (int) $sessionCompany
            : null;

        if ($current === null || ! in_array($current, $allowed, true)) {
            $current = $allowed[0] ?? null;
            if ($current !== null) {
                $request->session()->put('company_id', $current);
            }
        }

        $context->initialize($user, $allowed, $current);

        return $next($request);
    }
}
