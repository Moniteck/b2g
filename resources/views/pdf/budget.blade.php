@extends('pdf.finance-layout', ['subtitle' => 'Al '.now()->format('d/m/Y')])

@section('content')
    <table class="figures">
        <tr>
            <td><div class="label">Monto inicial</div><div class="value">L {{ number_format((float) $budget->initial_amount, 2) }}</div></td>
            <td><div class="label">Ingresos</div><div class="value">L {{ number_format($stats['income'] + $stats['projected'], 2) }}</div></td>
            <td><div class="label">Egresos</div><div class="value">L {{ number_format($stats['expense'], 2) }}</div></td>
            <td><div class="label">Saldo actual</div><div class="value highlight">L {{ number_format((float) $budget->current_balance, 2) }}</div></td>
        </tr>
    </table>

    <table class="data">
        <thead>
            <tr><th>Fecha</th><th>Tipo</th><th>Concepto</th><th>Referencia</th><th class="right">Monto (L)</th><th class="right">Saldo (L)</th></tr>
        </thead>
        <tbody>
            @forelse ($movements as $movement)
                <tr>
                    <td class="nowrap">{{ $movement->moved_at?->format('d/m/Y') }}</td>
                    <td>{{ $movement->type }}{{ $movement->synchronized ? ' (sincronizado)' : '' }}</td>
                    <td>{{ $movement->concept }}</td>
                    <td>{{ $movement->reference ?? '—' }}</td>
                    <td class="right nowrap">{{ $movement->isExpense() ? '-' : '' }}{{ number_format((float) $movement->amount, 2) }}</td>
                    <td class="right nowrap">{{ $movement->balance_after === null ? '—' : number_format((float) $movement->balance_after, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">Aún no hay movimientos.</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection
