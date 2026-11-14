@extends('mail.layout', ['title' => 'Acceso aprobado', 'companyName' => $companyName])

@section('content')
    @php
        $surface = config('design.chrome.surface');
        $onAccent = config('design.chrome.onAccent');
        $textSoft = config('design.chrome.textSoft');
    @endphp
    <h1 style="margin:0 0 16px;font-family:Poppins,Helvetica,Arial,sans-serif;font-size:20px;line-height:28px;font-weight:600;">
        ¡Hola, {{ $person }}! Ya tienes acceso
    </h1>

    <p style="margin:0 0 20px;font-size:14px;line-height:22px;">
        Tu solicitud fue aprobada. Entras a <strong>{{ $companyName }}</strong> con el rol <strong>{{ $roleLabel }}</strong>,
        usando tu correo y la contraseña que elegiste al solicitar el acceso.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0">
        <tr>
            <td style="border-radius:999px;background:{{ $surface }};">
                <a href="{{ $url }}" style="display:inline-block;padding:12px 24px;font-size:14px;font-weight:600;color:{{ $onAccent }};text-decoration:none;">Ingresar al CRM</a>
            </td>
        </tr>
    </table>

    <p style="margin:20px 0 0;font-size:13px;line-height:20px;color:{{ $textSoft }};">
        Si no recuerdas la contraseña, pide al administrador de tu empresa que la restablezca desde Usuarios.
    </p>
@endsection
