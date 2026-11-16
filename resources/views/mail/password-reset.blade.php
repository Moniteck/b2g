@extends('mail.layout', ['title' => 'Crea una contraseña nueva'])

@section('content')
    @php
        $surface = config('design.chrome.surface');
        $onAccent = config('design.chrome.onAccent');
        $textSoft = config('design.chrome.textSoft');
    @endphp
    <h1 style="margin:0 0 16px;font-family:Poppins,Helvetica,Arial,sans-serif;font-size:20px;line-height:28px;font-weight:600;">
        Hola, {{ \Illuminate\Support\Str::before(trim($name), ' ') }}: crea una contraseña nueva
    </h1>

    <p style="margin:0 0 20px;font-size:14px;line-height:22px;">
        Pediste restablecer la contraseña de tu cuenta del CRM. Usa este botón para elegir una nueva.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0">
        <tr>
            <td style="border-radius:999px;background:{{ $surface }};">
                <a href="{{ $url }}" style="display:inline-block;padding:12px 24px;font-size:14px;font-weight:600;color:{{ $onAccent }};text-decoration:none;">Crear contraseña nueva</a>
            </td>
        </tr>
    </table>

    <p style="margin:20px 0 0;font-size:13px;line-height:20px;color:{{ $textSoft }};">
        El enlace vence en {{ $minutes }} minutos y sirve una sola vez. Al cambiarla se cierran las sesiones abiertas en otros equipos.
        Si no lo pediste, ignora este mensaje: tu contraseña no cambia.
    </p>
@endsection
