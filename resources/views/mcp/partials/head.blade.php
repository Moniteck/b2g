{{-- Cabecera común de las pantallas OAuth del MCP: tema sin parpadeo, fuentes y estilos CAP. --}}
<meta charset="utf-8">
<script>
    let storedTheme;
    try { storedTheme = localStorage.theme; } catch { storedTheme = undefined; }
    document.documentElement.classList.toggle('dark', (storedTheme ?? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')) === 'dark');
</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family={{ config('design.fonts.webfont') }}&display=swap" rel="stylesheet">
<title>{{ $title }} · CRM B2G</title>
@vite('resources/css/app.css')
