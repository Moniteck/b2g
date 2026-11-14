// Utilidades del calendario: fechas como claves «AAAA-MM-DD» en hora de pared de Honduras
// (sin zonas en medio), rejilla mensual que empieza en lunes, colores por tipo y reparto de barras.
import { TIME_ZONE } from '../../lib/format.js';

const DAY_MS = 86_400_000;

/** Hoy en Honduras como «AAAA-MM-DD». */
export function todayKey() {
    return new Intl.DateTimeFormat('en-CA', { timeZone: TIME_ZONE, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
}

/** «AAAA-MM-DD» (o con hora) → número de día. */
export function dayNumber(value) {
    const [year, month, day] = String(value).slice(0, 10).split('-').map(Number);
    return Math.round(Date.UTC(year, month - 1, day) / DAY_MS);
}

export function keyOf(number) {
    return new Date(number * DAY_MS).toISOString().slice(0, 10);
}

export function addDays(key, days) {
    return keyOf(dayNumber(key) + days);
}

/** Mes «AAAA-MM» desplazado `delta` meses. */
export function shiftMonth(month, delta) {
    const [year, number] = month.split('-').map(Number);
    const date = new Date(Date.UTC(year, number - 1 + delta, 1));
    return date.toISOString().slice(0, 7);
}

export function monthLabel(month) {
    const [year, number] = month.split('-').map(Number);
    const text = new Intl.DateTimeFormat('es-HN', { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(Date.UTC(year, number - 1, 1)));
    return text.charAt(0).toUpperCase() + text.slice(1);
}

export const WEEKDAYS = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];

/** Semanas visibles del mes (de lunes a domingo): [[clave × 7], …]. */
export function monthWeeks(month) {
    const first = dayNumber(`${month}-01`);
    const weekday = (new Date(first * DAY_MS).getUTCDay() + 6) % 7;
    const next = dayNumber(`${shiftMonth(month, 1)}-01`);
    const start = first - weekday;
    const count = Math.ceil((next - start) / 7);
    return Array.from({ length: count }, (_, week) => Array.from({ length: 7 }, (__, day) => keyOf(start + week * 7 + day)));
}

/** Colores por tipo, solo con tokens del sistema de diseño (con su par oscuro). */
const TONES = {
    tender: { dot: 'bg-cap-brand dark:bg-cap-brand-soft', chip: 'bg-cap-red-50 text-cap-red-800 dark:bg-cap-red-800/40 dark:text-cap-brand-soft', bar: 'border border-cap-red-100 bg-cap-red-50 text-cap-red-800 dark:border-cap-red-800 dark:bg-cap-red-800/40 dark:text-cap-brand-soft' },
    warning: { dot: 'bg-cap-status-warning dark:bg-cap-status-dark-warning', chip: 'bg-cap-status-warning-tint text-cap-status-warning-ink' },
    error: { dot: 'bg-cap-status-error dark:bg-cap-status-dark-error', chip: 'bg-cap-status-error-tint text-cap-status-error-ink' },
    info: { dot: 'bg-cap-status-info dark:bg-cap-status-dark-info', chip: 'bg-cap-status-info-tint text-cap-status-info-ink' },
    ok: { dot: 'bg-cap-status-ok dark:bg-cap-status-dark-ok', chip: 'bg-cap-status-ok-tint text-cap-status-ok-ink', bar: 'border border-cap-divider bg-cap-status-ok-tint text-cap-status-ok-ink' },
    taupe: { dot: 'bg-cap-taupe dark:bg-cap-taupe', chip: 'bg-cap-card-2 text-gray-800 dark:text-gray-100' },
    neutral: { dot: 'bg-cap-status-neutral dark:bg-cap-status-dark-neutral', chip: 'bg-cap-card-2 text-gray-700 dark:text-gray-200' },
    opportunity: { dot: 'bg-cap-red-400 dark:bg-cap-red-400', chip: 'bg-cap-card-2 text-gray-800 dark:text-gray-100' },
    manual: { dot: 'bg-cap-chrome dark:bg-gray-100', chip: 'bg-cap-card-2 text-gray-900 dark:text-gray-100', bar: 'border border-cap-divider bg-cap-card-2 text-gray-900 dark:text-gray-100' },
};

const TYPE_TONES = {
    Licitacion: 'tender',
    Aclaraciones: 'warning',
    Presentacion: 'error',
    Apertura: 'info',
    Oportunidad: 'opportunity',
    InicioContrato: 'ok',
    FinContrato: 'ok',
    Seguimiento: 'ok',
    Entrega: 'taupe',
    Documento: 'neutral',
    Manual: 'manual',
};

export function toneOf(type) {
    return TONES[TYPE_TONES[type] ?? 'manual'];
}

/** Leyenda de colores (los siete grupos del diseño más licitación y oportunidad). */
export const LEGEND = [
    { label: 'Licitación (inicio a fin)', tone: 'tender' },
    { label: 'Aclaraciones', tone: 'warning' },
    { label: 'Presentación', tone: 'error' },
    { label: 'Apertura', tone: 'info' },
    { label: 'Contrato y seguimiento', tone: 'ok' },
    { label: 'Entrega', tone: 'taupe' },
    { label: 'Documento', tone: 'neutral' },
    { label: 'Oportunidad', tone: 'opportunity' },
    { label: 'Manual', tone: 'manual' },
].map((item) => ({ ...item, dot: TONES[item.tone].dot }));

/** Ícono por tipo (sin `mdi-`). */
export const TYPE_ICONS = {
    Licitacion: 'gavel',
    Aclaraciones: 'comment-question-outline',
    Presentacion: 'file-send-outline',
    Apertura: 'email-open-outline',
    Oportunidad: 'view-column-outline',
    InicioContrato: 'file-sign',
    FinContrato: 'file-clock-outline',
    Entrega: 'truck-delivery-outline',
    Seguimiento: 'phone-outline',
    Documento: 'folder-account-outline',
    Manual: 'calendar-star',
};

/** ¿Se dibuja como barra de varios días? */
export function isBar(event) {
    return Boolean(event.ends_at) && event.ends_at.slice(0, 10) > event.starts_at.slice(0, 10);
}

/** Marcas de una barra: plazos de la misma licitación o entregas del mismo contrato. */
export function marksFor(bar, events) {
    if (bar.type === 'Licitacion' && bar.tender_id) {
        return events.filter((event) => event.tender_id === bar.tender_id && ['Aclaraciones', 'Apertura'].includes(event.type));
    }
    if (bar.type === 'InicioContrato' && bar.contract_id) {
        return events.filter((event) => event.contract_id === bar.contract_id && event.type === 'Entrega');
    }
    return [];
}

/**
 * Barras de una semana en carriles: [{ event, start (0-6), span, lane, continuesBefore, continuesAfter, marks: [col] }].
 */
export function weekBars(week, bars, events) {
    const first = dayNumber(week[0]);
    const last = dayNumber(week[6]);
    const segments = bars
        .map((event) => ({ event, from: dayNumber(event.starts_at), to: dayNumber(event.ends_at) }))
        .filter(({ from, to }) => from <= last && to >= first)
        .sort((a, b) => a.from - b.from || b.to - a.to);

    const lanes = [];
    return segments.map(({ event, from, to }) => {
        const start = Math.max(from, first) - first;
        const end = Math.min(to, last) - first;
        let lane = lanes.findIndex((busyUntil) => busyUntil < start);
        if (lane === -1) lane = lanes.length;
        lanes[lane] = end;
        const marks = marksFor(event, events)
            .map((mark) => dayNumber(mark.starts_at) - first)
            .filter((column) => column >= start && column <= end);
        return { event, start, span: end - start + 1, lane, continuesBefore: from < first, continuesAfter: to > last, marks };
    });
}
