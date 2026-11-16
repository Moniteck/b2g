<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad de todas las páginas del grupo `web` (hallazgo M3).
 *
 * - Content-Security-Policy con nonce por petición: los <script> en línea de las vistas Blade
 *   llevan `nonce="{{ Vite::cspNonce() }}"` y `@vite` lo añade solo a sus etiquetas.
 *   `blob:` para el visor de PDF (pdf.js usa un worker y URLs blob), `data:` para imágenes y
 *   fuentes, `'unsafe-inline'` solo en estilos (Vue y las librerías escriben `style=""`).
 *   En `local` se admiten los orígenes del servidor de Vite (HTTP y WebSocket del HMR).
 * - X-Frame-Options y `frame-ancestors 'none'` contra clickjacking; nosniff; Referrer-Policy;
 *   Permissions-Policy; HSTS solo cuando la petición llega por HTTPS.
 * - Se quita X-Powered-By (en producción, además, `expose_php=Off` en php.ini).
 */
class SecurityHeaders
{
    /** Orígenes del servidor de Vite en desarrollo. */
    private const VITE_DEV_HOSTS = ['localhost:5173', '127.0.0.1:5173'];


    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        if (function_exists('header_remove') && ! headers_sent()) {
            header_remove('X-Powered-By');
        }
        $response->headers->remove('X-Powered-By');

        $headers = [
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        ];

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', $this->policy($request, $nonce));
        }

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function policy(Request $request, string $nonce): string
    {
        [$devHttp, $devWs] = $this->viteDevOrigins();

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'nonce-{$nonce}'", ...$devHttp],
            'style-src' => ["'self'", "'unsafe-inline'", ...$devHttp],
            'img-src' => ["'self'", 'data:', 'blob:', ...$devHttp],
            'font-src' => ["'self'", 'data:', ...$devHttp],
            'connect-src' => ["'self'", ...$devHttp, ...$devWs],
            'worker-src' => ["'self'", 'blob:', ...$devHttp],
            'frame-src' => ["'self'", 'blob:'],
            'media-src' => ["'self'", 'blob:', 'data:'],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'frame-ancestors' => ["'none'"],
        ];

        // El consentimiento OAuth termina redirigiendo al cliente MCP (otro dominio): Chrome aplica
        // `form-action` también a esa redirección, así que en esas pantallas no se restringe.
        if (! $request->is('oauth/*') && ! $request->routeIs('mcp.*')) {
            $directives['form-action'] = ["'self'"];
        }

        return collect($directives)
            ->map(fn (array $sources, string $directive) => $directive.' '.implode(' ', array_unique($sources)))
            ->implode('; ');
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function viteDevOrigins(): array
    {
        if (! app()->environment('local')) {
            return [[], []];
        }

        $hosts = self::VITE_DEV_HOSTS;
        $hot = Vite::hotFile();
        $ipv6 = false;

        if (is_file($hot) && ($url = trim((string) @file_get_contents($hot))) !== '') {
            $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
            $port = parse_url($url, PHP_URL_PORT) ?: 5173;

            // La CSP no acepta IPv6 literal (`[::1]`): Chrome descarta esa fuente como inválida.
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $ipv6 = true;
                $hosts[] = "localhost:{$port}";
            } elseif ($host !== '') {
                $hosts[] = "{$host}:{$port}";
            }
        }

        $hosts = array_values(array_unique($hosts));
        $http = array_map(fn (string $host) => "http://{$host}", $hosts);
        $ws = array_map(fn (string $host) => "ws://{$host}", $hosts);

        // Vite publicado en IPv6 (sin `server.host: 'localhost'` en vite.config.js): solo en local
        // se admite cualquier origen http/ws para no dejar sin íconos ni HMR al desarrollador.
        if ($ipv6) {
            $http[] = 'http:';
            $ws[] = 'ws:';
        }

        return [$http, $ws];
    }
}
