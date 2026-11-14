@extends('pdf.tools.layout')

@section('content')
    <table>
        <tr><th style="width: 28%">Código</th><td>{{ $sop['code'] }}</td></tr>
        <tr><th>Propósito</th><td>{{ $sop['purpose'] }}</td></tr>
        <tr><th>Alcance</th><td>{{ $sop['scope'] }}</td></tr>
        <tr><th>Responsable</th><td>{{ $sop['owner'] }}</td></tr>
    </table>

    <h2>Procedimiento</h2>
    <ol class="steps">
        @foreach ($sop['steps'] as $step)
            <li><strong>{{ $step['title'] }}.</strong> {{ $step['detail'] }}</li>
        @endforeach
    </ol>

    @if (! empty($sop['notes']))
        <h2>Notas</h2>
        @foreach ($sop['notes'] as $note)
            <p>{{ $note }}</p>
        @endforeach
    @endif
@endsection
