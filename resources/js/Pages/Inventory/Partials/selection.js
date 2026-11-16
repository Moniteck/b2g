// Selección de productos que viaja del inventario (o de la calculadora de oferta) a una cotización.
//
// CONTRATO con el módulo de cotizaciones:
//   - «Llevar a una cotización» guarda la selección en sessionStorage bajo SELECTION_KEY y navega a
//     `/cotizaciones?desde_inventario=1`.
//   - Cotizaciones la lee con `readQuoteSelection()` (o `JSON.parse(sessionStorage.getItem(SELECTION_KEY))`),
//     precarga las líneas y la borra con `clearQuoteSelection()`.
//   - Formato (versión 1):
//     {
//       version: 1, source: 'inventario' | 'calculadora', company_id, currency: 'HNL' | 'USD', created_at (ISO),
//       items: [{
//         inventory_item_id (número o null), sku, description, unit, quantity,
//         unit_price,      // precio unitario en la base indicada por tax_included
//         tax_included,    // ¿unit_price ya trae el impuesto?
//         tax_rate,        // porcentaje (15 = 15 %)
//         unit_cost, margin, // solo desde la calculadora (margen sobre la venta)
//         pricing_source,  // 'blessing' | 'calculadora'
//       }],
//       totals: { subtotal, tax, total },  // informativos; la cotización recalcula en el servidor
//     }
//   - Una selección de otra empresa (company_id distinto de la activa) se debe descartar.

export const SELECTION_KEY = 'crm.cotizacion.desde_inventario.v1';

/** Cantidades que se van escribiendo en `/inventario` (sobreviven a filtros y paginación). */
export const DRAFT_KEY = 'crm.inventario.seleccion.borrador.v1';

export const QUOTE_URL = '/cotizaciones?desde_inventario=1';

function storage() {
    try {
        return window.sessionStorage;
    } catch {
        return null;
    }
}

function read(key) {
    try {
        const raw = storage()?.getItem(key);
        return raw ? JSON.parse(raw) : null;
    } catch {
        return null;
    }
}

function write(key, value) {
    try {
        storage()?.setItem(key, JSON.stringify(value));
        return true;
    } catch {
        return false;
    }
}

export function saveQuoteSelection({ source, companyId, currency = 'HNL', items, totals }) {
    return write(SELECTION_KEY, {
        version: 1,
        source,
        company_id: companyId ?? null,
        currency,
        created_at: new Date().toISOString(),
        items,
        totals,
    });
}

export function readQuoteSelection() {
    const value = read(SELECTION_KEY);
    return value?.version === 1 && Array.isArray(value.items) ? value : null;
}

export function clearQuoteSelection() {
    storage()?.removeItem(SELECTION_KEY);
}

/** Borrador de `/inventario`: { company_id, items: { [id]: { item, quantity } } }. */
export function readDraft(companyId) {
    const value = read(DRAFT_KEY);
    return value && value.company_id === (companyId ?? null) && value.items ? value.items : {};
}

export function writeDraft(companyId, items) {
    if (!Object.keys(items).length) {
        storage()?.removeItem(DRAFT_KEY);
        return;
    }
    write(DRAFT_KEY, { company_id: companyId ?? null, items });
}
