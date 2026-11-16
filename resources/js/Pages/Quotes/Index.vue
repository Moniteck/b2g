<script setup>
// Cotizaciones de la empresa activa: cifras, buscador, segmentado por estado o resultado, filtros,
// lista paginada en el servidor y todas las modales del módulo (resources/js/Modules/Quotes).
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useToasts } from '../../../design/vue/composables/useToasts.js';
import AppButton from '../../Components/AppButton.vue';
import DataTable from '../../Components/DataTable.vue';
import DateInput from '../../Components/DateInput.vue';
import DropdownMenu from '../../Components/DropdownMenu.vue';
import IconButton from '../../Components/IconButton.vue';
import PageHeader from '../../Components/PageHeader.vue';
import Pagination from '../../Components/Pagination.vue';
import SearchSelect from '../../Components/SearchSelect.vue';
import StatCard from '../../Components/StatCard.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import Toolbar from '../../Components/Toolbar.vue';
import { useConfirm } from '../../composables/useConfirm.js';
import AppLayout from '../../Layouts/AppLayout.vue';
import { date, money } from '../../lib/format.js';
import { visit } from '../../lib/http.js';
import { tableLink, textLink } from '../../lib/ui.js';
import { clearQuoteSelection, readQuoteSelection } from '../../Modules/Inventory/selection.js';
import LostModal from '../../Modules/Quotes/LostModal.vue';
import MailModal from '../../Modules/Quotes/MailModal.vue';
import ParticipantsModal from '../../Modules/Quotes/ParticipantsModal.vue';
import QuoteFormModal from '../../Modules/Quotes/QuoteFormModal.vue';
import QuoteViewerModal from '../../Modules/Quotes/QuoteViewerModal.vue';
import RevertModal from '../../Modules/Quotes/RevertModal.vue';
import StatusModal from '../../Modules/Quotes/StatusModal.vue';
import WonModal from '../../Modules/Quotes/WonModal.vue';
import { fetchQuote } from '../../Modules/Quotes/api.js';
import { lineFromSelection } from '../../Modules/Quotes/quotes.js';
import { celebrate } from '../../lib/motion/index.js';

const props = defineProps({
    quotes: { type: Object, required: true },
    stats: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    filterOptions: { type: Object, default: () => ({}) },
    statuses: { type: Array, default: () => [] },
    issuerCompanies: { type: Array, default: () => [] },
    taxRules: { type: Object, default: () => ({ rate: 15, prices_include_tax: false }) },
    can: { type: Object, default: () => ({}) },
});

const page = usePage();
const confirm = useConfirm();
const toasts = useToasts();

const state = reactive({
    search: props.filters.search ?? '',
    segment: props.filters.segment ?? 'all',
    institution_id: props.filters.institution_id ?? null,
    tender_id: props.filters.tender_id ?? null,
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});
const loading = ref(false);
const loadError = ref('');

const modal = reactive({ form: false, viewer: false, participants: false, won: false, lost: false, revert: false, status: false, mail: false });
const selected = ref(null);
const editingId = ref(null);
const prefill = ref([]);
const uploadOnOpen = ref(false);

const activeCompanyId = computed(() => page.props.activeCompany?.id ?? null);
const filtered = computed(() => Boolean(state.search || state.institution_id || state.tender_id || state.from || state.to || state.segment !== 'all'));
const moreFilters = computed(() => Boolean(state.institution_id || state.tender_id || state.from || state.to));
const segments = computed(() => [
    { label: 'Todas', value: 'all' },
    { label: 'En preparación', value: 'preparacion', count: props.stats.preparing },
    { label: 'Presentadas', value: 'presentadas', count: props.stats.presented },
    { label: 'Ganadas', value: 'ganadas', count: props.stats.won },
    { label: 'Perdidas', value: 'perdidas', count: props.stats.lost },
]);
const query = computed(() => Object.fromEntries(Object.entries(state).filter(([key, value]) => value !== null && value !== '' && !(key === 'segment' && value === 'all'))));
const exportQuery = computed(() => new URLSearchParams(query.value).toString());
const subject = computed(() => (selected.value ? { type: 'quote', id: selected.value.id, label: selected.value.number, amount: selected.value.total, outcome: selected.value.outcome } : null));

const columns = [
    { key: 'number', label: 'Cotización', primary: true, width: 'w-40' },
    { key: 'institution', label: 'Institución', format: (value) => value?.name ?? '—' },
    { key: 'tender', label: 'Licitación', format: (value) => value?.reference ?? '—', width: 'w-40' },
    { key: 'quoted_at', label: 'Fecha', format: (value) => date(value), width: 'w-28' },
    { key: 'total', label: 'Total (L)', align: 'right', format: (value) => money(value, 'HNL', { symbol: false }), width: 'w-36' },
    { key: 'status', label: 'Estado', width: 'w-32' },
    { key: 'outcome', label: 'Resultado', width: 'w-32' },
];

function reload() {
    loadError.value = '';
    router.get('/cotizaciones', query.value, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['quotes', 'stats', 'filters', 'filterOptions'],
        onStart: () => (loading.value = true),
        onFinish: () => (loading.value = false),
        onError: () => (loadError.value = 'No se pudo cargar la lista de cotizaciones.'),
    });
}

watch(() => [state.search, state.segment, state.institution_id, state.tender_id, state.from, state.to], reload);

function clearFilters() {
    Object.assign(state, { search: '', segment: 'all', institution_id: null, tender_id: null, from: '', to: '' });
}

function refresh() {
    router.reload({ only: ['quotes', 'stats'] });
}

/** Mantiene la fila elegida al día tras recargar (sus permisos cambian con el estado). */
watch(
    () => props.quotes.data,
    (rows) => {
        if (selected.value) selected.value = rows.find((row) => row.id === selected.value.id) ?? selected.value;
    },
);

function open(name, row = null) {
    selected.value = row;
    modal[name] = true;
}

function create(lines = []) {
    editingId.value = null;
    prefill.value = lines;
    modal.form = true;
}

function edit(row) {
    editingId.value = row.id;
    prefill.value = [];
    modal.form = true;
}

function view(row, withUpload = false) {
    uploadOnOpen.value = withUpload;
    open('viewer', row);
}

function remove(row) {
    confirm({
        verb: 'Eliminar',
        object: `la cotización ${row.number}`,
        noun: 'cotización',
        message: 'Solo se eliminan cotizaciones en Borrador. Se quitan sus líneas y sus adjuntos dejan de estar enlazados.',
        action: () => visit('delete', `/cotizaciones/${row.id}`),
    });
}

// Ganada: celebración con confeti de la marca (con animaciones reducidas solo queda el toast).
function onWon(result, message) {
    celebrate();
    onOutcome(result, message);
}

function onOutcome(result, message) {
    if (message) toasts.show({ status: 'ok', title: message });
    refresh();
}

function actionsOf(row) {
    const c = row.can ?? {};
    return [
        ...(c.update ? [{ label: 'Editar', icon: 'pencil-outline', onSelect: () => edit(row) }] : []),
        ...(c.changeStatus ? [{ label: 'Cambiar estado', icon: 'swap-horizontal', onSelect: () => open('status', row) }] : []),
        ...(c.send ? [{ label: 'Enviar por correo', icon: 'email-outline', onSelect: () => open('mail', row) }] : []),
        { label: 'Descargar PDF', icon: 'download-outline', href: `/cotizaciones/${row.id}/pdf?descargar=1`, external: true },
        ...(row.client ? [{ label: 'Ver cliente', icon: 'domain', href: row.client.url }] : []),
        ...(row.contract ? [{ label: 'Ver contrato', icon: 'file-sign', href: row.contract.url }] : []),
        ...(c.revert ? [{ label: 'Revertir resultado', icon: 'undo-variant', separator: true, onSelect: () => open('revert', row) }] : []),
        ...(c.delete ? [{ label: 'Eliminar', icon: 'trash-can-outline', danger: true, separator: !c.revert, onSelect: () => remove(row) }] : []),
    ];
}

/** Abre el formulario con las líneas que trae el inventario (`/cotizaciones?desde_inventario=1`). */
function takeInventorySelection() {
    const params = new URLSearchParams(window.location.search);
    if (!params.has('desde_inventario')) return;
    const selection = readQuoteSelection();
    params.delete('desde_inventario');
    const url = `/cotizaciones${params.toString() ? `?${params}` : ''}`;
    router.replace({ url, preserveState: true, preserveScroll: true });
    if (!selection?.items?.length) {
        toasts.show({ status: 'warning', title: 'No hay productos seleccionados', detail: 'Elige productos en el inventario y usa «Llevar a una cotización».' });
        return;
    }
    if (selection.company_id && activeCompanyId.value && Number(selection.company_id) !== Number(activeCompanyId.value)) {
        clearQuoteSelection();
        toasts.show({ status: 'warning', title: 'La selección era de otra empresa', detail: 'Se descartó. Elige de nuevo los productos en el inventario de esta empresa.' });
        return;
    }
    clearQuoteSelection();
    if (!props.can.create) return;
    create(selection.items.map(lineFromSelection));
}

/** Quita parámetros de la URL sin recargar, para que «Atrás» no vuelva a abrir la modal. */
function dropParams(...names) {
    const params = new URLSearchParams(window.location.search);
    if (!names.some((name) => params.has(name))) return;
    names.forEach((name) => params.delete(name));
    router.replace({ url: `/cotizaciones${params.toString() ? `?${params}` : ''}`, preserveState: true, preserveScroll: true });
}

/**
 * Enlaces directos: `?ver={id}` abre la modal Ver (también llega aquí `/cotizaciones/{id}` abierto
 * en el navegador) y `?accion=nueva` abre el formulario de nueva cotización (atajo de la PWA).
 */
async function takeDirectLink() {
    const params = new URLSearchParams(window.location.search);
    if (params.get('accion') === 'nueva') {
        if (props.can.create) create();
        else dropParams('accion');
        return;
    }
    const id = Number(params.get('ver'));
    if (!params.has('ver')) return;
    if (!Number.isInteger(id) || id <= 0) {
        dropParams('ver');
        return;
    }
    const row = props.quotes.data?.find((item) => item.id === id);
    if (row) {
        view(row);
        return;
    }
    try {
        view(await fetchQuote(id));
    } catch {
        dropParams('ver');
        toasts.show({ status: 'error', title: 'No se encontró la cotización', detail: 'No existe o pertenece a otra empresa.' });
    }
}

watch(
    () => modal.viewer,
    (open) => {
        if (!open) dropParams('ver');
    },
);
watch(
    () => modal.form,
    (open) => {
        if (!open) dropParams('accion');
    },
);

onMounted(() => {
    takeInventorySelection();
    takeDirectLink();
});

const stopListener = router.on('exception', () => (loadError.value = 'No se pudo cargar la lista de cotizaciones.'));
onBeforeUnmount(() => stopListener());
</script>

<template>
    <AppLayout title="Cotizaciones" :fab-label="can.create ? 'Nueva cotización' : ''" @fab="create()">
        <PageHeader description="Ofertas de la empresa activa: arma, firma, presenta y registra el resultado.">
            <template #actions>
                <AppButton external :href="`/cotizaciones/export/list-pdf?${exportQuery}`" icon="file-pdf-box">PDF</AppButton>
                <AppButton external :href="`/cotizaciones/export/excel?${exportQuery}`" icon="file-excel-outline">Excel</AppButton>
                <AppButton href="/inventario" icon="package-variant-closed">Desde inventario</AppButton>
                <span v-if="can.create && quotes.total > 0" class="hidden lg:contents">
                    <AppButton variant="primary" icon="plus" @click="create()">Nueva cotización</AppButton>
                </span>
            </template>
        </PageHeader>

        <div class="cap-stagger grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard label="En preparación" :count="stats.preparing ?? 0" icon="file-document-edit-outline" hint="Borrador o emitida, sin resultado" href="/cotizaciones?segment=preparacion" />
            <StatCard label="Presentadas" :count="stats.presented ?? 0" :hint="`${money(stats.presented_amount ?? 0)} esperando resultado`" href="/cotizaciones?segment=presentadas" />
            <StatCard variant="featured" label="Ganadas" :count="stats.won ?? 0" icon="trophy-outline" :hint="`${money(stats.won_amount ?? 0)} adjudicados`" href="/cotizaciones?segment=ganadas" />
            <StatCard label="Tasa de adjudicación" :value="stats.rate === null || stats.rate === undefined ? '—' : `${stats.rate} %`" :hint="`${stats.won ?? 0} ganadas de ${(stats.won ?? 0) + (stats.lost ?? 0)} con resultado`" />
        </div>

        <Toolbar v-model:search="state.search" v-model:filter="state.segment" :filters="segments" filter-label="Filtrar por estado o resultado" search-placeholder="Número o institución" search-label="Buscar cotizaciones">
            <template #filters>
                <div class="grid w-full grid-cols-1 gap-2 sm:grid-cols-2 lg:flex lg:w-auto lg:flex-wrap">
                    <SearchSelect v-model="state.institution_id" url="/instituciones/buscar" option-value="id" option-label="name" :selected="filterOptions.institution" placeholder="Institución…" aria-label="Filtrar por institución" class="lg:w-56" />
                    <SearchSelect v-model="state.tender_id" :options="filterOptions.tenders ?? []" option-value="id" option-label="name" option-description="description" placeholder="Licitación…" aria-label="Filtrar por licitación" class="lg:w-48" />
                    <DateInput v-model="state.from" aria-label="Desde" title="Desde" class="lg:w-40" :max="state.to || undefined" />
                    <DateInput v-model="state.to" aria-label="Hasta" title="Hasta" class="lg:w-40" :min="state.from || undefined" />
                    <AppButton v-if="moreFilters" icon="filter-remove-outline" @click="clearFilters">Limpiar filtros</AppButton>
                </div>
            </template>
        </Toolbar>

        <div>
            <DataTable
                :columns="columns"
                :rows="quotes.data"
                :loading="loading"
                :error="loadError"
                :filtered="filtered"
                caption="Cotizaciones"
                clickable
                empty-icon="file-document-outline"
                empty-title="Aún no hay cotizaciones"
                empty-description="Crea la primera o llévala desde el inventario con los productos elegidos."
                @row-click="view"
                @retry="reload"
                @clear-filters="clearFilters"
            >
                <template #cell-number="{ row }">
                    <button type="button" :class="tableLink" class="tabular-nums" @click.stop="view(row)">{{ row.number }}</button>
                </template>
                <template #cell-institution="{ row }">
                    <span class="block max-w-xs truncate" :title="row.institution?.name">{{ row.institution?.name ?? '—' }}</span>
                </template>
                <template #cell-tender="{ row }">
                    <Link v-if="row.tender" :href="`/licitaciones/${row.tender.id}`" :class="textLink" @click.stop>{{ row.tender.reference }}</Link>
                    <span v-else class="text-cap-text-soft">—</span>
                </template>
                <template #cell-status="{ row }"><StatusBadge :value="row.status" :label="row.status" /></template>
                <template #cell-outcome="{ row }"><StatusBadge :value="row.outcome" :label="row.outcome" /></template>
                <template #actions="{ row }">
                    <div class="flex items-center justify-end gap-0.5">
                        <IconButton icon="eye-outline" :label="`Ver ${row.number}`" @click="view(row)" />
                        <IconButton v-if="row.can.attach" icon="file-upload-outline" :label="`Agregar PDF a ${row.number}`" @click="view(row, true)" />
                        <IconButton icon="account-group-outline" :label="`Participantes de ${row.number}`" @click="open('participants', row)" />
                        <IconButton v-if="row.can.win" icon="trophy-outline" :label="`Marcar ${row.number} como Ganada`" @click="open('won', row)" />
                        <IconButton v-if="row.can.lose" icon="close-circle-outline" :label="`Marcar ${row.number} como Perdida`" @click="open('lost', row)" />
                        <DropdownMenu :label="`Más acciones de ${row.number}`" :items="actionsOf(row)" />
                    </div>
                </template>
                <template #card="{ row }">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-cap-control bg-cap-red-200 text-cap-red-800 dark:bg-cap-red-600/20 dark:text-cap-brand-soft" aria-hidden="true"><i class="mdi mdi-file-document-outline text-xl leading-none" /></span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-semibold text-gray-900 dark:text-gray-100">{{ row.number }}</span>
                            <span class="block truncate text-xs text-cap-text-soft">{{ row.institution?.name ?? 'Sin institución' }}</span>
                            <span class="mt-1 block text-sm font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ money(row.total) }}</span>
                        </span>
                        <span class="flex shrink-0 flex-col items-end gap-1">
                            <StatusBadge :value="row.status" :label="row.status" />
                            <StatusBadge v-if="row.outcome !== 'Pendiente'" :value="row.outcome" :label="row.outcome" />
                        </span>
                    </div>
                    <div class="mt-3 flex flex-wrap items-center gap-1 border-t border-cap-divider pt-2" @click.stop>
                        <IconButton icon="eye-outline" :label="`Ver ${row.number}`" @click="view(row)" />
                        <IconButton v-if="row.can.attach" icon="file-upload-outline" :label="`Agregar PDF a ${row.number}`" @click="view(row, true)" />
                        <IconButton icon="account-group-outline" :label="`Participantes de ${row.number}`" @click="open('participants', row)" />
                        <IconButton v-if="row.can.win" icon="trophy-outline" :label="`Marcar ${row.number} como Ganada`" @click="open('won', row)" />
                        <IconButton v-if="row.can.lose" icon="close-circle-outline" :label="`Marcar ${row.number} como Perdida`" @click="open('lost', row)" />
                        <span class="ml-auto"><DropdownMenu :label="`Más acciones de ${row.number}`" :items="actionsOf(row)" /></span>
                    </div>
                </template>
                <template v-if="can.create" #empty-action>
                    <AppButton variant="primary" icon="plus" @click="create()">Nueva cotización</AppButton>
                </template>
            </DataTable>
            <Pagination :paginator="quotes" :only="['quotes', 'filters']" />
        </div>

        <QuoteFormModal v-model:open="modal.form" :quote-id="editingId" :prefill="prefill" :issuer-companies="issuerCompanies" :active-company-id="activeCompanyId" :tax-rules="taxRules" :tenders="filterOptions.tenders ?? []" :opportunities="filterOptions.opportunities ?? []" :can-see-cost="Boolean(can.viewCost)" @saved="refresh" />
        <QuoteViewerModal v-model:open="modal.viewer" :quote="selected" :start-with-upload="uploadOnOpen" @changed="refresh" />
        <ParticipantsModal v-model:open="modal.participants" :subject="subject" :title="`Participantes · ${selected?.number ?? ''}`" @saved="refresh" />
        <WonModal v-model:open="modal.won" :subject="subject" @done="onWon" />
        <LostModal v-model:open="modal.lost" :subject="subject" @done="onOutcome" />
        <RevertModal v-model:open="modal.revert" :subject="subject" @done="onOutcome" />
        <StatusModal v-model:open="modal.status" :quote="selected" />
        <MailModal v-model:open="modal.mail" :quote="selected" />
    </AppLayout>
</template>
