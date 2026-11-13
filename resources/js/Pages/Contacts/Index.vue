<script setup>
// Contactos de las instituciones (catálogo compartido del grupo).
import { Link } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AppButton from '../../Components/AppButton.vue';
import DataTable from '../../Components/DataTable.vue';
import DropdownMenu from '../../Components/DropdownMenu.vue';
import PageHeader from '../../Components/PageHeader.vue';
import Pagination from '../../Components/Pagination.vue';
import SearchSelect from '../../Components/SearchSelect.vue';
import Toolbar from '../../Components/Toolbar.vue';
import { useConfirm } from '../../composables/useConfirm.js';
import AppLayout from '../../Layouts/AppLayout.vue';
import { number } from '../../lib/format.js';
import { visit } from '../../lib/http.js';
import { tableLink } from '../../lib/ui.js';
import { useListFilters } from '../Admin/Partials/useListFilters.js';
import ContactFormModal from './Partials/ContactFormModal.vue';

const props = defineProps({
    contacts: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    institution: { type: Object, default: null },
    can: { type: Object, default: () => ({}) },
});

const { filters, loading, error, active, reset, reload } = useListFilters('/contactos', props.filters, {
    only: ['contacts', 'filters', 'institution'],
    defaults: { search: '', institution_id: null },
});

const confirm = useConfirm();
const editing = ref(null);
const selectedInstitution = ref(props.institution);
watch(() => props.institution, (value) => (selectedInstitution.value = value));

const columns = [
    { key: 'name', label: 'Nombre', primary: true, wrap: true },
    { key: 'institution', label: 'Institución', wrap: true },
    { key: 'email', label: 'Correo', hideOnMobile: true },
    { key: 'phone', label: 'Teléfono' },
];

function create() {
    editing.value = selectedInstitution.value ? { institution_id: selectedInstitution.value.id, institution: selectedInstitution.value } : {};
}

function actionsOf(row) {
    const items = [];
    if (props.can.update) items.push({ label: 'Editar', icon: 'pencil-outline', onSelect: () => (editing.value = row) });
    if (row.email) items.push({ label: 'Escribir correo', icon: 'email-outline', href: `mailto:${row.email}`, external: true });
    if (props.can.delete) items.push({ label: 'Eliminar', icon: 'trash-can-outline', danger: true, separator: true, onSelect: () => remove(row) });
    return items;
}

function remove(row) {
    confirm({
        verb: 'Eliminar',
        object: `el contacto ${row.name}`,
        noun: 'contacto',
        message: 'Se quitará del directorio de la institución. No se puede deshacer.',
        icon: 'trash-can-outline',
        action: () => visit('delete', `/contactos/${row.id}`, {}, { only: ['contacts', 'flash'] }),
    });
}
</script>

<template>
    <AppLayout
        title="Contactos"
        :sections="[{ label: 'Instituciones', href: '/instituciones' }, { label: 'Contactos', href: '/contactos', active: true }]"
        :fab-label="can.create ? 'Nuevo contacto' : ''"
        @fab="create"
    >
        <PageHeader :description="`${number(contacts.total)} ${contacts.total === 1 ? 'contacto' : 'contactos'} de instituciones compradoras`">
            <template #actions>
                <AppButton v-if="can.create && contacts.total" class="max-lg:hidden" variant="primary" icon="plus" @click="create">Nuevo contacto</AppButton>
            </template>
        </PageHeader>

        <Toolbar v-model:search="filters.search" search-placeholder="Buscar contacto o correo">
            <template #filters>
                <SearchSelect
                    v-model="filters.institution_id"
                    class="sm:w-72"
                    url="/instituciones/buscar"
                    :params="{ todas: 1 }"
                    option-value="id"
                    option-label="name"
                    option-description="description"
                    :selected="selectedInstitution"
                    :min-chars="1"
                    placeholder="Filtrar por institución…"
                    aria-label="Filtrar por institución"
                    @select="(option) => (selectedInstitution = option)"
                />
            </template>
        </Toolbar>

        <div>
            <DataTable
                :columns="columns"
                :rows="contacts.data"
                :loading="loading"
                :error="error"
                :filtered="active"
                caption="Contactos"
                empty-icon="card-account-phone-outline"
                empty-title="Aún no hay contactos"
                empty-description="Registre a las personas con quienes trata en cada institución."
                @retry="reload"
                @clear-filters="reset"
            >
                <template #cell-name="{ row }">
                    <div class="min-w-0">
                        <p class="font-medium text-gray-900 dark:text-gray-100">{{ row.name }}</p>
                        <p v-if="row.position || row.department" class="text-xs text-cap-text-soft">{{ [row.position, row.department].filter(Boolean).join(' · ') }}</p>
                    </div>
                </template>
                <template #cell-institution="{ row }">
                    <Link v-if="row.institution" :href="`/instituciones/${row.institution.id}`" :class="tableLink">{{ row.institution.acronym || row.institution.name }}</Link>
                </template>
                <template #cell-email="{ value }"><a v-if="value" :href="`mailto:${value}`" :class="tableLink">{{ value }}</a><span v-else class="text-cap-text-soft">—</span></template>
                <template #cell-phone="{ row }">{{ row.phone || row.whatsapp || '—' }}</template>
                <template #actions="{ row }"><DropdownMenu v-if="actionsOf(row).length" :label="`Acciones de ${row.name}`" :items="actionsOf(row)" /></template>
                <template #empty-action><AppButton v-if="can.create" variant="primary" icon="plus" @click="create">Nuevo contacto</AppButton></template>
            </DataTable>
            <Pagination :paginator="contacts" :only="['contacts', 'filters']" />
        </div>

        <ContactFormModal :contact="editing" @close="editing = null" />
    </AppLayout>
</template>
