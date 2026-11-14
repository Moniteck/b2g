@extends('mail.layout', ['title' => $subjectLine])

@section('content')
    @php
        $surface = config('design.chrome.surface');
        $onAccent = config('design.chrome.onAccent');
        $textSoft = config('design.chrome.textSoft');
        $paperLine = config('design.chrome.paperLine');
        $error = config('design.status.light.error');
    @endphp
    <p style="margin:0 0 6px;font-size:13px;color:{{ $textSoft }};">Resumen diario · {{ $dateLabel }}</p>
    <h1 style="margin:0 0 20px;font-family:Poppins,Helvetica,Arial,sans-serif;font-size:20px;line-height:28px;font-weight:600;">
        Hola, {{ $userName }}: tienes {{ $total }} {{ $total === 1 ? 'plazo' : 'plazos' }} en los próximos 7 días
    </h1>

    @foreach ($groups as $group)
        <h2 style="margin:0 0 8px;font-family:Poppins,Helvetica,Arial,sans-serif;font-size:15px;font-weight:600;{{ $group['today'] ? 'color:'.$error.';' : '' }}">{{ $group['label'] }}</h2>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;border-top:1px solid {{ $paperLine }};">
            @foreach ($group['items'] as $item)
                <tr>
                    <td style="padding:10px 0;border-bottom:1px solid {{ $paperLine }};font-size:14px;line-height:20px;">
                        <a href="{{ $item['url'] }}" style="color:{{ $surface }};font-weight:600;text-decoration:underline;">{{ $item['title'] }}</a><br>
                        <span style="font-size:13px;color:{{ $textSoft }};">{{ $item['type'] }} · {{ $item['when'] }}</span>
                    </td>
                </tr>
            @endforeach
        </table>
    @endforeach

    <table role="presentation" cellpadding="0" cellspacing="0">
        <tr>
            <td style="border-radius:999px;background:{{ $surface }};">
                <a href="{{ $calendarUrl }}" style="display:inline-block;padding:12px 24px;font-size:14px;font-weight:600;color:{{ $onAccent }};text-decoration:none;">Abrir el calendario</a>
            </td>
        </tr>
    </table>
@endsection
