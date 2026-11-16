{{-- Cabecera común de las pantallas OAuth del MCP: tema sin parpadeo, fuentes y estilos CAP. --}}
<meta charset="utf-8">
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
    let storedTheme;
    try { storedTheme = localStorage.theme; } catch { storedTheme = undefined; }
    document.documentElement.classList.toggle('dark', (storedTheme ?? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')) === 'dark');
</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
{{-- Fuentes alojadas en el sistema (resources/css/fonts.css, dentro de app.css). --}}
<link rel="preload" href="{{ \Illuminate\Support\Facades\Vite::asset('resources/fonts/inter/inter-latin-400.woff2') }}" as="font" type="font/woff2" crossorigin>
<title>{{ $title }} · CRM B2G</title>
@vite('resources/css/app.css')
