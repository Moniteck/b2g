<script setup>
import { Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import AppButton from '../../Components/AppButton.vue';
import DataTable from '../../Components/DataTable.vue';
import DropdownMenu from '../../Components/DropdownMenu.vue';
import PageHeader from '../../Components/PageHeader.vue';
import Pagination from '../../Components/Pagination.vue';
import SelectInput from '../../Components/SelectInput.vue';
import StatCard from '../../Components/StatCard.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import Toolbar from '../../Components/Toolbar.vue';
import { useConfirm } from '../../composables/useConfirm.js';
import AppLayout from '../../Layouts/AppLayout.vue';
import { dateTime, money } from '../../lib/format.js';
import { visit } from '../../lib/http.js';
import { tableLink } from '../../lib/ui.js';
import ImportCaptureModal from '../../Modules/Tenders/ImportCaptureModal.vue';
import TenderFormModal from '../../Modules/Tenders/TenderFormModal.vue';
import { deadline, tenderTone } from '../../Modules/Tenders/tenders.js';

const props = defineProps({
    tenders: { type: Object, required: true },
    stats: { type: Object, default: () => ({ open: 0, week: 0, clarifications: 0 }) },
    filters: { type: Object, default: () => ({}) },
    statuses: { type: Array, default: () => [] },
    modalities: { type: Array, default: () => [] },
    maxImageMb: { type: Number, default: 10 },
    can: { type: Object, default: () => ({}) },
});

const confirm = useConfirm();
const state = reactive({
    search: props.filters.search ?? '',
    window: props.filters.window ?? 'all',
    status: props.filters.status ?? '',
    modality: props.filters.modality ?? '',
});
const loading = ref(false);
const loadError = ref('');
const formOpen = ref(false);
const importOpen = ref(false);
const editing = ref(null);

const windows = computed(() => [
    { label: 'Todas', value: 'all' },
    { label: 'Vigentes', value: 'open', count: props.stats.open },
    { label: 'Cierran esta semana', value: 'week', count: props.stats.week },
    { label: 'Aclaraciones próximas', value: 'clarifications', count: props.stats.clarifications },
]);

const filtered = computed(() => Boolean(state.search || state.status || state.modality || state.window !== 'all'));
const statusOptions = computed(() => props.statuses.map((value) => ({ value, label: value })));
const modalityOptions = computed(() => props.modalities.map((value) => ({ value, label: value })));
const exportQuery = computed(() => new URLSearchParams(Object.entries(state).filter(([, value]) => value && value !== 'all')).toString());

const columns = [
    { key: 'reference', label: 'Expediente', primary: true, width: 'w-48' },
    { key: 'title', label: 'Objeto' },
    { key: 'institution', label: 'Entidad', format: (value) => value?.acronym || value?.name || '—', hideOnMobile: false },
    { key: 'presentation_deadline', label: 'Recepción de ofertas', width: 'w-52' },
    { key: 'clarification_deadline', label: 'Aclaraciones', width: 'w-44' },
    { key: 'estimated_amount', label: 'Monto (L)', align: 'right', format: (value) => (Number(value) > 0 ? money(value, 'HNL', { symbol: false }) : '—') },
    { key: 'status', label: 'Estado', width: 'w-36' },
];

function reload() {
    loadError.value = '';
    router.get(
        '/licitaciones',
        Object.fromEntries(Object.entries(state).filter(([, value]) => value && value !== 'all')),
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['tenders', 'stats', 'filters'],
            onStart: () => (loading.value = true),
            onFinish: () => (loading.value = false),
            onError: () => (loadError.value = 'No se pudo cargar la lista de licitaciones.'),
        },
    );
}

watch(() => [state.search, state.window, state.status, state.modality], reload);

function clearFilters() {
    Object.assign(state, { search: '', window: 'all', status: '', modality: '' });
}

function isClosed(row) {
    return !['Activa', 'En preparación'].includes(row.status);
}

function show(row) {
    router.visit(`/licitaciones/${row.id}`);
}

function create() {
    editing.value = null;
    formOpen.value = true;
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

function actionsOf(row) {
    return [
        { label: 'Ver ficha', icon: 'eye-outline', href: `/licitaciones/${row.id}` },
        ...(props.can.create ? [{ label: 'Editar', icon: 'pencil-outline', href: `/licitaciones/${row.id}?editar=1` }] : []),
        ...(props.can.delete ? [{ label: 'Eliminar', icon: 'trash-can-outline', danger: true, separator: true, onSelect: () => remove(row) }] : []),
    ];
}

const stopListener = router.on('exception', () => (loadError.value = 'No se pudo cargar la lista de licitaciones.'));
onBeforeUnmount(() => stopListener());
</script>

<template>
    <AppLayout title="Licitaciones" :fab-label="can.create ? 'Nueva licitación' : ''" @fab="create">
        <PageHeader description="Procesos de compra pública de la empresa activa, ordenados por el plazo más cercano.">
            <template #actions>
                <AppButton external :href="`/licitaciones/export/pdf?${exportQuery}`" icon="file-pdf-box">PDF</AppButton>
                <AppButton external :href="`/licitaciones/export/xlsx?${exportQuery}`" icon="file-excel-outline">Excel</AppButton>
                <AppButton v-if="can.create" icon="text-recognition" @click="importOpen = true">Importar captura</AppButton>
                <AppButton v-if="can.create && tenders.total > 0" variant="primary" icon="plus" @click="create">Nueva licitación</AppButton>
            </template>
        </PageHeader>

        <div class="cap-stagger grid grid-cols-1 gap-3 sm:grid-cols-3">
            <StatCard variant="featured" label="Vigentes" :count="stats.open" icon="gavel" hint="Activas o en preparación con plazo abierto" href="/licitaciones?window=open" />
            <StatCard :variant="stats.week > 0 ? 'alert' : 'normal'" label="Cierran esta semana" :count="stats.week" hint="Recepción de ofertas en 7 días" href="/licitaciones?window=week" />
            <StatCard label="Aclaraciones próximas" :count="stats.clarifications" hint="Último día para preguntar en 7 días" href="/licitaciones?window=clarifications" />
        </div>

        <Toolbar v-model:search="state.search" v-model:filter="state.window" :filters="windows" filter-label="Filtrar por plazo" search-placeholder="Expediente, objeto o entidad" search-label="Buscar licitaciones">
            <template #filters>
                <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
                    <SelectInput v-model="state.status" :options="statusOptions" placeholder="Todos los estados" aria-label="Filtrar por estado" class="sm:w-48" />
                    <SelectInput v-model="state.modality" :options="modalityOptions" placeholder="Todas las modalidades" aria-label="Filtrar por modalidad" class="sm:w-56" />
                </div>
            </template>
        </Toolbar>

        <div>
            <DataTable
                :columns="columns"
                :rows="tenders.data"
                :loading="loading"
                :error="loadError"
                :filtered="filtered"
                caption="Licitaciones"
                clickable
                empty-icon="gavel"
                empty-title="Aún no hay licitaciones"
                empty-description="Importa la captura de HonduCompras o créala a mano."
                @row-click="show"
                @retry="reload"
                @clear-filters="clearFilters"
            >
                <template #cell-reference="{ row }">
                    <Link :href="`/licitaciones/${row.id}`" :class="tableLink" class="tabular-nums" @click.stop>{{ row.reference }}</Link>
                </template>
                <template #cell-title="{ row }">
                    <span class="block max-w-md truncate" :title="row.title">{{ row.title }}</span>
                </template>
                <template #cell-presentation_deadline="{ row }">
                    <span v-if="row.presentation_deadline" class="flex flex-col items-start gap-1">
                        <span class="tabular-nums">{{ dateTime(row.presentation_deadline) }}</span>
                        <StatusBadge v-bind="deadline(row.presentation_deadline, { closed: isClosed(row) })" />
                    </span>
                    <span v-else class="text-cap-text-soft">—</span>
                </template>
                <template #cell-clarification_deadline="{ row }">
                    <span v-if="row.clarification_deadline" class="flex flex-col items-start gap-1">
                        <span class="tabular-nums">{{ dateTime(row.clarification_deadline) }}</span>
                        <StatusBadge v-if="!isClosed(row)" v-bind="deadline(row.clarification_deadline)" />
                    </span>
                    <span v-else class="text-cap-text-soft">—</span>
                </template>
                <template #cell-status="{ row }">
                    <StatusBadge :status="tenderTone(row.status)" :label="row.status" />
                </template>
                <template #card="{ row }">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-cap-control bg-cap-red-200 text-cap-red-800 dark:bg-cap-red-600/20 dark:text-cap-brand-soft" aria-hidden="true"><i class="mdi mdi-gavel text-xl leading-none" /></span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-semibold text-gray-900 dark:text-gray-100">{{ row.reference }}</span>
                            <span class="line-clamp-2 block text-xs text-cap-text-soft">{{ row.title }}</span>
                            <span v-if="row.presentation_deadline" class="mt-2 flex flex-wrap items-center gap-2 text-xs text-cap-text-soft">
                                <span>Ofertas {{ dateTime(row.presentation_deadline) }}</span>
                                <StatusBadge v-bind="deadline(row.presentation_deadline, { closed: isClosed(row) })" />
                            </span>
                        </span>
                        <span class="flex shrink-0 flex-col items-end gap-1">
                            <StatusBadge :status="tenderTone(row.status)" :label="row.status" />
                        </span>
                    </div>
                </template>
                <template #actions="{ row }">
                    <DropdownMenu :label="`Acciones de ${row.reference}`" :items="actionsOf(row)" />
                </template>
                <template v-if="can.create" #empty-action>
                    <AppButton variant="primary" icon="plus" @click="create">Nueva licitación</AppButton>
                </template>
            </DataTable>
            <Pagination :paginator="tenders" :only="['tenders', 'filters']" />
        </div>

        <TenderFormModal v-model:open="formOpen" :tender="editing" :statuses="statuses" :modalities="modalities" />
        <ImportCaptureModal v-model:open="importOpen" :max-size-mb="maxImageMb" />
    </AppLayout>
</template>
