@extends('mail.layout', ['title' => $heading])

@section('content')
    @php
        $surface = config('design.chrome.surface');
        $onAccent = config('design.chrome.onAccent');
        $textSoft = config('design.chrome.textSoft');
        $paper = config('design.chrome.paper');
    @endphp
    <p style="margin:0 0 6px;font-size:13px;color:{{ $textSoft }};">{{ $typeLabel }} · {{ $offsetLabel }}</p>
    <h1 style="margin:0 0 16px;font-family:Poppins,Helvetica,Arial,sans-serif;font-size:20px;line-height:28px;font-weight:600;">
        {{ $heading }}: {{ $event->label ?: $event->title }}
    </h1>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;background:{{ $paper }};border-radius:12px;">
        <tr>
            <td style="padding:14px 16px;font-size:14px;line-height:22px;">
                <strong>Plazo:</strong> {{ $when }}<br>
                <strong>Registro:</strong> {{ $sourceLabel }} · {{ $event->title }}
                @if ($event->description)
                    <br><span style="color:{{ $textSoft }};">{{ $event->description }}</span>
                @endif
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0">
        <tr>
            <td style="border-radius:999px;background:{{ $surface }};">
                <a href="{{ $url }}" style="display:inline-block;padding:12px 24px;font-size:14px;font-weight:600;color:{{ $onAccent }};text-decoration:none;">Abrir el registro</a>
            </td>
        </tr>
    </table>

    <p style="margin:20px 0 0;font-size:13px;line-height:20px;color:{{ $textSoft }};">
        Si ya lo resolviste, márcalo como atendido en el calendario para detener los avisos de este plazo.
    </p>
@endsection
