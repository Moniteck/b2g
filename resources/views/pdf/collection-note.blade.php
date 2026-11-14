@extends('pdf.finance-layout', ['subtitle' => 'Fecha: '.now()->format('d/m/Y')])

@section('content')
    @php($status = $invoice->displayStatus())
    <p>
        <strong>Para:</strong> {{ $invoice->client?->name ?? $invoice->institution?->name ?? 'Cliente' }}
        @if ($invoice->client?->tax_id)<br><span class="muted">RTN {{ $invoice->client->tax_id }}</span>@endif
    </p>

    <p>
        Por medio de la presente le recordamos el saldo pendiente de la factura detallada a continuación.
        Le agradecemos gestionar su pago o comunicarse con nosotros si ya fue realizado.
    </p>

    <table class="data" style="margin-top:12px">
        <thead>
            <tr><th>Factura</th><th>Concepto</th><th>Emisión</th><th>Vencimiento</th><th class="right">Monto (L)</th><th>Estado</th></tr>
        </thead>
        <tbody>
            <tr>
                <td class="nowrap">{{ $invoice->number }}</td>
                <td>{{ $invoice->concept }}</td>
                <td class="nowrap">{{ $invoice->issued_at?->format('d/m/Y') }}</td>
                <td class="nowrap">{{ $invoice->due_at?->format('d/m/Y') }}</td>
                <td class="right nowrap highlight">{{ number_format((float) $invoice->amount, 2) }}</td>
                <td class="{{ $status === 'Vencida' ? 'stamp-error' : ($status === 'Pagada' ? 'stamp-ok' : 'stamp-warning') }}">{{ mb_strtoupper($status) }}</td>
            </tr>
        </tbody>
    </table>

    @if ((float) $invoice->interest_rate > 0)
        <p class="muted" style="margin-top:10px">Tasa de interés moratorio anual: {{ number_format((float) $invoice->interest_rate, 2) }} %.</p>
    @endif

    @if ($invoice->collection_note)
        <p style="margin-top:14px"><strong>Nota:</strong><br>{!! nl2br(e($invoice->collection_note)) !!}</p>
    @endif

    <p style="margin-top:28px">Atentamente,</p>
    <p><strong>{{ $company['name'] }}</strong></p>
@endsection
