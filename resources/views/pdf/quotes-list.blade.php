<!doctype html>
<html lang="es"><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;color:#1f2937;font-size:11px}header{border-bottom:2px solid #0057b8;padding-bottom:10px}h1{color:#0057b8;margin:0;font-size:20px}table{width:100%;border-collapse:collapse;margin-top:14px}th{background:#eff6ff;color:#1e40af;text-align:left}th,td{padding:6px;border-bottom:1px solid #e5e7eb}.right{text-align:right}.total{font-weight:bold;background:#f0fdfa}.muted{color:#6b7280}</style></head><body>
<header><h1>Listado de cotizaciones</h1><p class="muted">{{ count($quotes) }} registros · generado el {{ now()->format('d/m/Y H:i') }}</p></header>
<table><thead><tr><th>Número</th><th>Empresa</th><th>Institución</th><th>Fecha</th><th>Tipo</th><th class="right">Total</th><th>Estado</th><th>Resultado</th></tr></thead><tbody>
@forelse($quotes as $quote)<tr><td>{{ $quote->number }}</td><td>{{ $quote->company?->name }}</td><td>{{ $quote->institution?->name }}</td><td>{{ $quote->quoted_at?->format('d/m/Y') }}</td><td>{{ $quote->purchase_type }}</td><td class="right">L {{ number_format($quote->total, 2) }}</td><td>{{ $quote->status }}</td><td>{{ $quote->outcome }}</td></tr>
@empty<tr><td colspan="8" class="muted">No hay cotizaciones para los filtros aplicados.</td></tr>@endforelse
</tbody><tfoot><tr class="total"><td colspan="5">TOTAL</td><td class="right">L {{ number_format($quotes->sum('total'), 2) }}</td><td colspan="2"></td></tr></tfoot></table>
<p class="muted">Los precios incluyen el impuesto correspondiente.</p>
</body></html>
