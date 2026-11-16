<script setup>
// Lista de Licitaciones. Tres vistas alternables (tabla, kanban por etapa y calendario de plazos),
// la columna principal es el PRÓXIMO PLAZO (aclaraciones, recepción o apertura: el primero que
// viene) con su cuenta regresiva. Filtros en una hoja (lateral en escritorio, inferior en móvil)
// con chips de los activos. Cifras arriba con la definición única de BusinessMetrics.
// `?accion=importar` (PWA) e `?importar=1` (Dashboard) abren directamente la importación.
import { Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import CapAlert from '../../../design/vue/CapAlert.vue';
import CapKanban from '../../../design/vue/CapKanban.vue';
import CapSegmented from '../../../design/vue/CapSegmented.vue';
import CapSkeleton from '../../../design/vue/CapSkeleton.vue';
import { useToasts } from '../../../design/vue/composables/useToasts.js';
import AppButton from '../../Components/AppButton.vue';
import Avatar from '../../Components/Avatar.vue';
import DataTable from '../../Components/DataTable.vue';
import DateInput from '../../Components/DateInput.vue';
import DropdownMenu from '../../Components/DropdownMenu.vue';
import ErrorState from '../../Components/ErrorState.vue';
import FormField from '../../Components/FormField.vue';
import Pagination from '../../Components/Pagination.vue';
import SearchSelect from '../../Components/SearchSelect.vue';
import SelectInput from '../../Components/SelectInput.vue';
import Toggle from '../../Components/Toggle.vue';
import Toolbar from '../../Components/Toolbar.vue';
import { useConfirm } from '../../composables/useConfirm.js';
import AppLayout from '../../Layouts/AppLayout.vue';
import { date, dateTime, money, number, percent } from '../../lib/format.js';
import { visit } from '../../lib/http.js';
import { card, tableLink } from '../../lib/ui.js';
import FilterSheet from '../../Modules/Tenders/FilterSheet.vue';
import ImportCaptureModal from '../../Modules/Tenders/ImportCaptureModal.vue';
import TenderBoardCard from '../../Modules/Tenders/TenderBoardCard.vue';
import TenderCalendar from '../../Modules/Tenders/TenderCalendar.vue';
import TenderFormModal from '../../Modules/Tenders/TenderFormModal.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import { countdown, DEADLINE_ICONS, stageBadge } from '../../Modules/Tenders/tenders.js';

const props = defineProps({
    tenders: { type: Object, default: null },
    board: { type: Object, default: null },
    calendar: { type: Object, default: null },
    stats: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
    openImport: { type: Boolean, default: false },
    statuses: { type: Array, default: () => [] },
    modalities: { type: Array, default: () => [] },
    maxImageMb: { type: Number, default: 10 },
    aiView: { type: Boolean, default: false },
    can: { type: Object, default: () => ({}) },
});

const toasts = useToasts();
const confirm = useConfirm();

/** Estado de la lista tal como va en la URL. */
function fromProps(f = props.filters) {
    return {
        search: f.search ?? '',
        stages: [...(f.stages ?? [])],
        status: f.status ?? '',
        modality: f.modality ?? '',
        institution_id: f.institution_id ?? null,
        owner_id: f.owner_id ?? null,
        mine: Boolean(f.mine),
        from: f.from ?? '',
        to: f.to ?? '',
        expired: Boolean(f.expired) && !(f.stages ?? []).includes('vencida'),
        window: f.window ?? 'all',
        view: f.view ?? 'tabla',
        sort: f.sort ?? 'deadline',
        direction: f.direction ?? 'asc',
        month: f.month ?? '',
    };
}

const state = reactive(fromProps());
const loading = ref(false);
const loadError = ref('');
const formOpen = ref(false);
const importOpen = ref(false);
const sheetOpen = ref(false);
const draft = reactive(fromProps());
const institution = ref(props.options.institution ?? null);
const now = ref(Date.now());

const query = computed(() => {
    const q = {
        search: state.search || undefined,
        etapa: state.stages.length ? state.stages.join(',') : undefined,
        status: state.status || undefined,
        modality: state.modality || undefined,
        institution_id: state.institution_id || undefined,
        owner_id: state.owner_id || undefined,
        mias: state.mine ? 1 : undefined,
        desde: state.from || undefined,
        hasta: state.to || undefined,
        vencidas: state.expired ? 1 : undefined,
        window: state.window !== 'all' ? state.window : undefined,
        vista: state.view !== 'tabla' ? state.view : undefined,
        orden: state.sort !== 'deadline' ? state.sort : undefined,
        dir: state.direction !== 'asc' ? state.direction : undefined,
        mes: state.view === 'calendario' && state.month ? state.month : undefined,
    };
    return Object.fromEntries(Object.entries(q).filter(([, value]) => value !== undefined));
});

const exportQuery = computed(() => new URLSearchParams(Object.fromEntries(Object.entries(query.value).filter(([key]) => !['vista', 'mes'].includes(key)))).toString());

function reload() {
    loadError.value = '';
    router.get('/licitaciones', query.value, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['tenders', 'board', 'calendar', 'stats', 'filters', 'options'],
        onStart: () => (loading.value = true),
        onFinish: () => (loading.value = false),
        onError: () => (loadError.value = 'No se pudo cargar la lista de licitaciones.'),
    });
}

watch(() => [state.search, state.window, state.view, state.sort, state.direction, state.month], reload);

// ── Cifras de cabecera ──────────────────────────────────────────────────────────────────────────
const figures = computed(() => [
    { key: 'open', label: 'Vigentes', value: number(props.stats.open ?? 0), tone: 'info', window: 'open', hint: 'Activas o en preparación con plazo abierto' },
    { key: 'week', label: 'Cierran esta semana', value: number(props.stats.week ?? 0), tone: props.stats.week ? 'warning' : 'neutral', window: 'week', hint: 'Recepción de ofertas en 7 días' },
    { key: 'clarifications', label: 'Aclaraciones en 72 h', value: number(props.stats.clarifications ?? 0), tone: props.stats.clarifications ? 'error' : 'neutral', window: 'clarifications', hint: 'Último plazo para preguntar' },
    { key: 'presented', label: 'Presentadas', value: number(props.stats.presented ?? 0), tone: 'info', window: 'presented', hint: props.stats.awaiting ? `${props.stats.awaiting} esperando resultado` : 'Oferta entregada, sin resultado' },
    { key: 'rate', label: 'Tasa de adjudicación', value: props.stats.rate === null || props.stats.rate === undefined ? '—' : percent(props.stats.rate), tone: 'ok', window: null, hint: props.stats.decided ? `${props.stats.won} ganadas de ${props.stats.decided} decididas` : 'Aún no hay cotizaciones decididas' },
]);

const DOT = {
    ok: 'bg-cap-status-ok dark:bg-cap-status-dark-ok',
    info: 'bg-cap-status-info dark:bg-cap-status-dark-info',
    warning: 'bg-cap-status-warning dark:bg-cap-status-dark-warning',
    error: 'bg-cap-status-error dark:bg-cap-status-dark-error',
    neutral: 'bg-gray-400 dark:bg-gray-500',
};

function toggleWindow(window) {
    if (!window) return;
    state.window = state.window === window ? 'all' : window;
}

// ── Filtros (hoja) y chips ──────────────────────────────────────────────────────────────────────
const stageOptions = computed(() => props.options.stages ?? []);
const stageLabel = (key) => stageOptions.value.find((stage) => stage.key === key)?.label ?? key;
const ownerOptions = computed(() => (props.options.owners ?? []).map((owner) => ({ value: owner.id, label: owner.name })));
const modalityOptions = computed(() => (props.options.modalities ?? props.modalities).map((value) => ({ value, label: value })));
const sortOptions = [
    { value: 'deadline:asc', label: 'Próximo plazo (lo más cercano primero)' },
    { value: 'deadline:desc', label: 'Próximo plazo (lo más lejano primero)' },
    { value: 'created:desc', label: 'Más recientes' },
    { value: 'amount:desc', label: 'Monto (mayor a menor)' },
    { value: 'reference:asc', label: 'Expediente (A → Z)' },
    { value: 'stage:asc', label: 'Etapa del flujo' },
];
const sortChoice = computed({
    get: () => `${state.sort}:${state.direction}`,
    set: (value) => {
        const [sort, direction] = value.split(':');
        Object.assign(state, { sort, direction });
    },
});

function openSheet() {
    Object.assign(draft, fromProps({ ...props.filters, ...stateAsFilters() }));
    draft.stages = [...state.stages];
    sheetOpen.value = true;
}

function stateAsFilters() {
    return { ...state, stages: [...state.stages], expired: state.expired };
}

function toggleDraftStage(key) {
    draft.stages = draft.stages.includes(key) ? draft.stages.filter((stage) => stage !== key) : [...draft.stages, key];
}

function applySheet() {
    Object.assign(state, {
        stages: [...draft.stages],
        modality: draft.modality,
        institution_id: draft.institution_id,
        owner_id: draft.owner_id,
        mine: draft.mine,
        from: draft.from,
        to: draft.to,
        expired: draft.expired,
    });
    sheetOpen.value = false;
    reload();
}

function clearSheet() {
    Object.assign(draft, { stages: [], modality: '', institution_id: null, owner_id: null, mine: false, from: '', to: '', expired: false });
    institution.value = null;
}

const chips = computed(() => {
    const list = [];
    state.stages.forEach((key) => list.push({ key: `stage-${key}`, label: stageLabel(key), remove: () => (state.stages = state.stages.filter((stage) => stage !== key)) }));
    if (state.status) list.push({ key: 'status', label: `Estado: ${state.status}`, remove: () => (state.status = '') });
    if (state.modality) list.push({ key: 'modality', label: state.modality, remove: () => (state.modality = '') });
    if (state.institution_id) list.push({ key: 'institution', label: institution.value?.acronym || institution.value?.name || 'Institución', remove: () => ((state.institution_id = null), (institution.value = null)) });
    if (state.owner_id) list.push({ key: 'owner', label: `Responsable: ${ownerOptions.value.find((o) => o.value === state.owner_id)?.label ?? '—'}`, remove: () => (state.owner_id = null) });
    if (state.mine) list.push({ key: 'mine', label: 'Solo mías', remove: () => (state.mine = false) });
    if (state.from || state.to) list.push({ key: 'dates', label: `Recepción ${state.from ? date(state.from) : '…'} – ${state.to ? date(state.to) : '…'}`, remove: () => ((state.from = ''), (state.to = '')) });
    if (state.expired) list.push({ key: 'expired', label: 'Incluye vencidas', remove: () => (state.expired = false) });
    if (state.window !== 'all') list.push({ key: 'window', label: figures.value.find((f) => f.window === state.window)?.label ?? state.window, remove: () => (state.window = 'all') });
    return list;
});

function removeChip(chip) {
    chip.remove();
    reload();
}

function clearAll() {
    Object.assign(state, { search: '', stages: [], status: '', modality: '', institution_id: null, owner_id: null, mine: false, from: '', to: '', expired: false, window: 'all' });
    institution.value = null;
    reload();
}

const sheetCount = computed(() => chips.value.filter((chip) => chip.key !== 'window').length);
const filtered = computed(() => Boolean(state.search) || chips.value.length > 0);

// ── Vistas ──────────────────────────────────────────────────────────────────────────────────────
const views = [
    { label: 'Tabla', value: 'tabla' },
    { label: 'Kanban', value: 'kanban' },
    { label: 'Calendario', value: 'calendario' },
];

// Entre 1024 y 1279 px no caben todas: responsable y resultado se ven en la ficha y el kanban.
const wideQuery = typeof window !== 'undefined' ? window.matchMedia('(min-width: 1280px)') : null;
const wide = ref(wideQuery?.matches ?? true);
const onWide = (event) => (wide.value = event.matches);
onMounted(() => wideQuery?.addEventListener('change', onWide));
onBeforeUnmount(() => wideQuery?.removeEventListener('change', onWide));
const columns = computed(() => (wide.value ? allColumns : allColumns.filter((column) => !['owner', 'result'].includes(column.key))));
const allColumns = [
    { key: 'deadline', label: 'Próximo plazo', sortable: true, width: 'w-48', wrap: true },
    { key: 'reference', label: 'Expediente', primary: true, sortable: true },
    { key: 'institution', label: 'Institución', sortable: true, width: 'w-28' },
    { key: 'stage', label: 'Etapa', sortable: true, width: 'w-44' },
    { key: 'owner', label: 'Responsable', sortable: true, width: 'w-40' },
    { key: 'amount', label: 'Monto (L)', align: 'right', sortable: true, width: 'w-36 whitespace-nowrap' },
    { key: 'result', label: 'Resultado', width: 'w-32' },
];

const sort = computed({
    get: () => ({ key: state.sort, direction: state.direction }),
    set: (value) => {
        state.sort = value?.key ?? 'deadline';
        state.direction = value?.direction ?? 'asc';
    },
});

function left(row) {
    return row.next_deadline ? countdown(row.next_deadline.at, now.value) : null;
}

const URGENCY_TEXT = {
    error: 'text-cap-status-error-ink',
    warning: 'text-cap-status-warning-ink',
    neutral: 'text-gray-900 dark:text-gray-100',
};

function resultOf(row) {
    if (row.stage?.key === 'adjudicada') return { status: 'ok', label: row.result || 'Ganada' };
    if (row.stage?.key === 'no_adjudicada') return { status: 'error', label: row.result || 'Perdida' };
    if (row.stage?.awaiting_result) return { status: 'warning', label: 'Pendiente' };
    return null;
}

function actionsOf(row) {
    return [
        { label: 'Ver ficha', icon: 'eye-outline', href: `/licitaciones/${row.id}` },
        ...(props.can.update ? [{ label: 'Editar', icon: 'pencil-outline', href: `/licitaciones/${row.id}?editar=1` }] : []),
        ...(props.can.delete ? [{ label: 'Eliminar', icon: 'trash-can-outline', danger: true, separator: true, onSelect: () => remove(row) }] : []),
    ];
}

function remove(row) {
    confirm({
        verb: 'Eliminar',
        object: `la licitación ${row.reference}`,
        noun: 'licitación',
        message: 'Se borran sus análisis y su matriz documental. La oportunidad y las cotizaciones se conservan sin enlace. No se puede deshacer.',
        action: () => visit('delete', `/licitaciones/${row.id}`),
    });
}

// ── Kanban ──────────────────────────────────────────────────────────────────────────────────────
const boardCards = ref([]);
watch(
    () => props.board,
    (board) => {
        boardCards.value = (board?.cards ?? []).map((tender) => ({ id: tender.id, column: tender.stage.key, title: tender.title, code: tender.reference }));
    },
    { immediate: true },
);
const boardColumns = computed(() =>
    (props.board?.columns ?? []).map((column) => ({
        id: column.id,
        title: column.total > boardCards.value.filter((c) => c.column === column.id).length ? `${column.title} (${column.total})` : column.title,
        tone: column.tone,
        emptyTitle: boardCards.value.some((c) => c.column === column.id) ? undefined : 'Sin licitaciones',
        emptyHint: boardCards.value.some((c) => c.column === column.id) ? undefined : column.id === 'por_analizar' ? 'Importa una captura para empezar.' : undefined,
    })),
);
const findTender = (id) => props.board?.cards.find((tender) => tender.id === Number(id));

async function onBoardMove(detail) {
    const tender = findTender(detail.cardId);
    if (!tender || detail.from === detail.to) return;
    if (!props.can.update) {
        toasts.show({ status: 'warning', title: 'Solo lectura', detail: 'Tu rol permite ver el tablero, no mover licitaciones.' });
        return;
    }
    if (['adjudicada', 'no_adjudicada'].includes(detail.to)) {
        toasts.show({ status: 'info', title: 'Registra el resultado en la ficha', detail: `Abre ${tender.reference} y usa «Registrar resultado»: así la cotización y el pipeline quedan iguales.` });
        return;
    }
    const previous = boardCards.value;
    boardCards.value = boardCards.value.map((c) => (c.id === tender.id ? { ...c, column: detail.to } : c));
    try {
        await visit('patch', `/licitaciones/${tender.id}/etapa`, { stage: detail.to }, { only: ['board', 'stats', 'flash', 'errors'], preserveState: true });
    } catch (errors) {
        boardCards.value = previous;
        toasts.show({ status: 'error', title: 'No se movió la licitación', detail: errors?.stage ?? 'Inténtalo de nuevo.' });
    }
}

// ── Importar ────────────────────────────────────────────────────────────────────────────────────
function openImporter() {
    if (props.can.create) importOpen.value = true;
}

let clock = null;
onMounted(() => {
    clock = setInterval(() => (now.value = Date.now()), 60_000);
    if (props.openImport) {
        openImporter();
        // El atajo no se repite al recargar ni al volver atrás.
        const url = new URL(window.location.href);
        ['accion', 'importar'].forEach((key) => url.searchParams.delete(key));
        window.history.replaceState(window.history.state, '', `${url.pathname}${url.search}`);
    }
});

const stopListener = router.on('exception', () => (loadError.value = 'No se pudo cargar la lista de licitaciones.'));
onBeforeUnmount(() => {
    stopListener();
    clearInterval(clock);
});

const exportMenu = computed(() => [
    { label: 'Descargar PDF', icon: 'file-pdf-box', href: `/licitaciones/export/pdf?${exportQuery.value}`, external: true },
    { label: 'Descargar Excel', icon: 'file-excel-outline', href: `/licitaciones/export/xlsx?${exportQuery.value}`, external: true },
    ...(props.can.create ? [{ label: 'Nueva licitación a mano', icon: 'plus', separator: true, onSelect: () => (formOpen.value = true) }] : []),
]);
const total = computed(() => props.tenders?.total ?? props.board?.columns?.reduce((sum, column) => sum + column.total, 0) ?? props.calendar?.events?.length ?? 0);
</script>

<template>
    <AppLayout title="Licitaciones" :fab-label="can.create ? 'Importar captura' : ''" @fab="openImporter">
        <template #topbar-actions>
            <DropdownMenu label="Exportar y más acciones" icon="dots-horizontal" :items="exportMenu" width="w-60" />
        </template>
        <div class="hidden flex-wrap items-center justify-between gap-3 lg:flex">
            <p class="min-w-0 text-sm text-cap-text-soft">Procesos de compra pública de la empresa activa, ordenados por el plazo que viene primero.</p>
            <div class="flex items-center gap-2">
                <DropdownMenu label="Exportar y más acciones" icon="dots-horizontal" :items="exportMenu" width="w-60" />
                <span v-if="can.create" class="hidden lg:contents">
                    <AppButton icon="plus" @click="formOpen = true">Nueva licitación</AppButton>
                    <!-- Con la lista vacía, el primario es el del estado vacío. -->
                    <AppButton v-if="!(state.view === 'tabla' && tenders?.total === 0 && !filtered)" variant="primary" icon="text-recognition" data-open-import @click="openImporter">Importar captura</AppButton>
                </span>
            </div>
        </div>

        <CapAlert v-if="aiView" status="info" title="Recomendaciones con IA" detail="Abre una licitación vigente y usa «Análisis del pliego» para analizar su pliego y pedir una recomendación." />

        <!-- Cifras: mosaico de 5 en escritorio, fila deslizable compacta en móvil. Cada una filtra la lista. -->
        <div class="-mx-1 flex snap-x gap-3 overflow-x-auto px-1 pb-1 md:grid md:grid-cols-5 md:overflow-visible" data-tender-figures>
            <component
                :is="figure.window ? 'button' : 'div'"
                v-for="figure in figures"
                :key="figure.key"
                :type="figure.window ? 'button' : undefined"
                class="w-40 shrink-0 snap-start rounded-cap-card border bg-cap-card p-4 text-left transition-[transform,border-color] duration-cap-fast ease-cap md:w-auto md:min-w-0"
                :class="[figure.window ? 'hover:-translate-y-0.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome focus-visible:ring-offset-2 focus-visible:ring-offset-cap-page dark:focus-visible:ring-gray-100' : '', state.window === figure.window && figure.window ? 'border-cap-chrome dark:border-gray-100' : 'border-cap-divider']"
                :aria-pressed="figure.window ? String(state.window === figure.window) : undefined"
                :data-figure="figure.key"
                @click="toggleWindow(figure.window)"
            >
                <p class="font-display text-2xl font-semibold tabular-nums text-gray-900 md:text-3xl dark:text-gray-100">{{ figure.value }}</p>
                <p class="mt-1 flex items-center gap-1.5 text-xs font-medium text-gray-700 dark:text-gray-300"><span class="h-1.5 w-1.5 shrink-0 rounded-full" :class="DOT[figure.tone]" aria-hidden="true" />{{ figure.label }}</p>
                <p class="mt-0.5 truncate text-xs text-cap-text-soft" :title="figure.hint">{{ figure.hint }}</p>
            </component>
        </div>

        <div class="flex flex-col gap-3">
            <Toolbar v-model:search="state.search" search-placeholder="Expediente, objeto o institución" search-label="Buscar licitaciones">
                <template #filters>
                    <div class="max-w-full overflow-x-auto">
                        <CapSegmented v-model="state.view" :items="views" label="Vista de la lista" data-view-switch />
                    </div>
                </template>
                <template #actions>
                    <AppButton icon="filter-variant" :aria-label="sheetCount ? `Filtros, ${sheetCount} activos` : 'Filtros'" data-open-filters @click="openSheet">
                        Filtros<span v-if="sheetCount" class="ml-1 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-cap-chrome px-1.5 text-xs font-semibold text-white dark:bg-gray-100 dark:text-gray-900">{{ sheetCount }}</span>
                    </AppButton>
                </template>
            </Toolbar>

            <div v-if="chips.length" class="flex flex-wrap items-center gap-2" aria-label="Filtros activos" data-filter-chips>
                <button v-for="chip in chips" :key="chip.key" type="button" class="inline-flex h-8 items-center gap-1 rounded-full border border-cap-divider bg-cap-card pl-3 pr-2 text-xs font-medium text-gray-800 transition-colors duration-cap-fast ease-cap hover:bg-cap-card-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome dark:text-gray-100 dark:focus-visible:ring-gray-100" :aria-label="`Quitar filtro ${chip.label}`" @click="removeChip(chip)">
                    {{ chip.label }}<i class="mdi mdi-close text-base leading-none text-cap-text-soft" aria-hidden="true" />
                </button>
                <button type="button" class="h-8 px-2 text-xs text-cap-text-soft underline decoration-gray-400 underline-offset-2 hover:decoration-current" @click="clearAll">Limpiar todo</button>
            </div>
        </div>

        <!-- Tabla (tarjetas en móvil) -->
        <div v-if="state.view === 'tabla'" data-view="tabla">
            <DataTable
                v-if="tenders"
                v-model:sort="sort"
                server-sort
                :columns="columns"
                :rows="tenders.data"
                :loading="loading"
                :error="loadError"
                :filtered="filtered"
                caption="Licitaciones"
                clickable
                empty-icon="gavel"
                empty-title="Aún no hay licitaciones"
                empty-description="Importa la captura de la ficha de HonduCompras: leemos los datos y tú los revisas."
                @row-click="(row) => router.visit(`/licitaciones/${row.id}`)"
                @retry="reload"
                @clear-filters="clearAll"
            >
                <template #cell-deadline="{ row }">
                    <span v-if="left(row)" class="flex flex-col" :data-urgency="left(row).status">
                        <span class="inline-flex items-center gap-1 text-sm font-semibold" :class="URGENCY_TEXT[left(row).status]">
                            <i class="mdi text-base leading-none" :class="`mdi-${DEADLINE_ICONS[row.next_deadline.key]}`" aria-hidden="true" />{{ left(row).label }}
                        </span>
                        <span class="text-xs text-cap-text-soft xl:whitespace-nowrap">{{ row.next_deadline.label }} · {{ dateTime(row.next_deadline.at) }}</span>
                    </span>
                    <span v-else class="text-xs text-cap-text-soft">{{ row.presentation_deadline ? `Recepción ${date(row.presentation_deadline)}` : 'Sin fecha' }}</span>
                </template>
                <template #cell-reference="{ row }">
                    <span class="flex min-w-0 flex-col">
                        <Link :href="`/licitaciones/${row.id}`" :class="tableLink" class="truncate tabular-nums" :title="row.reference" @click.stop>{{ row.reference }}</Link>
                        <span class="max-w-md truncate text-xs text-cap-text-soft" :title="row.title">{{ row.title }}</span>
                    </span>
                </template>
                <template #cell-institution="{ row }">
                    <span class="block truncate" :title="row.institution?.name">{{ row.institution?.acronym || row.institution?.name || '—' }}</span>
                </template>
                <template #cell-stage="{ row }">
                    <StatusBadge v-bind="stageBadge(row.stage)" class="whitespace-nowrap" />
                </template>
                <template #cell-owner="{ row }">
                    <span v-if="row.owner" class="flex min-w-0 items-center gap-2"><Avatar :name="row.owner.name" size="sm" /><span class="truncate">{{ row.owner.name }}</span></span>
                    <span v-else class="text-cap-text-soft">—</span>
                </template>
                <template #cell-amount="{ row }">
                    <span class="tabular-nums">{{ Number(row.estimated_amount) > 0 ? money(row.estimated_amount, 'HNL', { symbol: false }) : '—' }}</span>
                </template>
                <template #cell-result="{ row }">
                    <StatusBadge v-if="resultOf(row)" v-bind="resultOf(row)" />
                    <span v-else class="text-cap-text-soft">—</span>
                </template>
                <template #card="{ row }">
                    <div class="flex flex-col gap-3" data-tender-card>
                        <div class="flex items-start justify-between gap-2">
                            <span class="min-w-0">
                                <span class="block break-all text-sm font-semibold text-gray-900 dark:text-gray-100">{{ row.reference }}</span>
                                <span class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-cap-text-soft">
                                    <StatusBadge v-bind="stageBadge(row.stage)" class="whitespace-nowrap" />
                                    <span class="truncate">{{ row.institution?.acronym || row.institution?.name || 'Sin institución' }}<template v-if="Number(row.estimated_amount) > 0"> · {{ money(row.estimated_amount) }}</template></span>
                                </span>
                            </span>
                            <span class="-mr-2 -mt-2 shrink-0" @click.stop><DropdownMenu :label="`Acciones de ${row.reference}`" :items="actionsOf(row)" /></span>
                        </div>
                        <p class="line-clamp-2 text-sm text-gray-800 dark:text-gray-200">{{ row.title }}</p>
                        <div v-if="left(row)" class="flex items-center gap-3 rounded-cap-control bg-cap-card-2 p-3" :data-urgency="left(row).status">
                            <i class="mdi text-2xl leading-none" :class="[`mdi-${DEADLINE_ICONS[row.next_deadline.key]}`, URGENCY_TEXT[left(row).status]]" aria-hidden="true" />
                            <span class="min-w-0 flex-1">
                                <span class="block font-display text-lg font-semibold leading-tight" :class="URGENCY_TEXT[left(row).status]">{{ left(row).label }}</span>
                                <span class="block truncate text-xs text-cap-text-soft">{{ row.next_deadline.label }} · {{ dateTime(row.next_deadline.at) }}</span>
                            </span>
                        </div>
                        <p v-else class="text-xs text-cap-text-soft">{{ row.stage?.awaiting_result ? 'Apertura pasada: falta registrar el resultado.' : row.presentation_deadline ? `Recepción de ofertas: ${dateTime(row.presentation_deadline)}` : 'Sin fechas registradas' }}</p>
                    </div>
                </template>
                <template #actions="{ row }">
                    <DropdownMenu :label="`Acciones de ${row.reference}`" :items="actionsOf(row)" />
                </template>
                <template v-if="can.create" #empty-action>
                    <AppButton variant="primary" icon="text-recognition" @click="openImporter">Importar captura</AppButton>
                </template>
            </DataTable>
            <CapSkeleton v-else variant="table" :rows="6" label="Cargando licitaciones" />
            <Pagination v-if="tenders" :paginator="tenders" :only="['tenders', 'filters']" />
            <p v-if="stats.expired && !state.expired && !state.stages.length" class="mt-3 text-sm text-cap-text-soft" data-hidden-expired>
                {{ stats.expired === 1 ? '1 licitación vencida sin oferta no se muestra.' : `${number(stats.expired)} licitaciones vencidas sin oferta no se muestran.` }}
                <button type="button" class="underline decoration-gray-400 underline-offset-2 hover:decoration-current" @click="(state.expired = true), reload()">Mostrarlas</button>
            </p>
        </div>

        <!-- Kanban por etapa -->
        <div v-else-if="state.view === 'kanban'" class="tender-board" :data-readonly="can.update ? undefined : 'true'" data-view="kanban">
            <ErrorState v-if="loadError" :title="loadError" @retry="reload" />
            <CapSkeleton v-else-if="!board" variant="kanban" label="Cargando el tablero" />
            <template v-else>
                <CapKanban :columns="boardColumns" :cards="boardCards" label="Licitaciones por etapa" picker-label="Etapa" @move="onBoardMove">
                    <template #card="{ card: item }">
                        <TenderBoardCard v-if="findTender(item.id)" :tender="findTender(item.id)" :menu="actionsOf(findTender(item.id))" :now="now" />
                    </template>
                </CapKanban>
                <p class="mt-2 text-xs text-cap-text-soft">
                    Arrastra una tarjeta para cambiar su etapa. El resultado (adjudicada o no) se registra en la ficha.
                    <button v-if="!state.expired" type="button" class="underline decoration-gray-400 underline-offset-2 hover:decoration-current" @click="(state.expired = true), reload()">Mostrar las vencidas sin oferta</button>
                </p>
            </template>
        </div>

        <!-- Calendario de plazos -->
        <div v-else data-view="calendario">
            <ErrorState v-if="loadError" :title="loadError" @retry="reload" />
            <TenderCalendar v-else :month="calendar?.month ?? state.month ?? ''" :events="calendar?.events ?? []" :loading="loading || !calendar" @month="(month) => (state.month = month)" />
        </div>

        <p v-if="state.view !== 'tabla' && total" class="sr-only" aria-live="polite">{{ total }} licitaciones</p>

        <FilterSheet v-model:open="sheetOpen" title="Filtros" description="Se aplican a la tabla, al kanban, al calendario y a los exportes.">
            <div class="flex flex-col gap-6">
                <fieldset>
                    <legend class="mb-2 text-xs font-semibold text-gray-700 dark:text-gray-300">Etapa del flujo</legend>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="stage in stageOptions"
                            :key="stage.key"
                            type="button"
                            class="inline-flex h-9 items-center gap-1.5 rounded-full border px-3 text-xs font-medium transition-colors duration-cap-fast ease-cap focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome dark:focus-visible:ring-gray-100"
                            :class="draft.stages.includes(stage.key) ? 'border-cap-chrome bg-cap-chrome text-white dark:border-gray-100 dark:bg-gray-100 dark:text-gray-900' : 'border-cap-divider bg-cap-card text-gray-800 hover:bg-cap-card-2 dark:text-gray-100'"
                            :aria-pressed="String(draft.stages.includes(stage.key))"
                            @click="toggleDraftStage(stage.key)"
                        >
                            <i class="mdi text-base leading-none" :class="`mdi-${stage.icon}`" aria-hidden="true" />{{ stage.label }}
                        </button>
                    </div>
                </fieldset>
                <FormField label="Modalidad">
                    <SelectInput v-model="draft.modality" :options="modalityOptions" placeholder="Todas las modalidades" />
                </FormField>
                <FormField label="Institución">
                    <SearchSelect v-model="draft.institution_id" url="/instituciones/buscar" option-value="id" option-label="name" :selected="institution" placeholder="Nombre o siglas…" @select="(option) => (institution = option)" />
                </FormField>
                <FormField label="Responsable">
                    <SelectInput v-model="draft.owner_id" :options="ownerOptions" placeholder="Cualquier responsable" :disabled="draft.mine" />
                </FormField>
                <Toggle v-model="draft.mine" label="Solo las mías" description="Licitaciones de las que eres responsable." />
                <fieldset>
                    <legend class="mb-2 text-xs font-semibold text-gray-700 dark:text-gray-300">Recepción de ofertas</legend>
                    <div class="grid grid-cols-2 gap-3">
                        <FormField label="Desde"><DateInput v-model="draft.from" :max="draft.to || ''" /></FormField>
                        <FormField label="Hasta"><DateInput v-model="draft.to" :min="draft.from || ''" /></FormField>
                    </div>
                </fieldset>
                <Toggle v-model="draft.expired" label="Incluir las vencidas sin oferta" description="Por defecto se ocultan las que pasaron la recepción de ofertas sin presentar." />
                <FormField label="Ordenar por" class="lg:hidden">
                    <SelectInput v-model="sortChoice" :options="sortOptions" />
                </FormField>
            </div>
            <template #footer>
                <AppButton icon="filter-remove-outline" @click="clearSheet">Limpiar</AppButton>
                <AppButton variant="primary" icon="check" data-apply-filters @click="applySheet">Ver resultados</AppButton>
            </template>
        </FilterSheet>

        <TenderFormModal v-model:open="formOpen" :tender="null" :statuses="statuses" :modalities="modalities" />
        <ImportCaptureModal v-model:open="importOpen" :max-size-mb="maxImageMb" />
    </AppLayout>
</template>

<style scoped>
/* El tablero no crea tarjetas desde la columna ni tiene menú de columna (README de Components). */
.tender-board :deep(.cap-kanban__action) {
    display: none;
}
/* B-24: en móvil el selector de etapas se desplaza; el borde desvanecido indica que hay más. */
@media (max-width: 1023px) {
    .tender-board :deep(.cap-kanban__picker) {
        mask-image: linear-gradient(to right, black calc(100% - 2.5rem), transparent);
        padding-right: 2.5rem;
    }
}
</style>
