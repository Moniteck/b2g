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
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family={{ config('design.fonts.webfont') }}&display=swap" rel="stylesheet">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>CRM B2G Honduras</title>
    @vite('resources/js/app.js')
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
