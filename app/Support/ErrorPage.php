<?php

namespace App\Support;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Páginas de error propias (`Pages/Errors/Show.vue`), en español y con el marco cuando hay sesión.
 *
 * - JSON (API, MCP, fetch con `Accept: application/json`): sin cambios.
 * - Navegación GET (carga completa o visita de Inertia): página de error de Inertia con su estado.
 * - Envío de Inertia (POST/PUT/PATCH/DELETE) con 403/404/500/503: vuelve a la página con un toast;
 *   el 419 se deja pasar para que el marco muestre «Tu sesión expiró · Recargar».
 * - 500 y 503 solo se personalizan fuera de depuración (en local se ve la traza).
 */
final class ErrorPage
{
    public const HANDLED = [403, 404, 419, 429, 500, 503];

    public const MESSAGES = [
        403 => 'No tienes permiso para hacer esto.',
        404 => 'No encontramos lo que buscabas.',
        419 => 'Tu sesión expiró. Recarga la página para continuar.',
        429 => 'Demasiadas solicitudes seguidas. Espera un momento e inténtalo de nuevo.',
        500 => 'Algo salió mal en el servidor. Inténtalo de nuevo.',
        503 => 'El sistema está en mantenimiento. Volvemos en unos minutos.',
    ];

    public static function respond(Response $response, Request $request): Response
    {
        $status = $response->getStatusCode();

        if (! in_array($status, self::HANDLED, true) || self::wantsJson($request)) {
            return $response;
        }

        if (in_array($status, [500, 503], true) && config('app.debug') && ! $request->routeIs('errors.preview')) {
            return $response;
        }

        if ($request->header('X-Inertia') && ! $request->isMethod('GET')) {
            if ($status === 419) {
                return $response;
            }

            return back()->with('error', self::MESSAGES[$status]);
        }

        try {
            return Inertia::render('Errors/Show', [
                'status' => $status,
                'message' => self::MESSAGES[$status],
                'retryAfter' => $response->headers->get('Retry-After'),
            ])->toResponse($request)->setStatusCode($status);
        } catch (Throwable) {
            return $response;
        }
    }

    private static function wantsJson(Request $request): bool
    {
        return ($request->expectsJson() && ! $request->header('X-Inertia'))
            || $request->is('api/*')
            || $request->is('mcp*');
    }
}
