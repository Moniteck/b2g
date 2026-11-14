@php
    /** @var \App\Models\Quote $quote */
    /** @var \App\Services\Pricing\PriceDocument $document */
    $money = fn ($value) => 'L '.number_format((float) $value, 2);
    $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, '.', ','), '0'), '.');
    $pct = fn ($value) => rtrim(rtrim(number_format((float) $value, 2), '0'), '.').' %';
    $surface = config('design.chrome.surface');
    $onAccent = config('design.chrome.onAccent');
    $line = config('design.chrome.paperLine');
    $soft = config('design.chrome.textSoft');
    $brand = config('design.brand.red');
    $error = config('design.status.light.error');
    $company = $quote->company;
    $stamp = in_array($quote->status, ['Anulada', 'Sustituida'], true) ? mb_strtoupper($quote->status) : null;
    $allIncluded = $quote->items->isNotEmpty() && $quote->items->every(fn ($item) => $item->tax_included);
    $noneIncluded = $quote->items->every(fn ($item) => ! $item->tax_included);
@endphp
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Cotización {{ $quote->number }}</title>
<style>
    @page { margin: 28px 32px 48px; }
    body { font-family: Helvetica, Arial, sans-serif; color: {{ $surface }}; font-size: 10.5px; line-height: 1.4; }
    .bar { background: {{ $surface }}; color: {{ $onAccent }}; padding: 14px 16px; }
    .bar table { width: 100%; border-collapse: collapse; }
    .bar td { vertical-align: top; padding: 0; color: {{ $onAccent }}; }
    .bar h1 { margin: 0; font-size: 18px; letter-spacing: .2px; }
    .bar .number { font-size: 12px; margin-top: 2px; }
    .issuer { font-size: 13px; font-weight: bold; color: {{ $brand }}; }
    .muted { color: {{ $soft }}; }
    .grid { width: 100%; border-collapse: collapse; margin-top: 14px; }
    .grid td { vertical-align: top; padding: 0 12px 0 0; width: 50%; }
    .label { font-size: 9px; color: {{ $soft }}; text-transform: none; margin-bottom: 2px; }
    .box { border: 1px solid {{ $line }}; padding: 10px 12px; }
    .lines { width: 100%; border-collapse: collapse; margin-top: 16px; }
    .lines th { background: {{ $surface }}; color: {{ $onAccent }}; text-align: left; padding: 6px; font-size: 9.5px; font-weight: bold; }
    .lines td { padding: 6px; border-bottom: 1px solid {{ $line }}; vertical-align: top; }
    .r { text-align: right; }
    .totals { width: 46%; margin-left: 54%; border-collapse: collapse; margin-top: 10px; }
    .totals td { padding: 4px 6px; }
    .totals .grand td { font-size: 13px; font-weight: bold; color: {{ $brand }}; border-top: 2px solid {{ $surface }}; padding-top: 6px; }
    .note { margin-top: 12px; padding: 8px 10px; border: 1px solid {{ $line }}; font-size: 9.5px; color: {{ $soft }}; }
    h2 { font-size: 11px; margin: 16px 0 4px; }
    .stamp { position: fixed; top: 300px; left: 120px; font-size: 64px; font-weight: bold; color: {{ $error }}; border: 5px solid {{ $error }}; padding: 6px 24px; opacity: .25; transform: rotate(-18deg); }
    footer { position: fixed; bottom: -30px; left: 0; right: 0; font-size: 8.5px; color: {{ $soft }}; border-top: 1px solid {{ $line }}; padding-top: 4px; }
</style>
</head>
<body>
@if ($stamp)<div class="stamp">{{ $stamp }}</div>@endif

<div class="bar">
    <table>
        <tr>
            <td>
                <h1>Cotización</h1>
                <div class="number">{{ $quote->number }}@if ($quote->revision > 1) · Revisión {{ $quote->revision }}@endif</div>
            </td>
            <td class="r">
                <div>Fecha: {{ $quote->quoted_at?->format('d/m/Y') }}</div>
                <div>Válida hasta: {{ $quote->validUntil()?->format('d/m/Y') }} ({{ $quote->validity_days }} días)</div>
                @if ($quote->tender)<div>Proceso: {{ $quote->tender->reference }}</div>@endif
            </td>
        </tr>
    </table>
</div>

<table class="grid">
    <tr>
        <td>
            <div class="box">
                <div class="label">Emite</div>
                <div class="issuer">{{ $company?->name ?? config('design.company.name') }}</div>
                @if ($company?->tax_id)<div>RTN: {{ $company->tax_id }}</div>@endif
                @if ($company?->representative)<div>Representante: {{ $company->representative }}</div>@endif
                @if ($company?->contact_phone || $company?->contact_email)<div class="muted">{{ collect([$company?->contact_phone, $company?->contact_email])->filter()->implode(" · ") }}</div>@endif
            </div>
        </td>
        <td style="padding-right: 0;">
            <div class="box">
                <div class="label">Para</div>
                <div style="font-weight: bold;">{{ $quote->institution?->name ?? '—' }}</div>
                <div>Atención: {{ $quote->contact?->name ?? 'Jefe de Compras' }}@if ($quote->contact?->position), {{ $quote->contact->position }}@endif</div>
                @if ($quote->contact?->email || $quote->contact?->phone)<div class="muted">{{ collect([$quote->contact?->email, $quote->contact?->phone])->filter()->implode(' · ') }}</div>@endif
            </div>
        </td>
    </tr>
</table>

<table class="lines">
    <thead>
        <tr>
            <th style="width: 4%;">#</th>
            <th>Descripción</th>
            <th class="r" style="width: 10%;">Cantidad</th>
            <th style="width: 9%;">Unidad</th>
            <th class="r" style="width: 13%;">P. unitario{{ $allIncluded ? ' (con ISV)' : ' (sin ISV)' }}</th>
            <th class="r" style="width: 8%;">Desc.</th>
            <th class="r" style="width: 9%;">ISV</th>
            <th class="r" style="width: 14%;">Total</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($document->lines as $index => $priced)
            @php $item = $quote->items[$index]; @endphp
            <tr>
                <td class="muted">{{ $index + 1 }}</td>
                <td>{{ $item->description }}@if ($item->sku)<br><span class="muted">{{ $item->sku }}</span>@endif</td>
                <td class="r">{{ $qty($item->quantity) }}</td>
                <td>{{ $item->unit }}</td>
                <td class="r">{{ $money($allIncluded ? $priced->unitTotal : $priced->unitPrice) }}</td>
                <td class="r">{{ (float) $item->discount_percent > 0 ? $pct($item->discount_percent) : '—' }}</td>
                <td class="r">{{ $priced->taxRate > 0 ? $pct($priced->taxRate) : 'Exento' }}</td>
                <td class="r">{{ $money($priced->total) }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="muted">Sin líneas registradas.</td></tr>
        @endforelse
    </tbody>
</table>

<table class="totals">
    @if ($document->discount > 0)
        <tr><td>Descuentos</td><td class="r">− {{ $money($document->discount) }}</td></tr>
    @endif
    <tr><td>Subtotal (sin ISV)</td><td class="r">{{ $money($document->subtotal) }}</td></tr>
    @foreach ($document->taxes as $tax)
        <tr>
            <td>{{ $tax['rate'] > 0 ? 'ISV '.$pct($tax['rate']).' sobre '.$money($tax['base']) : 'Exento: '.$money($tax['base']) }}</td>
            <td class="r">{{ $money($tax['tax']) }}</td>
        </tr>
    @endforeach
    <tr class="grand"><td>Total</td><td class="r">{{ $money($document->total) }}</td></tr>
</table>

<p class="note">
    @if ($allIncluded)
        Los precios unitarios incluyen el impuesto sobre ventas (ISV); el total no suma impuesto adicional y el ISV contenido se detalla arriba.
    @elseif ($noneIncluded)
        Los precios unitarios no incluyen el impuesto sobre ventas (ISV); el ISV se suma en el total según la tasa de cada línea.
    @else
        Los precios unitarios se muestran sin ISV; el impuesto de cada línea se suma en el total.
    @endif
    Montos en lempiras (HNL).
</p>

@if ($quote->conditions)
    <h2>Condiciones</h2>
    <div>{!! nl2br(e($quote->conditions)) !!}</div>
@endif

@if ($quote->notes)
    <h2>Notas</h2>
    <div>{!! nl2br(e($quote->notes)) !!}</div>
@endif

<footer>
    {{ $company?->name ?? config('design.company.name') }} · Cotización {{ $quote->number }} · Generada el {{ now()->format('d/m/Y g:i a') }}
</footer>
</body>
</html>
