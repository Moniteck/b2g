// Utilidades del Dashboard: formato compacto de cifras, variación frente al período anterior,
// colores por tipo de plazo y saludo según la hora de Honduras.
import { compactMoney } from '@/Pages/Finance/Partials/finance.js';
import { money, number, percent } from '@/lib/format.js';

export { compactMoney };

/** Cifra de una tarjeta KPI: «L 4.24 M», «66.7 %» o «—» si no hay dato. */
export function kpiText(value, format) {
    if (value === null || value === undefined) return '—';
    if (format === 'percent') return percent(value);
    if (format === 'currency') return compactMoney(value);
    return number(value);
}

/** Cifra completa para el título accesible de la tarjeta. */
export function kpiFull(value, format) {
    if (value === null || value === undefined) return 'sin dato';
    if (format === 'percent') return percent(value);
    if (format === 'currency') return money(value);
    return number(value);
}

/**
 * Variación frente al período anterior. Porcentaje relativo para montos y puntos para tasas.
 * `inverse`: subir es malo (cartera por cobrar).
 * @returns {{ label: string, direction: 'up'|'down'|'flat', tone: 'good'|'bad'|'neutral' } | null}
 */
export function variation(value, previous, format, inverse = false) {
    if (value === null || value === undefined || previous === null || previous === undefined) return null;
    const diff = Number(value) - Number(previous);
    let label;
    if (format === 'percent') {
        label = `${diff > 0 ? '+' : ''}${number(diff, Math.abs(diff) < 10 ? 1 : 0)} pts`;
    } else if (Number(previous) === 0) {
        if (diff === 0) return { label: 'Sin cambio', direction: 'flat', tone: 'neutral' };
        label = diff > 0 ? 'Nuevo' : `${number(diff)}`;
    } else {
        const pct = (diff / Math.abs(Number(previous))) * 100;
        label = `${pct > 0 ? '+' : ''}${number(pct, Math.abs(pct) < 10 ? 1 : 0)} %`;
    }
    if (Math.abs(diff) < 1e-9) return { label: format === 'percent' ? '0 pts' : 'Sin cambio', direction: 'flat', tone: 'neutral' };
    const direction = diff > 0 ? 'up' : 'down';
    const good = inverse ? direction === 'down' : direction === 'up';
    return { label, direction, tone: good ? 'good' : 'bad' };
}

/** Tipo de plazo → índice de la serie de `chartColors()` y etiqueta de la leyenda. */
export const TIMELINE_CATEGORIES = {
    tender: { label: 'Licitaciones', series: 0, icon: 'gavel' },
    contract: { label: 'Contratos y entregas', series: 1, icon: 'file-sign' },
    document: { label: 'Documentos', series: 2, icon: 'folder-account-outline' },
    collection: { label: 'Cobros', series: 3, icon: 'cash-multiple' },
    other: { label: 'Otros', series: null, icon: 'calendar-month-outline' },
};

/** «Buenos días» / «Buenas tardes» / «Buenas noches» en hora de Honduras. */
export function greeting(now = new Date()) {
    const hour = Number(new Intl.DateTimeFormat('en-US', { timeZone: 'America/Tegucigalpa', hour: 'numeric', hour12: false }).format(now)) % 24;
    if (hour < 12) return 'Buenos días';
    if (hour < 19) return 'Buenas tardes';
    return 'Buenas noches';
}

/** Fecha «AAAA-MM-DD» de hoy en Honduras. */
export function todayKey(now = new Date()) {
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Tegucigalpa', year: 'numeric', month: '2-digit', day: '2-digit' }).format(now);
}

/** Suma días a una fecha «AAAA-MM-DD» (sin husos: aritmética en UTC). */
export function addDays(key, days) {
    const [y, m, d] = key.split('-').map(Number);
    return new Date(Date.UTC(y, m - 1, d + days)).toISOString().slice(0, 10);
}
