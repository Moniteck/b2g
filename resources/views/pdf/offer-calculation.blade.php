@php
    $symbol = $currency === 'USD' ? 'US$' : 'L';
    $money = fn ($value) => $symbol.' '.number_format((float) $value, 2);
    $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, '.', ','), '0'), '.');
    $pct = fn ($value) => number_format((float) $value, 1).' %';
    $surface = config('design.chrome.surface');
    $line = config('design.chrome.paperLine');
    $soft = config('design.chrome.textSoft');
    $brand = config('design.brand.red');
@endphp
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
    body { font-family: Helvetica, Arial, sans-serif; color: {{ $surface }}; font-size: 11px; }
    header { border-bottom: 2px solid {{ $surface }}; padding-bottom: 10px; margin-bottom: 14px; }
    h1 { font-size: 18px; margin: 0; }
    .company { color: {{ $brand }}; font-weight: bold; }
    .muted { color: {{ $soft }}; }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th { background: {{ $surface }}; color: {{ config('design.chrome.onAccent') }}; text-align: left; padding: 6px; font-size: 10px; }
    td { padding: 6px; border-bottom: 1px solid {{ $line }}; vertical-align: top; }
    .r { text-align: right; }
    .totals td { border: none; padding: 3px 6px; }
    .grand td { font-size: 13px; font-weight: bold; color: {{ $brand }}; border-top: 2px solid {{ $surface }}; }
    h2 { font-size: 13px; margin: 18px 0 0; }
    .note { margin-top: 16px; font-size: 9px; color: {{ $soft }}; }
</style>
</head>
<body>
<header>
    <h1>{{ $title }}</h1>
    <p class="muted"><span class="company">{{ $company?->name ?? config('design.company.name') }}</span> · Generado el {{ $generatedAt->format('d/m/Y g:i a') }}</p>
</header>

<table>
    <thead>
        <tr>
            <th>Descripción</th>
            <th class="r">Cantidad</th>
            <th class="r">Costo unit.</th>
            <th class="r">Margen</th>
            <th class="r">Precio unit. sin ISV</th>
            <th class="r">Subtotal</th>
            <th class="r">ISV</th>
            <th class="r">Total</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($result['lines'] as $row)
            <tr>
                <td>{{ $row['description'] }}@if ($row['sku'])<br><span class="muted">{{ $row['sku'] }}</span>@endif</td>
                <td class="r">{{ $qty($row['quantity']) }} {{ $row['unit'] }}</td>
                <td class="r">{{ $money($row['cost']) }}</td>
                <td class="r">{{ $pct($row['margin']) }}</td>
                <td class="r">{{ $money($row['unit_price']) }}</td>
                <td class="r">{{ $money($row['subtotal']) }}</td>
                <td class="r">{{ $row['exempt'] ? 'Exonerado' : $money($row['tax']).' ('.$pct($row['tax_rate']).')' }}</td>
                <td class="r">{{ $money($row['total']) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="totals" style="width: 50%; margin-left: 50%;">
    <tr><td>Costo total</td><td class="r">{{ $money($result['totals']['cost_total']) }}</td></tr>
    <tr><td>Subtotal (sin ISV)</td><td class="r">{{ $money($result['totals']['subtotal']) }}</td></tr>
    @foreach ($result['totals']['taxes'] as $tax)
        <tr><td>{{ $tax['rate'] > 0 ? 'ISV '.$pct($tax['rate']).' sobre '.$money($tax['base']) : 'Exento o exonerado: '.$money($tax['base']) }}</td><td class="r">{{ $money($tax['tax']) }}</td></tr>
    @endforeach
    <tr><td>Ganancia ({{ $pct($result['totals']['margin']) }} sobre la venta)</td><td class="r">{{ $money($result['totals']['profit']) }}</td></tr>
    <tr class="grand"><td>Total</td><td class="r">{{ $money($result['totals']['total']) }}</td></tr>
</table>

@if (count($result['scenarios']))
    <h2>Escenarios de margen</h2>
    <table>
        <thead><tr><th class="r">Margen</th><th class="r">Subtotal</th><th class="r">ISV</th><th class="r">Total</th><th class="r">Ganancia</th></tr></thead>
        <tbody>
            @foreach ($result['scenarios'] as $scenario)
                <tr>
                    <td class="r">{{ $pct($scenario['margin']) }}</td>
                    <td class="r">{{ $money($scenario['subtotal']) }}</td>
                    <td class="r">{{ $money($scenario['tax']) }}</td>
                    <td class="r">{{ $money($scenario['total']) }}</td>
                    <td class="r">{{ $money($scenario['profit']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

<p class="note">Precio = costo ÷ (1 − margen): el margen es sobre la venta. Los precios no incluyen ISV; el impuesto se suma por línea con redondeo a dos decimales.</p>
</body>
</html>
