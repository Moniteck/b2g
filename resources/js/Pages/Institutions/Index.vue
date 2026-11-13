<script setup>
// Catálogo de instituciones compradoras, compartido por las empresas del grupo.
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppButton from '../../Components/AppButton.vue';
import DataTable from '../../Components/DataTable.vue';
import DropdownMenu from '../../Components/DropdownMenu.vue';
import PageHeader from '../../Components/PageHeader.vue';
import Pagination from '../../Components/Pagination.vue';
import SelectInput from '../../Components/SelectInput.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import Toolbar from '../../Components/Toolbar.vue';
import { useConfirm } from '../../composables/useConfirm.js';
import AppLayout from '../../Layouts/AppLayout.vue';
import { number } from '../../lib/format.js';
import { visit } from '../../lib/http.js';
import { useListFilters } from '../Admin/Partials/useListFilters.js';
import InstitutionFormModal from './Partials/InstitutionFormModal.vue';

const props = defineProps({
    institutions: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({ types: [], potentials: [], statuses: [] }) },
    can: { type: Object, default: () => ({}) },
});

const { filters, loading, error, active, reset, reload } = useListFilters('/instituciones', props.filters, {
    only: ['institutions', 'filters'],
    defaults: { search: '', status: 'all', type: '', potential: '' },
});

const confirm = useConfirm();
const editing = ref(null);

const POTENTIAL_LABELS = { ALTO: 'Alto', MEDIO: 'Medio', BAJO: 'Bajo' };
const POTENTIAL_STATUS = { ALTO: 'ok', MEDIO: 'warning', BAJO: 'neutral' };

const columns = [
    { key: 'name', label: 'Institución', primary: true, wrap: true },
    { key: 'type', label: 'Tipo', hideOnMobile: true },
    { key: 'potential', label: 'Potencial' },
    { key: 'location', label: 'Ubicación', hideOnMobile: true },
    { key: 'contacts_count', label: 'Contactos', align: 'right', format: (value) => number(value) },
    { key: 'status', label: 'Estado' },
];

function actionsOf(row) {
    const items = [{ label: 'Ver ficha', icon: 'eye-outline', href: `/instituciones/${row.id}` }];
    if (props.can.update) items.push({ label: 'Editar', icon: 'pencil-outline', onSelect: () => (editing.value = row) });
    items.push({ label: 'Ver contactos', icon: 'card-account-phone-outline', href: `/contactos?institution_id=${row.id}` });
    if (props.can.delete) items.push({ label: 'Eliminar', icon: 'trash-can-outline', danger: true, separator: true, onSelect: () => remove(row) });
    return items;
}

function remove(row) {
    confirm({
        verb: 'Eliminar',
        object: `la institución ${row.name}`,
        noun: 'institución',
        message: 'Se borra del catálogo de todas las empresas junto con sus contactos. Si tiene licitaciones o cotizaciones no se podrá eliminar.',
        icon: 'trash-can-outline',
        action: () => visit('delete', `/instituciones/${row.id}`, {}, { only: ['institutions', 'flash'] }),
    });
}
</script>

<template>
    <AppLayout
        title="Instituciones"
        :sections="[{ label: 'Instituciones', href: '/instituciones', active: true }, { label: 'Contactos', href: '/contactos' }]"
        :fab-label="can.create ? 'Nueva institución' : ''"
        @fab="editing = {}"
    >
        <PageHeader :description="`${number(institutions.total)} ${institutions.total === 1 ? 'institución' : 'instituciones'} · catálogo compartido del grupo`">
            <template #actions>
                <AppButton v-if="can.create && institutions.total" variant="primary" icon="plus" @click="editing = {}">Nueva institución</AppButton>
            </template>
        </PageHeader>

        <Toolbar
            v-model:search="filters.search"
            v-model:filter="filters.status"
            search-placeholder="Buscar por nombre o siglas"
            filter-label="Estado"
            :filters="[
                { label: 'Todas', value: 'all' },
                { label: 'Activas', value: 'Activa' },
                { label: 'Inactivas', value: 'Inactiva' },
            ]"
        >
            <template #filters>
                <SelectInput v-model="filters.type" class="sm:w-52" aria-label="Tipo de institución" placeholder="Todos los tipos" :options="options.types" />
                <SelectInput v-model="filters.potential" class="sm:w-44" aria-label="Potencial" placeholder="Todo potencial" :options="options.potentials.map((value) => ({ value, label: POTENTIAL_LABELS[value] ?? value }))" />
            </template>
        </Toolbar>

        <div>
            <DataTable
                :columns="columns"
                :rows="institutions.data"
                :loading="loading"
                :error="error"
                :filtered="active"
                clickable
                caption="Instituciones"
                empty-icon="bank-outline"
                empty-title="Aún no hay instituciones"
                empty-description="Agregue las instituciones a las que la empresa vende o importe una licitación."
                @row-click="(row) => router.visit(`/instituciones/${row.id}`)"
                @retry="reload"
                @clear-filters="reset"
            >
                <template #cell-name="{ row }">
                    <div class="min-w-0">
                        <p class="font-medium text-gray-900 dark:text-gray-100">{{ row.name }}</p>
                        <p v-if="row.acronym" class="text-xs text-cap-text-soft">{{ row.acronym }}</p>
                    </div>
                </template>
                <template #cell-potential="{ value }">
                    <StatusBadge v-if="value" :status="POTENTIAL_STATUS[value] ?? 'neutral'" :label="POTENTIAL_LABELS[value] ?? value" />
                    <span v-else class="text-cap-text-soft">—</span>
                </template>
                <template #cell-status="{ value }"><StatusBadge :value="value" /></template>
                <template #actions="{ row }"><DropdownMenu :label="`Acciones de ${row.name}`" :items="actionsOf(row)" /></template>
                <template #empty-action><AppButton v-if="can.create" variant="primary" icon="plus" @click="editing = {}">Nueva institución</AppButton></template>
            </DataTable>
            <Pagination :paginator="institutions" :only="['institutions', 'filters']" />
        </div>

        <InstitutionFormModal :institution="editing" :options="options" @close="editing = null" />
    </AppLayout>
</template>
