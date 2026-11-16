<?php

namespace App\Support;

use App\Services\Pwa\WebPushService;
use Illuminate\Http\Request;

/**
 * Datos de la aplicación instalable para el `<head>` (`resources/views/partials/pwa-head.blade.php`).
 */
class Pwa
{
    /**
     * URL del service worker con la versión de la compilación, o null si no debe registrarse:
     * sin `public/build/manifest.json`, con el servidor de Vite (`public/hot`) o en `local` salvo
     * `PWA_ENABLED=true` o `?pwa=1`. La versión cambia con cada compilación: así el navegador
     * detecta el service worker nuevo y la app ofrece «Actualizar».
     */
    public static function serviceWorkerUrl(?Request $request = null): ?string
    {
        $manifest = public_path('build/manifest.json');

        if (! is_file($manifest) || is_file(public_path('hot'))) {
            return null;
        }

        $request ??= request();
        $forced = filter_var(config('pwa.enabled'), FILTER_VALIDATE_BOOLEAN) || $request->query('pwa') === '1';

        if (app()->environment('local', 'testing') && ! $forced) {
            return null;
        }

        return '/sw.js?v='.substr((string) md5_file($manifest), 0, 12);
    }

    public static function vapidPublicKey(): ?string
    {
        return app(WebPushService::class)->publicKey();
    }
}
