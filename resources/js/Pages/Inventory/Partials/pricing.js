// Espejo en el navegador de App\Services\Pricing\PriceCalculator (servidor). Sirve para mostrar
// subtotal, ISV y total al instante mientras se escribe una cantidad. El servidor sigue siendo la
// fuente de verdad: si cambia una regla allá, se cambia aquí igual (mismos casos que
// tests/Feature/Inventory/PriceCalculatorTest.php).
//
// Regla de redondeo: comercial a 2 decimales, mitad lejos de cero, tras normalizar a 6 decimales.
// Por línea: importe = precio × cantidad; descuento; impuesto sobre el subtotal de la línea.
// Por documento: suma de las líneas ya redondeadas (en centavos enteros).

/** Redondeo comercial a 2 decimales (1.005 → 1.01). */
export function round(value) {
    const number = Number(value);
    if (!Number.isFinite(number)) return 0;
    const normalized = Math.abs(Number(number.toFixed(6)));
    const rounded = Number(`${Math.round(Number(`${normalized}e2`))}e-2`);
    const signed = number < 0 ? -rounded : rounded;
    return signed === 0 ? 0 : signed;
}

const cents = (value) => Math.round(Number(value) * 100);

/**
 * Una línea. `taxIncluded`: el precio ya trae el impuesto. `taxRate` en porcentaje (15 = 15 %).
 * Devuelve { quantity, unitPrice, unitTax, unitTotal, gross, discount, subtotal, taxRate, tax, total }.
 */
export function priceLine({ unitPrice, quantity, taxRate = 15, taxIncluded = false, discount = 0, discountPercent = 0, exempt = false }) {
    const price = Math.max(Number(unitPrice) || 0, 0);
    const qty = Number(quantity) || 0;
    const rate = Number(taxRate) || 0;
    const factor = 1 + rate / 100;
    const appliedRate = exempt ? 0 : rate;

    if (qty <= 0) {
        return { quantity: 0, unitPrice: 0, unitTax: 0, unitTotal: 0, gross: 0, discount: 0, subtotal: 0, taxRate: appliedRate, tax: 0, total: 0 };
    }

    const gross = round(price * qty);
    const discountAmount = Math.min(gross, round((gross * (Number(discountPercent) || 0)) / 100) + round(Number(discount) || 0));
    const amount = round(gross - discountAmount);

    let subtotal;
    let tax;
    let unitNet;
    let unitTax;

    if (taxIncluded) {
        subtotal = round(amount / factor);
        tax = exempt ? 0 : round(amount - subtotal);
        unitNet = round(price / factor);
        unitTax = exempt ? 0 : round(round(price) - unitNet);
    } else {
        subtotal = amount;
        tax = round((subtotal * appliedRate) / 100);
        unitNet = round(price);
        unitTax = round((unitNet * appliedRate) / 100);
    }

    return {
        quantity: qty,
        unitPrice: unitNet,
        unitTax,
        unitTotal: round(unitNet + unitTax),
        gross,
        discount: round(discountAmount),
        subtotal,
        taxRate: appliedRate,
        tax,
        total: round(subtotal + tax),
    };
}

/** Totales de varias líneas: suma exacta de las líneas redondeadas. */
export function documentTotals(lines) {
    const sum = { subtotal: 0, tax: 0, total: 0 };
    lines.forEach((line) => {
        sum.subtotal += cents(line.subtotal);
        sum.tax += cents(line.tax);
        sum.total += cents(line.total);
    });
    return { subtotal: sum.subtotal / 100, tax: sum.tax / 100, total: sum.total / 100, count: lines.length };
}

/** Precio sin impuesto con margen sobre la venta: costo ÷ (1 − margen). */
export function priceFromMargin(cost, marginPercent) {
    const margin = Number(marginPercent) || 0;
    if (margin < 0 || margin >= 100) return null;
    return round((Number(cost) || 0) / (1 - margin / 100));
}

/** Línea de un producto del inventario (filas de `/inventario` y de `/inventario/buscar`). */
export function itemLine(item, quantity) {
    return priceLine({ unitPrice: item.suggested_price, quantity, taxRate: item.tax_rate, taxIncluded: item.tax_included });
}
