<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rutas «solo JSON» (detalle que pinta una modal, listas auxiliares): si el navegador las abre
 * directamente (recarga, enlace compartido, correo), en lugar de JSON crudo se redirige a la
 * pantalla que las contiene. Las peticiones de la propia app (fetch con `Accept: application/json`,
 * `X-Requested-With`) y las visitas de Inertia pasan sin cambios.
 *
 * Uso, en el archivo de rutas del módulo (una línea por ruta):
 *
 *   Route::get('/cotizaciones/{quote}', [QuoteController::class, 'show'])
 *       ->middleware(RedirectBrowserToScreen::class.':/cotizaciones?ver={quote}');
 *
 * `{parametro}` se reemplaza por el valor de ese parámetro de la ruta (o la clave del modelo).
 */
class RedirectBrowserToScreen
{
    public function handle(Request $request, Closure $next, string $target = '/'): Response
    {
        if (self::isBrowserNavigation($request)) {
            return redirect()->to($this->resolve($target, $request));
        }

        return $next($request);
    }

    /** Navegación de documento hecha por el navegador, no una petición de datos de la app. */
    public static function isBrowserNavigation(Request $request): bool
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return false;
        }

        if ($request->header('X-Inertia') || $request->ajax() || $request->expectsJson()) {
            return false;
        }

        $mode = $request->header('Sec-Fetch-Mode');
        if ($mode !== null && $mode !== 'navigate') {
            return false;
        }

        return str_contains((string) $request->header('Accept', ''), 'text/html') || $mode === 'navigate';
    }

    private function resolve(string $target, Request $request): string
    {
        $url = preg_replace_callback('/\{(\w+)\}/', function (array $match) use ($request) {
            $value = $request->route($match[1]);

            if ($value instanceof UrlRoutable) {
                $value = $value->getRouteKey();
            }

            return rawurlencode((string) $value);
        }, $target);

        return str_starts_with($url, '/') ? $url : '/'.$url;
    }
}
