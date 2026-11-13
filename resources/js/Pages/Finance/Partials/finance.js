// Utilidades de las pantallas financieras: filtros de lista contra el servidor, paleta de gráficas
// que sigue al tema y cifras compactas para ejes.
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { chartColors } from '../../../../design/chart-colors.js';
import { number } from '../../../lib/format.js';

/** Quita los filtros vacíos o por defecto para que la URL quede limpia. */
export function cleanQuery(values, defaults = {}) {
    return Object.fromEntries(
        Object.entries(values).filter(([key, value]) => value !== '' && value !== null && value !== undefined && value !== defaults[key]),
    );
}

/**
 * Filtros de una lista paginada en el servidor, con estados de carga y error.
 *   const list = useServerList('/cobros', props.filters, { only: ['records', 'stats', 'filters'], defaults: { filter: 'all' } });
 *   watch(() => list.filters.filter, () => list.reload());
 */
export function useServerList(url, initial, { only = [], defaults = {} } = {}) {
    const filters = reactive({ ...initial });
    const loading = ref(false);
    const error = ref('');

    function reload() {
        error.value = '';
        router.get(url, cleanQuery({ ...filters }, defaults), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            ...(only.length ? { only } : {}),
            onStart: () => (loading.value = true),
            onFinish: () => (loading.value = false),
            onHttpException: () => {
                error.value = 'El servidor no pudo cargar la lista. Vuelve a intentarlo en un momento.';
                return false;
            },
            onNetworkError: () => {
                error.value = 'No hay conexión con el servidor. Revisa tu red y vuelve a intentarlo.';
                return false;
            },
        });
    }

    function reset(values = defaults) {
        Object.keys(filters).forEach((key) => (filters[key] = values[key] ?? ''));
        reload();
    }

    return { filters, loading, error, reload, reset };
}

/** Paleta de `chartColors()` que se recalcula al cambiar entre claro y oscuro. */
export function useChartPalette() {
    const palette = ref(chartColors());
    let observer = null;

    onMounted(() => {
        palette.value = chartColors();
        observer = new MutationObserver(() => (palette.value = chartColors()));
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    });
    onBeforeUnmount(() => observer?.disconnect());

    return palette;
}

/** Ancho real de un elemento (para dibujar el SVG en píxeles y no deformar el texto). */
export function useWidth(element, fallback = 640) {
    const width = ref(fallback);
    let observer = null;

    onMounted(() => {
        if (!element.value) return;
        width.value = element.value.clientWidth || fallback;
        observer = new ResizeObserver(([entry]) => (width.value = Math.max(240, Math.floor(entry.contentRect.width))));
        observer.observe(element.value);
    });
    onBeforeUnmount(() => observer?.disconnect());

    return width;
}

/** Cifra de eje en lempiras: «L 1.25 M», «L 350 K», «L 900». */
export function compactMoney(value) {
    const amount = Number(value) || 0;
    const sign = amount < 0 ? '-' : '';
    const abs = Math.abs(amount);
    if (abs >= 1_000_000) return `${sign}L ${number(abs / 1_000_000, abs >= 10_000_000 ? 1 : 2)} M`;
    if (abs >= 1_000) return `${sign}L ${number(abs / 1_000, 0)} K`;
    return `${sign}L ${number(abs, 0)}`;
}

/** Máximo «redondo» del eje para que las líneas de la rejilla caigan en cifras limpias. */
export function niceMax(value) {
    const max = Math.max(0, Number(value) || 0);
    if (max === 0) return 1;
    const magnitude = 10 ** Math.floor(Math.log10(max));
    const step = [1, 2, 2.5, 5, 10].find((candidate) => candidate * magnitude >= max);
    return step * magnitude;
}

const MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

/** «2026-03» → «mar 2026» (o «mar» con short). */
export function monthLabel(key, short = false) {
    const [year, month] = String(key).split('-');
    const name = MONTHS[Number(month) - 1] ?? key;
    return short ? name : `${name} ${year}`;
}

/** Fechas «AAAA-MM-DD» de atajos de período, en hora de Honduras. */
export function presetRange(preset, today = new Date()) {
    const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Tegucigalpa', year: 'numeric', month: '2-digit', day: '2-digit' }).format(today).split('-').map(Number);
    const [year, month] = parts;
    const iso = (y, m, d) => new Date(Date.UTC(y, m - 1, d)).toISOString().slice(0, 10);
    const lastDay = (y, m) => new Date(Date.UTC(y, m, 0)).getUTCDate();
    const quarterStart = Math.floor((month - 1) / 3) * 3 + 1;

    switch (preset) {
        case 'month':
            return { from: iso(year, month, 1), to: iso(year, month, lastDay(year, month)) };
        case 'quarter':
            return { from: iso(year, quarterStart, 1), to: iso(year, quarterStart + 2, lastDay(year, quarterStart + 2)) };
        case 'ytd':
            return { from: iso(year, 1, 1), to: iso(year, month, parts[2]) };
        case 'year':
            return { from: iso(year, 1, 1), to: iso(year, 12, 31) };
        case 'last-year':
            return { from: iso(year - 1, 1, 1), to: iso(year - 1, 12, 31) };
        default:
            return null;
    }
}
