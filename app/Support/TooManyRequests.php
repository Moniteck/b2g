<?php

namespace App\Support;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Respuesta a un límite de peticiones superado (429), en español y legible en la interfaz:
 *
 * - Peticiones JSON (API, MCP, fetch): 429 con `message`.
 * - Pantallas (Inertia o enlaces de descarga): vuelve a la página anterior con el aviso como
 *   toast (`flash.error`) y como error del formulario, para que las subidas lo muestren junto
 *   al archivo. La página anterior sale de la sesión, nunca de la cabecera Referer.
 * - Acceso: siempre a la pantalla de acceso, con el aviso bajo el correo.
 */
final class TooManyRequests
{
    /**
     * @param  array<string, mixed>  $headers  cabeceras del limitador (Retry-After, X-RateLimit-*)
     */
    public static function respond(Request $request, string $message, array $headers = []): Response
    {
        if ($request->routeIs('login.store')) {
            event(new Lockout($request));

            return redirect()->route('login')
                ->withErrors(['email' => $message])
                ->onlyInput('email')
                ->withHeaders($headers);
        }

        if (($request->expectsJson() || $request->is('api/*') || $request->is('mcp*')) && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $message], 429, $headers);
        }

        return redirect()->to(self::previousUrl($request))
            ->with('error', $message)
            ->withErrors(['throttle' => $message])
            ->withHeaders($headers);
    }

    /** Mensaje con la espera en segundos o minutos. */
    public static function message(?int $retryAfter, string $what = 'Demasiadas solicitudes seguidas.'): string
    {
        $seconds = max(1, (int) $retryAfter);
        $wait = $seconds >= 120 ? ceil($seconds / 60).' minutos' : "{$seconds} segundos";

        return "{$what} Espera {$wait} e inténtalo de nuevo.";
    }

    /**
     * Página anterior (Referer o la guardada en la sesión) solo si es de este mismo sitio y no es
     * la propia petición; si no, el inicio. Así un Referer ajeno nunca se convierte en redirección.
     */
    public static function previousUrl(Request $request): string
    {
        $candidates = [$request->headers->get('referer'), $request->hasSession() ? $request->session()->previousUrl() : null];

        foreach ($candidates as $previous) {
            if (! is_string($previous) || $previous === '') {
                continue;
            }

            // Ruta relativa del mismo sitio («/clientes», nunca «//otro.dominio»).
            if (str_starts_with($previous, '/') && ! str_starts_with($previous, '//')) {
                $previous = $request->getSchemeAndHttpHost().$previous;
            }

            if (parse_url($previous, PHP_URL_HOST) === $request->getHost() && $previous !== $request->fullUrl()) {
                return $previous;
            }
        }

        return $request->user() ? route('dashboard') : route('login');
    }
}
