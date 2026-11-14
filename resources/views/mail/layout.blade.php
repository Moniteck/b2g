{{-- Marco común de los correos del CRM (guía «PDF y correos»): fondo papel, tarjeta blanca de
     600 px, un solo botón sólido del color del marco y estilos inline. Colores desde config('design'). --}}
@php
    $paper = config('design.chrome.paper');
    $paperLine = config('design.chrome.paperLine');
    $surface = config('design.chrome.surface');
    $onChrome = config('design.chrome.onChrome');
    $card = config('design.chrome.card');
    $textSoft = config('design.chrome.textSoft');
    $brand = config('design.brand.red');
    $ink = config('design.chrome.surface');
    $owner = config('design.company.name');
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Recordatorio' }}</title>
</head>
<body style="margin:0;padding:24px 12px;background:{{ $paper }};font-family:Inter,Helvetica,Arial,sans-serif;color:{{ $ink }};">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:{{ $card }};border:1px solid {{ $paperLine }};border-radius:16px;overflow:hidden;">
        <tr>
            <td style="background:{{ $surface }};padding:18px 28px;">
                <span style="font-family:Poppins,Helvetica,Arial,sans-serif;font-size:15px;font-weight:600;color:{{ $onChrome }};">CRM B2G</span>
                @if (! empty($companyName))
                    <span style="font-size:13px;color:{{ $onChrome }};"> · {{ $companyName }}</span>
                @endif
            </td>
        </tr>
        <tr>
            <td style="padding:28px;">
                @yield('content')
            </td>
        </tr>
        <tr>
            <td style="padding:16px 28px;border-top:1px solid {{ $paperLine }};font-size:12px;line-height:18px;color:{{ $textSoft }};">
                Mensaje automático del CRM B2G. Puedes ajustar los avisos en el calendario, en «Reglas de recordatorio».<br>
                <span style="color:{{ $brand }};font-weight:600;">{{ $owner }}</span>
            </td>
        </tr>
    </table>
</body>
</html>
