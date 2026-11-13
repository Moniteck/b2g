@extends('pdf.finance-layout', ['subtitle' => 'Período: '.$period])

@section('content')
    @php($metrics = $report['metrics'])
    <table class="figures">
        <tr>
            <td><div class="label">Pipeline ponderado</div><div class="value">L {{ number_format($metrics['pipeline_weighted'], 2) }}</div><div class="muted">{{ $metrics['pipeline_count'] }} oportunidades activas</div></td>
            <td><div class="label">Monto adjudicado</div><div class="value">L {{ number_format($metrics['won_amount'], 2) }}</div><div class="muted">{{ $metrics['won_count'] }} cotizaciones ganadas</div></td>
            <td><div class="label">Tasa de adjudicación</div><div class="value">{{ $metrics['award_rate'] === null ? '—' : number_format($metrics['award_rate'], 1).' %' }}</div><div class="muted">{{ $metrics['won_count'] }} ganadas · {{ $metrics['lost_count'] }} perdidas</div></td>
            <td><div class="label">Cobros recibidos</div><div class="value">L {{ number_format($metrics['collected'], 2) }}</div><div class="muted">{{ $metrics['collected_count'] }} facturas</div></td>
        </tr>
    </table>

    <table class="data">
        <thead>
            <tr><th>Indicador</th><th>Módulo</th><th class="right">Registros</th><th class="right">Monto (L)</th></tr>
        </thead>
        <tbody>
            @forelse ($report['rows'] as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td>{{ $row['module_label'] }}</td>
                    <td class="right">{{ $row['count'] }}</td>
                    <td class="right nowrap">{{ number_format($row['amount'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">Sin datos para el período.</td></tr>
            @endforelse
        </tbody>
    </table>

    <p style="margin-top:18px; font-weight:bold;">Embudo comercial (oportunidades creadas en el período)</p>
    <table class="data">
        <thead><tr><th>Etapa</th><th class="right">Oportunidades</th><th class="right">Monto (L)</th></tr></thead>
        <tbody>
            @foreach ($report['funnel'] as $stage)
                <tr><td>{{ $stage['label'] }}</td><td class="right">{{ $stage['count'] }}</td><td class="right nowrap">{{ number_format($stage['amount'], 2) }}</td></tr>
            @endforeach
        </tbody>
    </table>
@endsection
