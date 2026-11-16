// Utilidades de fecha de los campos `DateInput` / `DateTimeInput` (sin librerías). Trabajan con
// cadenas «AAAA-MM-DD» en el calendario civil (sin husos: hoy se calcula en America/Tegucigalpa).
import { TIME_ZONE } from '../lib/format.js';

const pad = (value) => String(value).padStart(2, '0');

export const isoOf = (year, month, day) => `${String(year).padStart(4, '0')}-${pad(month)}-${pad(day)}`;

export function fromIso(iso) {
    const [year, month, day] = String(iso).slice(0, 10).split('-').map(Number);
    return { year, month, day };
}

export function isValidIso(iso) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(String(iso ?? ''))) return false;
    const { year, month, day } = fromIso(iso);
    const date = new Date(Date.UTC(year, month - 1, day));
    return date.getUTCFullYear() === year && date.getUTCMonth() === month - 1 && date.getUTCDate() === day;
}

export function addDays(iso, days) {
    const { year, month, day } = fromIso(iso);
    const date = new Date(Date.UTC(year, month - 1, day + days));
    return isoOf(date.getUTCFullYear(), date.getUTCMonth() + 1, date.getUTCDate());
}

export function addMonths(iso, months) {
    const { year, month, day } = fromIso(iso);
    const target = new Date(Date.UTC(year, month - 1 + months, 1));
    const last = new Date(Date.UTC(target.getUTCFullYear(), target.getUTCMonth() + 1, 0)).getUTCDate();
    return isoOf(target.getUTCFullYear(), target.getUTCMonth() + 1, Math.min(day, last));
}

export function todayIso() {
    const parts = new Intl.DateTimeFormat('en-CA', { timeZone: TIME_ZONE, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
    return parts;
}

/** «AAAA-MM-DD» → «dd/mm/aaaa». */
export function toDisplay(iso) {
    if (!isValidIso(iso)) return '';
    const { year, month, day } = fromIso(iso);
    return `${pad(day)}/${pad(month)}/${year}`;
}

/** Máscara mientras se escribe: solo dígitos y las barras en su lugar («05032026» → «05/03/2026»). */
export function maskDate(text) {
    const digits = String(text).replace(/\D/g, '').slice(0, 8);
    if (digits.length <= 2) return digits;
    if (digits.length <= 4) return `${digits.slice(0, 2)}/${digits.slice(2)}`;
    return `${digits.slice(0, 2)}/${digits.slice(2, 4)}/${digits.slice(4)}`;
}

/** «dd/mm/aaaa», «d/m/aa», «hoy», «mañana» → «AAAA-MM-DD», o null si no es una fecha. */
export function parseDisplay(text) {
    const value = String(text ?? '').trim().toLowerCase();
    if (!value) return null;
    if (value === 'hoy') return todayIso();
    if (value === 'mañana' || value === 'manana') return addDays(todayIso(), 1);
    if (value === 'ayer') return addDays(todayIso(), -1);
    const match = value.match(/^(\d{1,2})[/.-](\d{1,2})[/.-](\d{2}|\d{4})$/) ?? value.match(/^(\d{2})(\d{2})(\d{4})$/);
    if (!match) return null;
    let year = Number(match[3]);
    if (match[3].length === 2) year += 2000;
    const iso = isoOf(year, Number(match[2]), Number(match[1]));
    return isValidIso(iso) ? iso : null;
}

/** Máscara de hora 24 h («1430» → «14:30»). */
export function maskTime(text) {
    const digits = String(text).replace(/\D/g, '').slice(0, 4);
    return digits.length <= 2 ? digits : `${digits.slice(0, 2)}:${digits.slice(2)}`;
}

/** «14:30», «2:30 p. m.», «9 am», «0930» → «HH:mm», o null. */
export function parseTime(text) {
    const value = String(text ?? '').trim().toLowerCase().replace(/\s+/g, ' ');
    if (!value) return null;
    const meridiem = /p\.?\s?m\.?$/.test(value) ? 'pm' : /a\.?\s?m\.?$/.test(value) ? 'am' : null;
    const clean = value.replace(/[ap]\.?\s?m\.?$/, '').trim();
    const match = clean.match(/^(\d{1,2})(?::?(\d{2}))?$/);
    if (!match) return null;
    let hours = Number(match[1]);
    const minutes = Number(match[2] ?? 0);
    if (meridiem) {
        if (hours < 1 || hours > 12) return null;
        if (meridiem === 'pm' && hours !== 12) hours += 12;
        if (meridiem === 'am' && hours === 12) hours = 0;
    }
    if (hours > 23 || minutes > 59) return null;
    return `${pad(hours)}:${pad(minutes)}`;
}

/** «HH:mm» → «2:30 p. m.» (ayuda bajo el campo). */
export function meridiemLabel(time) {
    if (!/^\d{2}:\d{2}$/.test(String(time ?? ''))) return '';
    const [hours, minutes] = time.split(':').map(Number);
    const suffix = hours < 12 ? 'a. m.' : 'p. m.';
    return `${hours % 12 || 12}:${pad(minutes)} ${suffix}`;
}
