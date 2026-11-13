@extends('pdf.tools.layout')

@section('content')
    <p><strong>Progreso:</strong> {{ $summary['complies'] }} de {{ $summary['total'] }} requisitos cumplen ({{ $summary['progress'] }} %).
        Pendientes: {{ $summary['pending'] }}. Vencidos: {{ $summary['expired'] }}.</p>

    <table>
        <thead>
        <tr>
            <th style="width: 4%">#</th>
            <th>Requisito</th>
            <th style="width: 24%">Documento</th>
            <th style="width: 13%">Vence</th>
            <th style="width: 12%">Estado</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($items as $index => $item)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $item['requirement'] }}@if (! empty($item['notes']))<br><span class="soft">{{ $item['notes'] }}</span>@endif</td>
                <td>{{ $item['document'] ?? '—' }}</td>
                <td>{{ $item['renewal_due_at'] ? \Illuminate\Support\Carbon::parse($item['renewal_due_at'])->format('d/m/Y') : '—' }}</td>
                <td class="{{ ['Cumple' => 'ok', 'Pendiente' => 'warning', 'Vencido' => 'error'][$item['status']] ?? '' }}">{{ $item['status'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endsection
