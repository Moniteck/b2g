{{-- Listado de licitaciones con los filtros de la pantalla. Colores desde config('design') (finance-layout). --}}
@extends('pdf.finance-layout', ['subtitle' => $filterLabel])

@section('content')
    <table class="data">
        <thead>
            <tr>
                <th>Expediente</th><th>Objeto</th><th>Institución</th><th>Próximo plazo</th><th>Recepción de ofertas</th>
                <th>Etapa</th><th>Responsable</th><th class="right">Monto (L)</th><th>Resultado</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td class="nowrap">{{ $row['reference'] }}</td>
                    <td>{{ \Illuminate\Support\Str::limit($row['title'] ?? '', 160) }}</td>
                    <td>{{ $row['institution']['acronym'] ?? $row['institution']['name'] ?? '—' }}</td>
                    <td class="nowrap">
                        @if ($row['next_deadline'])
                            {{ $row['next_deadline']['label'] }}<br><span class="muted">{{ \Carbon\CarbonImmutable::parse($row['next_deadline']['at'])->format('d/m/Y g:i a') }}</span>
                        @else
                            —
                        @endif
                    </td>
                    <td class="nowrap">{{ $row['presentation_deadline'] ? \Carbon\CarbonImmutable::parse($row['presentation_deadline'])->format('d/m/Y g:i a') : '—' }}</td>
                    <td class="{{ ['ok' => 'stamp-ok', 'error' => 'stamp-error', 'warning' => 'stamp-warning'][$row['stage']['tone']] ?? '' }}">{{ $row['stage']['status_label'] }}</td>
                    <td>{{ $row['owner']['name'] ?? '—' }}</td>
                    <td class="right nowrap">{{ (float) $row['estimated_amount'] > 0 ? number_format((float) $row['estimated_amount'], 2) : '—' }}</td>
                    <td>{{ $row['result'] ?: '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="9" class="empty">No hay licitaciones con estos filtros.</td></tr>
            @endforelse
            @if ($rows->isNotEmpty())
                <tr class="total"><td colspan="7">Total ({{ $rows->count() }} licitaciones)</td><td class="right highlight nowrap">{{ number_format($rows->sum(fn ($r) => (float) $r['estimated_amount']), 2) }}</td><td></td></tr>
            @endif
        </tbody>
    </table>
@endsection
