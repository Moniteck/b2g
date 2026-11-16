{{-- Aplicación instalable (PWA): manifest, colores de la barra del sistema, íconos y datos del service worker.
     Lo registra resources/js/pwa/index.js (sin scripts en línea). Ver config/pwa.php. --}}
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#F1EDE9" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#151312" media="(prefers-color-scheme: dark)">
<link rel="icon" href="/favicon.ico" sizes="48x48">
<link rel="icon" href="/icons/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="CRM B2G">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="application-name" content="CRM B2G">
@if ($pwaWorker = \App\Support\Pwa::serviceWorkerUrl())
<meta name="pwa-sw" content="{{ $pwaWorker }}">
@endif
@if ($pwaVapid = \App\Support\Pwa::vapidPublicKey())
<meta name="pwa-vapid" content="{{ $pwaVapid }}">
@endif
