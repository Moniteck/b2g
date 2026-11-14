// Formato de cifras, moneda y fechas del sistema: es-HN y zona America/Tegucigalpa.
// Regla de la guía: celdas sin valor muestran «—», nunca «0», «null» ni vacío.

export const LOCALE = 'es-HN';
export const TIME_ZONE = 'America/Tegucigalpa';
export const EMPTY = '—';

const CURRENCY_SYMBOLS = { HNL: 'L', USD: 'US$' };
const MS_PER_DAY = 86_400_000;

const formatters = new Map();
function formatter(key, build) {
    if (!formatters.has(key)) formatters.set(key, build());
    return formatters.get(key);
}

function isBlank(value) {
    return value === null || value === undefined || value === '';
}

/** Símbolo de la moneda: «L» o «US$». */
export function currencySymbol(currency = 'HNL') {
    return CURRENCY_SYMBOLS[currency] ?? currency;
}

/** Número con miles y decimales fijos: number(1234.5, 2) → «1,234.50». */
export function number(value, decimals = 0) {
    if (isBlank(value) || !Number.isFinite(Number(value))) return EMPTY;
    return formatter(`n${decimals}`, () => new Intl.NumberFormat(LOCALE, { minimumFractionDigits: decimals, maximumFractionDigits: decimals })).format(Number(value));
}

/** Cantidad sin decimales innecesarios: quantity(12) → «12», quantity(12.5) → «12.5». */
export function quantity(value) {
    if (isBlank(value) || !Number.isFinite(Number(value))) return EMPTY;
    return formatter('q', () => new Intl.NumberFormat(LOCALE, { maximumFractionDigits: 2 })).format(Number(value));
}

/** Moneda: money(12345.67) → «L 12,345.67»; money(1234, 'USD') → «US$ 1,234.00»; negativos «-L 1,234.00».
 *  Con { symbol: false } devuelve solo la cifra (celdas cuya cabecera ya dice «Total (L)»). */
export function money(value, currency = 'HNL', { symbol = true } = {}) {
    if (isBlank(value) || !Number.isFinite(Number(value))) return EMPTY;
    const amount = Number(value);
    const figure = number(Math.abs(amount), 2);
    const sign = amount < 0 ? '-' : '';
    return symbol ? `${sign}${currencySymbol(currency)} ${figure}` : `${sign}${figure}`;
}

/** Porcentaje con un decimal y espacio: percent(12.5) → «12.5 %». */
export function percent(value, decimals = 1) {
    if (isBlank(value) || !Number.isFinite(Number(value))) return EMPTY;
    return `${number(value, decimals)} %`;
}

// Una fecha sin zona («2026-03-05» o «2026-03-05 14:05:00») es hora de pared de Honduras:
// se interpreta como UTC y se formatea en UTC para que ningún navegador la corra de día.
const WALL_TIME = /^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?(?:\.\d+)?)?$/;

function parse(value) {
    if (isBlank(value)) return null;
    if (value instanceof Date) return Number.isNaN(value.getTime()) ? null : { instant: value, zone: TIME_ZONE };
    if (typeof value === 'number') return { instant: new Date(value), zone: TIME_ZONE };
    const wall = WALL_TIME.exec(String(value).trim());
    if (wall) {
        const [, year, month, day, hour = '0', minute = '0', second = '0'] = wall;
        return { instant: new Date(Date.UTC(+year, +month - 1, +day, +hour, +minute, +second)), zone: 'UTC' };
    }
    const instant = new Date(value);
    return Number.isNaN(instant.getTime()) ? null : { instant, zone: TIME_ZONE };
}

function parts(parsed, options) {
    const list = formatter(`${parsed.zone}:${JSON.stringify(options)}`, () => new Intl.DateTimeFormat(LOCALE, { ...options, timeZone: parsed.zone })).formatToParts(parsed.instant);
    return Object.fromEntries(list.map((part) => [part.type, part.value]));
}

/** Fecha. 'short' (tablas y formularios) → «05/03/2026»; 'medium' (texto y tarjetas) → «5 mar 2026»;
 *  'long' → «jueves, 5 de marzo de 2026». */
export function date(value, style = 'short') {
    const parsed = parse(value);
    if (!parsed) return EMPTY;
    if (style === 'medium') {
        const p = parts(parsed, { day: 'numeric', month: 'short', year: 'numeric' });
        return `${p.day} ${p.month.replace('.', '')} ${p.year}`;
    }
    if (style === 'long') {
        return formatter(`long:${parsed.zone}`, () => new Intl.DateTimeFormat(LOCALE, { dateStyle: 'full', timeZone: parsed.zone })).format(parsed.instant);
    }
    const p = parts(parsed, { day: '2-digit', month: '2-digit', year: 'numeric' });
    return `${p.day}/${p.month}/${p.year}`;
}

/** Hora: «2:05 p. m.». */
export function time(value) {
    const parsed = parse(value);
    if (!parsed) return EMPTY;
    return formatter(`time:${parsed.zone}`, () => new Intl.DateTimeFormat(LOCALE, { hour: 'numeric', minute: '2-digit', hour12: true, timeZone: parsed.zone })).format(parsed.instant);
}

/** Fecha y hora: «05/03/2026, 2:05 p. m.» ('short') o «5 mar 2026, 2:05 p. m.» ('medium'). */
export function dateTime(value, style = 'short') {
    return parse(value) ? `${date(value, style)}, ${time(value)}` : EMPTY;
}

/** Rango con guion y espacios: «01/03/2026 – 31/03/2026». */
export function dateRange(from, to, style = 'short') {
    return `${date(from, style)} – ${date(to, style)}`;
}

// Día calendario (en Honduras) como número de días desde la época, para restar fechas sin horas.
function calendarDay(parsed) {
    const p = parts(parsed, { day: '2-digit', month: '2-digit', year: 'numeric' });
    return Math.round(Date.UTC(+p.year, +p.month - 1, +p.day) / MS_PER_DAY);
}

/** Días calendario desde hoy hasta la fecha: 0 hoy, 3 dentro de tres días, -2 hace dos. `null` sin fecha. */
export function daysUntil(value, now = new Date()) {
    const parsed = parse(value);
    if (!parsed) return null;
    return calendarDay(parsed) - calendarDay({ instant: now, zone: TIME_ZONE });
}

/** Plazo en días: «hoy», «mañana», «ayer», «en 3 días», «hace 2 días». */
export function relativeDays(value, now = new Date()) {
    const days = daysUntil(value, now);
    if (days === null) return EMPTY;
    if (days === 0) return 'hoy';
    if (days === 1) return 'mañana';
    if (days === -1) return 'ayer';
    return days > 0 ? `en ${days} días` : `hace ${Math.abs(days)} días`;
}

/** Tiempo transcurrido, solo para notificaciones: «ahora», «hace 3 min», «hace 2 h», «ayer», «05/03/2026». */
export function relativeTime(value, now = new Date()) {
    const parsed = parse(value);
    if (!parsed) return EMPTY;
    // La hora de pared se guardó como UTC; se compara contra la hora de pared actual de Honduras.
    const reference = parsed.zone === 'UTC' ? wallNow(now) : now.getTime();
    const minutes = Math.floor((reference - parsed.instant.getTime()) / 60_000);
    if (minutes < 1) return 'ahora';
    if (minutes < 60) return `hace ${minutes} min`;
    if (minutes < 1440) return `hace ${Math.floor(minutes / 60)} h`;
    const days = daysUntil(value, now);
    if (days === -1) return 'ayer';
    return date(value);
}

function wallNow(now) {
    const p = parts({ instant: now, zone: TIME_ZONE }, { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' });
    return Date.UTC(+p.year, +p.month - 1, +p.day, +p.hour, +p.minute, +p.second);
}

/** Tamaño de archivo: «820 KB», «2.4 MB». */
export function fileSize(bytes) {
    if (isBlank(bytes) || !Number.isFinite(Number(bytes))) return EMPTY;
    const size = Number(bytes);
    if (size < 1024) return `${size} B`;
    if (size < 1024 ** 2) return `${Math.round(size / 1024)} KB`;
    return `${quantity(Math.round((size / 1024 ** 2) * 10) / 10)} MB`;
}

/** Valor de celda: el texto tal cual o «—» si no hay. */
export function text(value) {
    return isBlank(value) ? EMPTY : String(value);
}
