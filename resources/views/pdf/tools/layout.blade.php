{{--
    Membrete común de los PDF de las herramientas B2G (sección «PDF y correos» de la guía de diseño).
    Variables: $title, $subtitle (opcional), $company (['name', 'tax_id', 'address', 'phone', 'email'] opcional),
    $generatedAt (Carbon). Todo texto se imprime escapado con {{ }}.
--}}
@php
    $surface = config('design.chrome.surface');
    $paperLine = config('design.chrome.paperLine');
    $brand = config('design.brand.red');
    $soft = config('design.chrome.textSoft');
    $ok = config('design.status.light.ok');
    $warning = config('design.status.light.warning');
    $error = config('design.status.light.error');
    $onAccent = config('design.chrome.onAccent');
    $companyName = $company['name'] ?? config('design.company.name');
@endphp
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 96px 48px 64px 48px; }
        body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: {{ $surface }}; line-height: 1.5; }
        header { position: fixed; top: -72px; left: 0; right: 0; height: 56px; border-bottom: 1px solid {{ $paperLine }}; }
        header .company { font-size: 14px; font-weight: bold; color: {{ $brand }}; }
        header .meta { color: {{ $soft }}; font-size: 9px; }
        footer { position: fixed; bottom: -44px; left: 0; right: 0; height: 24px; border-top: 1px solid {{ $paperLine }}; color: {{ $soft }}; font-size: 9px; padding-top: 6px; }
        footer .page:after { content: counter(page); }
        .title-bar { background: {{ $surface }}; color: {{ $onAccent }}; padding: 10px 14px; margin-bottom: 16px; }
        .title-bar h1 { margin: 0; font-size: 15px; }
        .title-bar p { margin: 2px 0 0; font-size: 10px; }
        h2 { font-size: 12px; margin: 18px 0 6px; }
        p { margin: 0 0 8px; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th { background: {{ $surface }}; color: {{ $onAccent }}; text-align: left; font-size: 10px; padding: 6px 8px; }
        td { border-bottom: 1px solid {{ $paperLine }}; padding: 6px 8px; vertical-align: top; }
        .right { text-align: right; }
        .soft { color: {{ $soft }}; }
        .ok { color: {{ $ok }}; font-weight: bold; }
        .warning { color: {{ $warning }}; font-weight: bold; }
        .error { color: {{ $error }}; font-weight: bold; }
        .body-text { white-space: pre-wrap; }
        .notice { border: 1px solid {{ $paperLine }}; padding: 8px 10px; color: {{ $soft }}; font-size: 9px; margin-top: 18px; }
        ol.steps { padding-left: 18px; }
        ol.steps li { margin-bottom: 8px; }
    </style>
</head>
<body>
<header>
    <div class="company">{{ $companyName }}</div>
    <div class="meta">
        @if (! empty($company['tax_id'])) RTN {{ $company['tax_id'] }} @endif
        @if (! empty($company['address'])) · {{ $company['address'] }} @endif
        @if (! empty($company['phone'])) · {{ $company['phone'] }} @endif
        @if (! empty($company['email'])) · {{ $company['email'] }} @endif
    </div>
</header>
<footer>
    {{ config('design.company.attribution') }} · Generado el {{ $generatedAt->format('d/m/Y h:i A') }} · Página <span class="page"></span>
</footer>
<div class="title-bar">
    <h1>{{ $title }}</h1>
    @if (! empty($subtitle))<p>{{ $subtitle }}</p>@endif
</div>
@yield('content')
</body>
</html>
