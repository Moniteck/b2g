@extends('pdf.finance-layout', ['subtitle' => 'Filtro: '.$filterLabel])

@section('content')
    <table class="data">
        <thead>
            <tr>
                <th>Factura</th><th>Concepto</th><th>Cliente o institución</th><th>Emisión</th><th>Vencimiento</th><th>Pago</th>
                <th class="right">Monto (L)</th><th class="right">Interés (L)</th><th>Estado</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($invoices as $invoice)
                @php($status = $invoice->displayStatus())
                <tr>
                    <td class="nowrap">{{ $invoice->number }}</td>
                    <td>{{ $invoice->concept }}</td>
                    <td>{{ $invoice->client?->name ?? $invoice->institution?->name ?? '—' }}</td>
                    <td class="nowrap">{{ $invoice->issued_at?->format('d/m/Y') ?? '—' }}</td>
                    <td class="nowrap">{{ $invoice->due_at?->format('d/m/Y') ?? '—' }}</td>
                    <td class="nowrap">{{ $invoice->paid_at?->format('d/m/Y') ?? '—' }}</td>
                    <td class="right nowrap">{{ number_format((float) $invoice->amount, 2) }}</td>
                    <td class="right nowrap">{{ (float) $invoice->interest_amount > 0 ? number_format((float) $invoice->interest_amount, 2) : '—' }}</td>
                    <td class="{{ $status === 'Vencida' ? 'stamp-error' : ($status === 'Pagada' ? 'stamp-ok' : 'stamp-warning') }}">{{ $status }}</td>
                </tr>
            @empty
                <tr><td colspan="9" class="empty">No hay facturas con este filtro.</td></tr>
            @endforelse
            @if ($invoices->isNotEmpty())
                <tr class="total"><td colspan="6">Total ({{ $invoices->count() }} facturas)</td><td class="right highlight nowrap">{{ number_format($total, 2) }}</td><td colspan="2"></td></tr>
            @endif
        </tbody>
    </table>
@endsection
