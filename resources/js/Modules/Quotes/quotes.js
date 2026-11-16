// Reglas y textos de la pantalla de cotizaciones que no son de un solo componente.
import { documentTotals, priceLine } from '../Inventory/pricing.js';

/** Qué significa pasar a cada estado (modal «Cambiar estado»). */
export const STATUS_HELP = {
    Borrador: 'Vuelve a edición interna.',
    Emitida: 'Lista para enviar a la institución; aún se puede editar.',
    Presentada: 'Se entregó la oferta: ya no se edita. Se registra nuestra oferta entre los participantes y la oportunidad pasa a Oferta Presentada.',
    Sustituida: 'Reemplazada por otra revisión de la misma oportunidad.',
    Anulada: 'Queda sin efecto y no admite cambios.',
};

let lineSeed = 0;

/** Línea vacía del formulario (texto libre). `tax_included: null` sigue la base de la cotización. */
export function emptyLine(overrides = {}) {
    lineSeed += 1;
    return {
        key: `l${Date.now()}-${lineSeed}`,
        id: null,
        inventory_item_id: null,
        sku: '',
        description: '',
        quantity: 1,
        unit: 'Unidad',
        unit_price: null,
        tax_rate: 15,
        discount_percent: 0,
        tax_included: null,
        pricing_source: 'manual',
        unit_cost: null,
        margin: null,
        ...overrides,
    };
}

/** Línea desde un producto de `/inventario/buscar`. */
export function lineFromProduct(product, quantity = 1) {
    return emptyLine({
        inventory_item_id: product.id,
        sku: product.sku ?? '',
        description: product.description ?? product.label ?? '',
        quantity,
        unit: product.unit || 'Unidad',
        unit_price: Number(product.suggested_price ?? product.unit_price ?? 0),
        tax_rate: Number(product.tax_rate ?? 15),
        tax_included: Boolean(product.tax_included),
        pricing_source: 'blessing',
    });
}

/** Línea desde la selección que trae el inventario o la calculadora (`Modules/Inventory/selection.js`). */
export function lineFromSelection(item) {
    return emptyLine({
        inventory_item_id: item.inventory_item_id ?? null,
        sku: item.sku ?? '',
        description: item.description ?? '',
        quantity: Number(item.quantity) || 1,
        unit: item.unit || 'Unidad',
        unit_price: item.unit_price !== undefined && item.unit_price !== null ? Number(item.unit_price) : null,
        tax_rate: Number(item.tax_rate ?? 15),
        tax_included: item.tax_included === undefined ? null : Boolean(item.tax_included),
        pricing_source: item.pricing_source === 'calculadora' ? 'calculadora' : item.inventory_item_id ? 'blessing' : 'manual',
    });
}

/** Línea del servidor (detalle de la cotización) al formato del formulario. */
export function lineFromServer(item) {
    return emptyLine({ ...item, sku: item.sku ?? '', tax_included: Boolean(item.tax_included) });
}

/** Cálculo de una línea con la misma regla que el servidor. */
export function priced(line, quoteTaxIncluded) {
    return priceLine({
        unitPrice: Number(line.unit_price) || 0,
        quantity: Number(line.quantity) || 0,
        taxRate: Number(line.tax_rate) || 0,
        taxIncluded: line.tax_included ?? quoteTaxIncluded,
        discountPercent: Number(line.discount_percent) || 0,
    });
}

/** Totales del documento y desglose por tasa (espejo de PriceCalculator::document()). */
export function quoteTotals(lines, quoteTaxIncluded) {
    const computed = lines.map((line) => priced(line, quoteTaxIncluded));
    const byRate = new Map();
    let discount = 0;
    computed.forEach((line) => {
        const entry = byRate.get(line.taxRate) ?? { rate: line.taxRate, base: 0, tax: 0 };
        entry.base += Math.round(line.subtotal * 100);
        entry.tax += Math.round(line.tax * 100);
        byRate.set(line.taxRate, entry);
        discount += Math.round(line.discount * 100);
    });
    const taxes = [...byRate.values()].sort((a, b) => a.rate - b.rate).map((entry) => ({ rate: entry.rate, base: entry.base / 100, tax: entry.tax / 100 }));
    return { ...documentTotals(computed), discount: discount / 100, taxes, lines: computed };
}

const optionalNumber = (value) => (value === '' || value === null || value === undefined ? null : value);

/**
 * Datos que se envían al servidor por línea. Costo y margen solo si el usuario puede verlos (`withCost`);
 * si no se envían, el servidor conserva los de la línea existente (se reconoce por `id`).
 */
export function linePayload(line, withCost = false) {
    return {
        id: line.id || null,
        ...(withCost ? { unit_cost: optionalNumber(line.unit_cost), margin: optionalNumber(line.margin) } : {}),
        description: line.description,
        quantity: line.quantity,
        unit: line.unit,
        unit_price: line.unit_price === '' ? null : line.unit_price,
        tax_rate: line.tax_rate,
        discount_percent: line.discount_percent || 0,
        tax_included: line.tax_included,
        inventory_item_id: line.inventory_item_id || null,
        sku: line.sku || null,
        pricing_source: line.pricing_source || 'manual',
    };
}

export const today = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Tegucigalpa' }).format(new Date());

/** «2026-11-13» + n días → «2026-12-13» (sin zona: fecha de pared de Honduras). */
export function addDays(isoDate, days) {
    if (!isoDate || !Number(days)) return '';
    const [year, month, day] = String(isoDate).slice(0, 10).split('-').map(Number);
    const result = new Date(Date.UTC(year, month - 1, day + Number(days)));
    return result.toISOString().slice(0, 10);
}
