<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <!-- Grupo CAP design system: tema sin parpadeo y fuentes -->
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        let storedTheme;
        try { storedTheme = localStorage.theme; } catch { storedTheme = undefined; }
        const theme = storedTheme ?? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        document.documentElement.classList.toggle('dark', theme === 'dark');
    </script>
    {{-- Fuentes alojadas en el sistema (resources/css/fonts.css); se precarga la del texto. --}}
    <link rel="preload" href="{{ \Illuminate\Support\Facades\Vite::asset('resources/fonts/inter/inter-latin-400.woff2') }}" as="font" type="font/woff2" crossorigin>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="description" content="CRM B2G Honduras: licitaciones, pipeline, cotizaciones y contratos con el sector público.">
    @include('partials.pwa-head')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>CRM B2G Honduras</title>
    @vite('resources/js/app.js')
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
