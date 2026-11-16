<?php

/*
|--------------------------------------------------------------------------
| Aplicación instalable (PWA) y notificaciones push
|--------------------------------------------------------------------------
|
| El service worker (`public/sw.js`) solo se registra con una compilación de producción
| (`public/build/manifest.json`) y sin el servidor de Vite (`public/hot`). En `APP_ENV=local` hace
| falta además `PWA_ENABLED=true` o abrir cualquier pantalla con `?pwa=1` (pruebas).
|
| Web Push usa claves VAPID. Genérelas una vez con `php artisan pwa:vapid` y cópielas al `.env`.
| Sin las tres claves el canal «push» queda desactivado sin errores: no se ofrecen suscripciones y
| las reglas de recordatorio con push no generan avisos.
|
*/

return [
    'enabled' => env('PWA_ENABLED'),

    'vapid' => [
        'subject' => env('VAPID_SUBJECT'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],

    /** Segundos que el servicio push guarda un aviso si el dispositivo está apagado. */
    'ttl' => (int) env('PWA_PUSH_TTL', 86400),

    /** Máximo de dispositivos suscritos por usuario (los más antiguos se descartan). */
    'max_devices' => 10,
];
