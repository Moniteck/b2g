<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Respuestas con datos de una sesión iniciada: no se guardan en cachés ni en el historial legible.
 *
 * - `Cache-Control: no-store, private`: el navegador no conserva la página para mostrarla con el
 *   botón Atrás después de cerrar sesión (los recursos de `public/build` los sirve el servidor web
 *   sin pasar por aquí y siguen en caché).
 * - Historial cifrado de Inertia: el estado de cada página que queda en `history.state` va cifrado
 *   con una llave de la sesión; al cerrar sesión (`Inertia::clearHistory()`) deja de poder leerse.
 */
class PrivateResponses
{
    public function handle(Request $request, Closure $next): Response
    {
        $authenticated = $request->user() !== null;

        if ($authenticated) {
            Inertia::encryptHistory();
        }

        $response = $next($request);

        if ($authenticated || $request->user() !== null) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Pragma', 'no-cache');
        }

        return $response;
    }
}
