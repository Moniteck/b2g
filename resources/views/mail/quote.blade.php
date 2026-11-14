{{-- Correo con la cotización en PDF adjunto (guía «PDF y correos»): fondo papel, tarjeta blanca de
     600 px y estilos inline. Va a la institución, así que firma la empresa emisora, no el CRM. --}}
@php
    $paper = config('design.chrome.paper');
    $paperLine = config('design.chrome.paperLine');
    $surface = config('design.chrome.surface');
    $onChrome = config('design.chrome.onChrome');
    $card = config('design.chrome.card');
    $textSoft = config('design.chrome.textSoft');
    $brand = config('design.brand.red');
    $issuer = $quote->company?->name ?? config('design.company.name');
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cotización {{ $quote->number }}</title>
</head>
<body style="margin:0;padding:24px 12px;background:{{ $paper }};font-family:Inter,Helvetica,Arial,sans-serif;color:{{ $surface }};">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:{{ $card }};border:1px solid {{ $paperLine }};border-radius:16px;overflow:hidden;">
        <tr>
            <td style="background:{{ $surface }};padding:18px 28px;">
                <span style="font-family:Poppins,Helvetica,Arial,sans-serif;font-size:15px;font-weight:600;color:{{ $onChrome }};">{{ $issuer }}</span>
            </td>
        </tr>
        <tr>
            <td style="padding:28px;font-size:14px;line-height:22px;">
                <h1 style="margin:0 0 16px;font-family:Poppins,Helvetica,Arial,sans-serif;font-size:20px;line-height:28px;font-weight:600;">Cotización {{ $quote->number }}</h1>

                @if (filled($body))
                    <p style="margin:0 0 16px;">{!! nl2br(e($body)) !!}</p>
                @else
                    <p style="margin:0 0 16px;">Adjuntamos la cotización <strong>{{ $quote->number }}</strong>@if ($quote->institution) para {{ $quote->institution->name }}@endif.</p>
                @endif

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 8px;background:{{ $paper }};border-radius:12px;">
                    <tr>
                        <td style="padding:14px 16px;">
                            <strong>Fecha:</strong> {{ $quote->quoted_at?->format('d/m/Y') }}<br>
                            <strong>Válida hasta:</strong> {{ $quote->validUntil()?->format('d/m/Y') }}<br>
                            <strong>Total:</strong> <span style="color:{{ $brand }};font-weight:600;">L {{ number_format((float) $quote->total, 2) }}</span>
                        </td>
                    </tr>
                </table>
                <p style="margin:12px 0 0;font-size:13px;color:{{ $textSoft }};">El detalle de líneas, impuestos y condiciones está en el PDF adjunto.</p>
            </td>
        </tr>
        <tr>
            <td style="padding:16px 28px;border-top:1px solid {{ $paperLine }};font-size:12px;line-height:18px;color:{{ $textSoft }};">
                Enviado por {{ $sender }} en nombre de <span style="color:{{ $brand }};font-weight:600;">{{ $issuer }}</span>.
            </td>
        </tr>
    </table>
</body>
</html>
