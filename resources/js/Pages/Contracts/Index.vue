<script setup>
// Post-adjudicación: lista de contratos ganados de la empresa activa con sus plazos y entregas.
import { router } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import AppButton from '@/Components/AppButton.vue';
import DataTable from '@/Components/DataTable.vue';
import DateInput from '@/Components/DateInput.vue';
import DropdownMenu from '@/Components/DropdownMenu.vue';
import FormField from '@/Components/FormField.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import SelectInput from '@/Components/SelectInput.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Toolbar from '@/Components/Toolbar.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { date, money, number } from '@/lib/format.js';
import { card } from '@/lib/ui.js';
import ContractFormModal from '@/Modules/Contracts/ContractFormModal.vue';
import HolidaysModal from '@/Modules/Contracts/HolidaysModal.vue';
import { percentOf, remaining, tone } from '@/Modules/Contracts/contracts.js';
import { useListFilters } from '@/Pages/Admin/Partials/useListFilters.js';

const props = defineProps({
    contracts: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    stats: { type: Object, default: () => ({}) },
    groups: { type: Object, default: () => ({}) },
    statuses: { type: Array, default: () => [] },
    tones: { type: Object, default: () => ({}) },
    officials: { type: Array, default: () => [] },
    institution: { type: Object, default: null },
    form: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
});

const DEFAULTS = { search: '', group: 'todos', status: '', manager_id: null, institution_id: null, end_from: '', end_to: '', sort: 'end_date', direction: 'asc' };
const { filters, loading, error, active, reset, reload } = useListFilters('/post-adjudicacion', { ...props.filters, status: props.filters.status ?? '', end_from: props.filters.end_from ?? '', end_to: props.filters.end_to ?? '' }, {
    only: ['contracts', 'filters', 'groups', 'institution'],
    defaults: DEFAULTS,
});

const createOpen = ref(false);
const holidaysOpen = ref(false);

// Enlace directo `?panel=feriados` (la consulta es de todo rol que ve la pantalla; editar exige can.manageHolidays; también llega aquí `/post-adjudicacion/feriados` abierto en el navegador).
// Al cerrar se quita el parámetro sin recargar, para que «Atrás» no vuelva a abrir la modal.
onMounted(() => {
    if (new URLSearchParams(window.location.search).get('panel') === 'feriados') holidaysOpen.value = true;
});
watch(holidaysOpen, (open) => {
    const params = new URLSearchParams(window.location.search);
    if (open || !params.has('panel')) return;
    params.delete('panel');
    router.replace({ url: `/post-adjudicacion${params.toString() ? `?${params}` : ''}`, preserveState: true, preserveScroll: true });
});
const showFilters = ref(Boolean(props.filters.status || props.filters.manager_id || props.filters.institution_id || props.filters.end_from || props.filters.end_to));

const columns = [
    { key: 'number', label: 'Contrato', primary: true, sortable: true, wrap: true },
    { key: 'institution', label: 'Institución', hideOnMobile: true, wrap: true },
    { key: 'manager', label: 'Oficial', hideOnMobile: true },
    { key: 'awarded_amount', label: 'Monto adjudicado', align: 'right', sortable: true, format: (value) => money(value) },
    { key: 'end_date', label: 'Fin', sortable: true },
    { key: 'deliveries', label: 'Entregas' },
    { key: 'status', label: 'Estado' },
];

const sort = computed({
    get: () => ({ key: filters.sort, direction: filters.direction }),
    set: (value) => {
        filters.sort = value.key;
        filters.direction = value.direction;
    },
});

const segments = computed(() => [
    { label: 'Todos', value: 'todos', count: props.groups.todos },
    { label: 'Activos', value: 'activos', count: props.groups.activos },
    { label: 'En ejecución', value: 'ejecucion', count: props.groups.ejecucion },
    { label: 'Entregados', value: 'entregados', count: props.groups.entregados },
    { label: 'Finalizados', value: 'finalizados', count: props.groups.finalizados },
]);

const officialOptions = computed(() => props.officials.map((user) => ({ value: user.id, label: user.name })));
const statusOptions = computed(() => props.statuses.map((value) => ({ value, label: value })));

function open(row) {
    router.visit(`/post-adjudicacion/${row.id}`);
}

function actionsOf(row) {
    return [{ label: 'Abrir ficha', icon: 'eye-outline', href: `/post-adjudicacion/${row.id}` }, ...(props.can.update ? [{ label: 'Registrar seguimiento', icon: 'message-plus-outline', href: `/post-adjudicacion/${row.id}?tab=seguimientos&nuevo=1` }] : [])];
}
</script>

<template>
    <AppLayout title="Post-adjudicación">
        <PageHeader description="Contratos ganados: plazos, documentos, entregas y seguimiento.">
            <template #actions>
                <AppButton icon="calendar-remove-outline" @click="holidaysOpen = true">Feriados</AppButton>
                <AppButton v-if="can.create && contracts.total" variant="primary" icon="plus" @click="createOpen = true">Nuevo contrato</AppButton>
            </template>
        </PageHeader>

        <div class="cap-stagger grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
            <StatCard class="col-span-2 xl:col-span-1" variant="featured" label="Monto adjudicado vigente" :value="money(stats.open_amount)" :hint="`${number(stats.open_count)} ${stats.open_count === 1 ? 'contrato vivo' : 'contratos vivos'}`" />
            <StatCard label="En ejecución" :count="stats.in_progress" hint="Con parciales" />
            <StatCard :variant="stats.expiring ? 'alert' : 'normal'" label="Por vencer en 30 días" :count="stats.expiring" :hint="stats.expiring ? 'Revisa sus entregas' : 'Ninguno vence pronto'" />
            <StatCard label="Entregas pendientes" :count="stats.pending_deliveries" hint="De contratos vivos" />
        </div>

        <Toolbar v-model:search="filters.search" v-model:filter="filters.group" search-placeholder="Número, nombre o institución" filter-label="Estado del contrato" :filters="segments">
            <template #actions>
                <AppButton :icon="showFilters ? 'filter-off-outline' : 'filter-variant'" :aria-expanded="showFilters" @click="showFilters = !showFilters">Filtros</AppButton>
            </template>
        </Toolbar>

        <section v-if="showFilters" :class="card" class="grid grid-cols-1 gap-4 p-4 sm:grid-cols-2 xl:grid-cols-5" aria-label="Filtros de la lista">
            <FormField label="Estado exacto"><SelectInput v-model="filters.status" :options="statusOptions" placeholder="Cualquiera" /></FormField>
            <FormField label="Oficial"><SelectInput v-model="filters.manager_id" :options="officialOptions" placeholder="Todos" /></FormField>
            <FormField label="Institución"><SearchSelect v-model="filters.institution_id" url="/instituciones/buscar" option-value="id" option-label="name" :selected="institution" placeholder="Buscar…" /></FormField>
            <FormField label="Fin desde"><DateInput v-model="filters.end_from" /></FormField>
            <FormField label="Fin hasta"><DateInput v-model="filters.end_to" :min="filters.end_from || ''" /></FormField>
        </section>

        <div>
            <DataTable
                v-model:sort="sort"
                server-sort
                :columns="columns"
                :rows="contracts.data"
                :loading="loading"
                :error="error"
                :filtered="active"
                clickable
                caption="Contratos"
                empty-icon="file-sign"
                empty-title="Aún no hay contratos"
                empty-description="Los contratos nacen al marcar una cotización como Ganada. También puedes registrar uno a mano."
                @row-click="open"
                @retry="reload"
                @clear-filters="reset"
            >
                <template #cell-number="{ row }">
                    <div class="min-w-0">
                        <p class="font-medium text-cap-brand dark:text-cap-brand-soft">{{ row.number }}</p>
                        <p class="line-clamp-2 text-xs text-cap-text-soft">{{ row.name }}</p>
                    </div>
                </template>
                <template #cell-institution="{ row }"><span class="text-sm">{{ row.institution?.name ?? '—' }}</span></template>
                <template #cell-manager="{ row }"><span class="text-sm">{{ row.manager?.name ?? '—' }}</span></template>
                <template #cell-end_date="{ row }">
                    <div class="flex flex-col items-start gap-1">
                        <span class="text-sm tabular-nums">{{ row.end_date ? date(row.end_date) : '—' }}</span>
                        <StatusBadge v-if="remaining(row.end_date, row.is_open) && row.end_date" v-bind="remaining(row.end_date, row.is_open)" />
                    </div>
                </template>
                <template #cell-deliveries="{ row }">
                    <div v-if="row.deliveries_total" class="flex min-w-28 flex-col gap-1">
                        <span class="text-xs tabular-nums text-cap-text-soft">{{ row.deliveries_done }} de {{ row.deliveries_total }}</span>
                        <div class="h-1.5 w-full overflow-hidden rounded-full bg-cap-card-2" role="progressbar" :aria-valuenow="percentOf(row.deliveries_done, row.deliveries_total)" aria-valuemin="0" aria-valuemax="100" :aria-label="`Avance de entregas de ${row.number}`">
                            <div class="m-fill h-full rounded-full bg-cap-status-ok dark:bg-cap-status-dark-ok" :style="{ width: `${percentOf(row.deliveries_done, row.deliveries_total)}%` }" />
                        </div>
                    </div>
                    <span v-else class="text-xs text-cap-text-soft">Sin entregas</span>
                </template>
                <template #cell-status="{ value }"><StatusBadge :status="tone(value, tones)" :label="value" /></template>
                <template #actions="{ row }"><DropdownMenu :label="`Acciones de ${row.number}`" :items="actionsOf(row)" /></template>
                <template v-if="can.create" #empty-action><AppButton variant="primary" icon="plus" @click="createOpen = true">Nuevo contrato</AppButton></template>
            </DataTable>
            <Pagination :paginator="contracts" :only="['contracts', 'filters']" />
        </div>

        <ContractFormModal v-model:open="createOpen" :officials="officials" :options="form" />
        <HolidaysModal v-model:open="holidaysOpen" :can-manage="can.manageHolidays" />
    </AppLayout>
</template>
