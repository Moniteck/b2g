<script setup>
// Base de clientes de la empresa activa. Los clientes nacen al ganar una oferta: no hay alta manual.
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CapAlert from '@design/vue/CapAlert.vue';
import AppButton from '@/Components/AppButton.vue';
import DataTable from '@/Components/DataTable.vue';
import DropdownMenu from '@/Components/DropdownMenu.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Toolbar from '@/Components/Toolbar.vue';
import { useConfirm } from '@/composables/useConfirm.js';
import AppLayout from '@/Layouts/AppLayout.vue';
import { date, money, number, relativeDays } from '@/lib/format.js';
import { visit } from '@/lib/http.js';
import { useListFilters } from '@/Pages/Admin/Partials/useListFilters.js';
import ClientFormModal from '@/Pages/Clients/Partials/ClientFormModal.vue';

const props = defineProps({
    clients: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    stats: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
});

const { filters, loading, error, active, reset, reload } = useListFilters('/clientes', props.filters, {
    only: ['clients', 'filters', 'stats'],
    defaults: { search: '', status: 'all', sort: 'last_purchase_at', direction: 'desc' },
});

const confirm = useConfirm();
const editing = ref(null);

const columns = [
    { key: 'name', label: 'Cliente', primary: true, sortable: true, wrap: true },
    { key: 'tax_id', label: 'RTN', hideOnMobile: true },
    { key: 'contact_name', label: 'Contacto', hideOnMobile: true, wrap: true },
    { key: 'accumulated_amount', label: 'Adjudicado acumulado', align: 'right', sortable: true, format: (value) => money(value) },
    { key: 'last_purchase_at', label: 'Último ganado', sortable: true, format: (value) => date(value) },
    { key: 'status', label: 'Estado' },
];

const sort = computed({
    get: () => ({ key: filters.sort, direction: filters.direction }),
    set: (value) => {
        filters.sort = value.key;
        filters.direction = value.direction;
    },
});

const exportUrl = computed(() => {
    const params = new URLSearchParams();
    if (filters.search) params.set('search', filters.search);
    if (filters.status !== 'all') params.set('status', filters.status);
    const query = params.toString();
    return `/clientes/export/csv${query ? `?${query}` : ''}`;
});

function actionsOf(row) {
    const items = [{ label: 'Ver ficha', icon: 'eye-outline', href: `/clientes/${row.id}` }];
    if (props.can.update) items.push({ label: 'Editar datos', icon: 'pencil-outline', onSelect: () => (editing.value = row) });
    if (props.can.delete) items.push({ label: 'Eliminar', icon: 'trash-can-outline', danger: true, separator: true, onSelect: () => remove(row) });
    return items;
}

function remove(row) {
    confirm({
        verb: 'Eliminar',
        object: `el cliente ${row.name}`,
        noun: 'cliente',
        message: 'Solo se puede eliminar si no tiene contratos. Sus cotizaciones ganadas se conservan.',
        icon: 'trash-can-outline',
        action: () => visit('delete', `/clientes/${row.id}`, {}, { only: ['clients', 'stats', 'flash'] }),
    });
}
</script>

<template>
    <AppLayout title="Clientes">
        <PageHeader description="Instituciones a las que la empresa activa les ha ganado una oferta.">
            <template #actions>
                <AppButton external :href="exportUrl" icon="file-delimited-outline" :disabled="!clients.total">Exportar CSV</AppButton>
            </template>
        </PageHeader>

        <CapAlert status="info" title="Los clientes se registran automáticamente al ganar una oferta" detail="Al marcar una cotización u oportunidad como Ganada se crea el cliente, o se suma el monto al que ya existe." />

        <div class="cap-stagger grid grid-cols-1 gap-4 sm:grid-cols-3">
            <StatCard variant="featured" label="Monto adjudicado acumulado" :value="money(stats.amount)" :hint="`${number(stats.total)} ${stats.total === 1 ? 'cliente' : 'clientes'}`" />
            <StatCard label="Clientes activos" :count="stats.active" :hint="stats.total > stats.active ? `${number(stats.total - stats.active)} inactivos` : 'Todos activos'" />
            <StatCard label="Último ganado" :value="stats.last_purchase_at ? date(stats.last_purchase_at, 'medium') : '—'" :hint="stats.last_purchase_at ? relativeDays(stats.last_purchase_at) : 'Aún sin adjudicaciones'" />
        </div>

        <Toolbar
            v-model:search="filters.search"
            v-model:filter="filters.status"
            search-placeholder="Buscar por nombre o RTN"
            filter-label="Estado del cliente"
            :filters="[
                { label: 'Todos', value: 'all' },
                { label: 'Activos', value: 'Activo' },
                { label: 'Inactivos', value: 'Inactivo' },
            ]"
        />

        <div>
            <DataTable
                v-model:sort="sort"
                server-sort
                :columns="columns"
                :rows="clients.data"
                :loading="loading"
                :error="error"
                :filtered="active"
                clickable
                caption="Clientes"
                empty-icon="account-group-outline"
                empty-title="Aún no hay clientes"
                empty-description="Los clientes se registran automáticamente al ganar una oferta."
                @row-click="(row) => router.visit(`/clientes/${row.id}`)"
                @retry="reload"
                @clear-filters="reset"
            >
                <template #cell-name="{ row }">
                    <div class="min-w-0">
                        <p class="font-medium text-gray-900 dark:text-gray-100">{{ row.name }}</p>
                        <p v-if="row.institution && row.institution.name !== row.name" class="truncate text-xs text-cap-text-soft">{{ row.institution.name }}</p>
                    </div>
                </template>
                <template #cell-contact_name="{ row }">
                    <div v-if="row.contact_name || row.email" class="min-w-0">
                        <p class="text-sm">{{ row.contact_name || '—' }}</p>
                        <p v-if="row.email || row.phone" class="truncate text-xs text-cap-text-soft">{{ [row.phone, row.email].filter(Boolean).join(' · ') }}</p>
                    </div>
                    <span v-else class="text-cap-text-soft">—</span>
                </template>
                <template #cell-status="{ value }"><StatusBadge :value="value" /></template>
                <template #actions="{ row }"><DropdownMenu :label="`Acciones de ${row.name}`" :items="actionsOf(row)" /></template>
            </DataTable>
            <Pagination :paginator="clients" :only="['clients', 'filters']" />
        </div>

        <ClientFormModal :client="editing" @close="editing = null" />
    </AppLayout>
</template>
