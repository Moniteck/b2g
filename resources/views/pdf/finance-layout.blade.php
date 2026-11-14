{{-- Marco común de los PDF financieros (reportes, cobros, presupuesto). Colores desde config('design'). --}}
@php
    $surface = config('design.chrome.surface');
    $line = config('design.chrome.paperLine');
    $soft = config('design.chrome.textSoft');
    $brand = config('design.brand.red');
    $error = config('design.status.light.error');
    $ok = config('design.status.light.ok');
    $warning = config('design.status.light.warning');
    $white = config('design.chrome.onAccent');
@endphp
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>{{ $title }} · {{ $company['name'] }}</title>
<style>
    @page { margin: 28px 32px 44px; }
    body { font-family: Helvetica, Arial, sans-serif; color: {{ $surface }}; font-size: 10.5px; line-height: 1.4; background: {{ $white }}; }
    .brandbar { border-bottom: 1px solid {{ $line }}; padding-bottom: 10px; margin-bottom: 14px; }
    .brandbar td { vertical-align: top; }
    .company { color: {{ $brand }}; font-size: 15px; font-weight: bold; }
    .muted { color: {{ $soft }}; }
    .doc-title { background: {{ $surface }}; color: {{ $white }}; padding: 8px 12px; font-size: 13px; font-weight: bold; margin: 0 0 12px; }
    table.data { width: 100%; border-collapse: collapse; }
    table.data th { background: {{ $surface }}; color: {{ $white }}; font-weight: bold; text-align: left; padding: 6px 8px; font-size: 9.5px; }
    table.data td { padding: 5px 8px; border-bottom: 1px solid {{ $line }}; vertical-align: top; }
    .right { text-align: right; }
    .nowrap { white-space: nowrap; }
    tr.total td { font-weight: bold; border-top: 1.5px solid {{ $surface }}; border-bottom: 0; }
    .highlight { color: {{ $brand }}; font-weight: bold; }
    .stamp-error { color: {{ $error }}; font-weight: bold; }
    .stamp-ok { color: {{ $ok }}; font-weight: bold; }
    .stamp-warning { color: {{ $warning }}; font-weight: bold; }
    .figures { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin: 0 -6px 14px; }
    .figures td { border: 1px solid {{ $line }}; padding: 8px 10px; width: 25%; }
    .figures .label { color: {{ $soft }}; font-size: 9px; }
    .figures .value { font-size: 13px; font-weight: bold; margin-top: 2px; }
    .footer { position: fixed; bottom: -30px; left: 0; right: 0; font-size: 8.5px; color: {{ $soft }}; border-top: 1px solid {{ $line }}; padding-top: 4px; }
    .empty { padding: 16px; text-align: center; color: {{ $soft }}; }
    dl { margin: 0; }
</style>
</head>
<body>
    <div class="footer">
        {{ $company['name'] }} · {{ $title }} · Generado el {{ $generatedAt->format('d/m/Y') }} a las {{ $generatedAt->format('g:i a') }} · {{ $company['system'] }}
    </div>

    <table width="100%" class="brandbar">
        <tr>
            <td>
                <div class="company">{{ $company['name'] }}</div>
                @if ($company['tax_id'])<div class="muted">RTN {{ $company['tax_id'] }}</div>@endif
                @if ($company['contact'])<div class="muted">{{ $company['contact'] }}</div>@endif
            </td>
            <td class="right muted">
                {{ $subtitle ?? '' }}
            </td>
        </tr>
    </table>

    <div class="doc-title">{{ $title }}</div>

    @yield('content')
</body>
</html>
