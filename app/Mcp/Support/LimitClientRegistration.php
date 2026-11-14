<?php

namespace App\Mcp\Support;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Límite de registros dinámicos de clientes OAuth por IP (`mcp.crm.registrations_per_hour`).
 *
 * Contador propio: el `throttle:N,M` sin nombre comparte su clave (dominio + IP) con el resto de
 * límites sin nombre, como el del inicio de sesión, y los mezclaría.
 */
class LimitClientRegistration
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = 'mcp-client-registration:'.$request->ip();
        $max = (int) config('mcp.crm.registrations_per_hour', 10);

        if (RateLimiter::tooManyAttempts($key, $max)) {
            return response()->json([
                'error' => 'too_many_requests',
                'error_description' => 'Demasiados registros de clientes desde esta dirección. Intente más tarde.',
            ], 429, ['Retry-After' => RateLimiter::availableIn($key)]);
        }

        RateLimiter::hit($key, 3600);

        return $next($request);
    }
}
