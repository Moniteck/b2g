<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Vista previa del membrete</title>
    <style>
        @page { margin: 32px 40px; }
        body { font-family: Helvetica, Arial, sans-serif; font-size: 12px; color: {{ $colors['surface'] }}; }
        .head { width: 100%; border-bottom: 2px solid {{ $colors['paperLine'] }}; padding-bottom: 12px; }
        .head td { vertical-align: middle; }
        .logo { max-height: 64px; max-width: 200px; }
        .name { font-size: 18px; font-weight: bold; color: {{ $colors['brand'] }}; }
        .muted { color: {{ $colors['soft'] }}; }
        h2 { font-size: 14px; margin: 24px 0 8px; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.items th { background: {{ $colors['surface'] }}; color: {{ $colors['onAccent'] }}; text-align: left; padding: 6px 8px; font-size: 11px; }
        table.items td { border-bottom: 1px solid {{ $colors['paperLine'] }}; padding: 6px 8px; }
        .right, table.items th.right { text-align: right; }
        .footer { margin-top: 32px; border-top: 1px solid {{ $colors['paperLine'] }}; padding-top: 8px; font-size: 10px; }
    </style>
</head>
<body>
    <table class="head">
        <tr>
            <td style="width: 55%;">
                @if ($logo)
                    <img class="logo" src="{{ $logo }}" alt="Logo">
                @else
                    <div class="name">{{ $company->name }}</div>
                @endif
            </td>
            <td class="right">
                @if ($logo)<div class="name">{{ $company->name }}</div>@endif
                @if ($company->tax_id)<div>RTN {{ $company->tax_id }}</div>@endif
                @if (! empty($settings['address']))<div class="muted">{{ $settings['address'] }}@if (! empty($settings['city'])), {{ $settings['city'] }}@endif</div>@endif
                <div class="muted">{{ collect([$settings['phone'] ?? $company->contact_phone, $settings['email'] ?? $company->contact_email, $settings['website'] ?? null])->filter()->implode(' · ') }}</div>
            </td>
        </tr>
    </table>

    <h2>Cotización de ejemplo</h2>
    <p class="muted">Así se verá el membrete en las cotizaciones y documentos de la empresa. Los datos de la tabla son ilustrativos.</p>

    <table class="items">
        <thead>
            <tr><th>Descripción</th><th class="right">Cantidad</th><th class="right">Precio</th><th class="right">Total</th></tr>
        </thead>
        <tbody>
            <tr><td>Producto de ejemplo</td><td class="right">2</td><td class="right">L 1,250.00</td><td class="right">L 2,500.00</td></tr>
            <tr><td>Servicio de ejemplo</td><td class="right">1</td><td class="right">L 800.00</td><td class="right">L 800.00</td></tr>
        </tbody>
    </table>

    @if ($company->representative)
        <p style="margin-top: 40px;">{{ $company->representative }}<br><span class="muted">Representante legal</span></p>
    @endif

    <div class="footer muted">{{ $settings['quote_footer'] ?? 'Pie de página de las cotizaciones (configúrelo en Configuración).' }}</div>
</body>
</html>
