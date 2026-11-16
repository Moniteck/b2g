@extends('mail.layout', ['title' => 'Confirma tu correo'])

@section('content')
    @php
        $surface = config('design.chrome.surface');
        $onAccent = config('design.chrome.onAccent');
        $textSoft = config('design.chrome.textSoft');
    @endphp
    <h1 style="margin:0 0 16px;font-family:Poppins,Helvetica,Arial,sans-serif;font-size:20px;line-height:28px;font-weight:600;">
        Hola, {{ $applicant }}: confirma tu correo
    </h1>

    <p style="margin:0 0 20px;font-size:14px;line-height:22px;">
        Recibimos tu solicitud de acceso al CRM. Para enviarla a los administradores, confirma que este correo es tuyo.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0">
        <tr>
            <td style="border-radius:999px;background:{{ $surface }};">
                <a href="{{ $url }}" style="display:inline-block;padding:12px 24px;font-size:14px;font-weight:600;color:{{ $onAccent }};text-decoration:none;">Confirmar mi correo</a>
            </td>
        </tr>
    </table>

    <p style="margin:20px 0 0;font-size:13px;line-height:20px;color:{{ $textSoft }};">
        El enlace vence en {{ $hours }} horas. Si no pediste acceso, ignora este mensaje: sin tu confirmación la solicitud no avanza y nadie podrá entrar con tu correo.
    </p>
@endsection
