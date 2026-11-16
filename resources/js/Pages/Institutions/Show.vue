<script setup>
// Ficha de una institución: datos, contactos y lo que la empresa activa tiene con ella
// (licitaciones, cotizaciones y contratos).
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppButton from '@/Components/AppButton.vue';
import DataTable from '@/Components/DataTable.vue';
import DescriptionList from '@/Components/DescriptionList.vue';
import DropdownMenu from '@/Components/DropdownMenu.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Tabs from '@/Components/Tabs.vue';
import { useConfirm } from '@/composables/useConfirm.js';
import AppLayout from '@/Layouts/AppLayout.vue';
import { date, dateTime, money } from '@/lib/format.js';
import { visit } from '@/lib/http.js';
import { card, tableLink } from '@/lib/ui.js';
import ContactFormModal from '@/Pages/Contacts/Partials/ContactFormModal.vue';
import InstitutionFormModal from '@/Pages/Institutions/Partials/InstitutionFormModal.vue';

const props = defineProps({
    institution: { type: Object, required: true },
    contacts: { type: Array, default: () => [] },
    tenders: { type: Array, default: () => [] },
    quotes: { type: Array, default: () => [] },
    contracts: { type: Array, default: () => [] },
    options: { type: Object, default: () => ({ types: [], potentials: [], statuses: [] }) },
    can: { type: Object, default: () => ({}) },
});

const tab = ref('contactos');
const editing = ref(null);
const editingContact = ref(null);
const confirm = useConfirm();

const POTENTIAL_LABELS = { ALTO: 'Alto', MEDIO: 'Medio', BAJO: 'Bajo' };

const tabs = computed(() => [
    { value: 'contactos', label: 'Contactos', count: props.contacts.length },
    { value: 'datos', label: 'Datos' },
    { value: 'licitaciones', label: 'Licitaciones', count: props.tenders.length },
    { value: 'cotizaciones', label: 'Cotizaciones', count: props.quotes.length },
    { value: 'contratos', label: 'Contratos', count: props.contracts.length },
]);

const details = computed(() => [
    { label: 'Nombre', value: props.institution.name, wide: true },
    { label: 'Siglas', value: props.institution.acronym },
    { label: 'Tipo', value: props.institution.type },
    { label: 'Potencial', value: POTENTIAL_LABELS[props.institution.potential] ?? props.institution.potential },
    { key: 'status', label: 'Estado', value: props.institution.status },
    { label: 'Ubicación', value: props.institution.location },
    { label: 'Contacto general', value: props.institution.contact_name },
    { label: 'Teléfono', value: props.institution.phone },
    { label: 'Correo', value: props.institution.email },
    { label: 'Notas', value: props.institution.notes, wide: true },
]);

const contactColumns = [
    { key: 'name', label: 'Nombre', primary: true, wrap: true },
    { key: 'position', label: 'Cargo', wrap: true },
    { key: 'email', label: 'Correo', hideOnMobile: true },
    { key: 'phone', label: 'Teléfono' },
];

const tenderColumns = [
    { key: 'reference', label: 'Expediente', primary: true },
    { key: 'title', label: 'Objeto', wrap: true },
    { key: 'presentation_deadline', label: 'Recepción de ofertas', format: (value) => dateTime(value) },
    { key: 'status', label: 'Estado' },
];

const quoteColumns = [
    { key: 'number', label: 'Cotización', primary: true },
    { key: 'quoted_at', label: 'Fecha', format: (value) => date(value) },
    { key: 'total', label: 'Total', align: 'right', format: (value) => money(value) },
    { key: 'outcome', label: 'Resultado' },
];

const contractColumns = [
    { key: 'number', label: 'Contrato', primary: true },
    { key: 'name', label: 'Objeto', wrap: true, hideOnMobile: true },
    { key: 'amount', label: 'Monto', align: 'right', format: (value) => money(value) },
    { key: 'status', label: 'Estado' },
];

const institutionRef = computed(() => ({ id: props.institution.id, name: props.institution.name }));

function newContact() {
    editingContact.value = { institution_id: props.institution.id, institution: institutionRef.value };
}

function contactActions(row) {
    const items = [];
    if (props.can.create_contact) items.push({ label: 'Editar', icon: 'pencil-outline', onSelect: () => (editingContact.value = { ...row, institution: institutionRef.value }) });
    if (row.email) items.push({ label: 'Escribir correo', icon: 'email-outline', href: `mailto:${row.email}`, external: true });
    if (props.can.delete) items.push({ label: 'Eliminar', icon: 'trash-can-outline', danger: true, separator: true, onSelect: () => removeContact(row) });
    return items;
}

function removeContact(row) {
    confirm({
        verb: 'Eliminar',
        object: `el contacto ${row.name}`,
        noun: 'contacto',
        message: 'Se quitará del directorio de la institución. No se puede deshacer.',
        icon: 'trash-can-outline',
        action: () => visit('delete', `/contactos/${row.id}`, {}, { only: ['contacts', 'flash'] }),
    });
}

function remove() {
    confirm({
        verb: 'Eliminar',
        object: `la institución ${props.institution.name}`,
        noun: 'institución',
        message: 'Se borra del catálogo de todas las empresas junto con sus contactos.',
        icon: 'trash-can-outline',
        action: () => visit('delete', `/instituciones/${props.institution.id}`),
    });
}
</script>

<template>
    <AppLayout :title="institution.acronym || institution.name" back-href="/instituciones" :breadcrumbs="[{ label: 'Instituciones', href: '/instituciones' }, { label: institution.acronym || institution.name }]">
        <PageHeader>
            <div class="flex min-w-0 flex-wrap items-center gap-2 text-sm text-cap-text-soft">
                <StatusBadge :value="institution.status" />
                <span class="min-w-0 break-words">{{ institution.name }}</span>
            </div>
            <template #actions>
                <AppButton v-if="can.delete" variant="danger" icon="trash-can-outline" @click="remove">Eliminar</AppButton>
                <AppButton v-if="can.update" icon="pencil-outline" @click="editing = institution">Editar</AppButton>
                <AppButton v-if="can.create_contact && (contacts.length || tab !== 'contactos')" variant="primary" icon="account-plus-outline" @click="newContact">Nuevo contacto</AppButton>
            </template>
        </PageHeader>

        <Tabs v-model="tab" :tabs="tabs" label="Secciones de la institución">
            <template #contactos>
                <DataTable :columns="contactColumns" :rows="contacts" caption="Contactos" empty-icon="card-account-phone-outline" empty-title="Sin contactos" empty-description="Agregue a las personas con quienes trata en esta institución.">
                    <template #cell-email="{ value }"><a v-if="value" :href="`mailto:${value}`" :class="tableLink">{{ value }}</a><span v-else class="text-cap-text-soft">—</span></template>
                    <template #actions="{ row }"><DropdownMenu v-if="contactActions(row).length" :label="`Acciones de ${row.name}`" :items="contactActions(row)" /></template>
                    <template #empty-action><AppButton v-if="can.create_contact" variant="primary" icon="account-plus-outline" @click="newContact">Nuevo contacto</AppButton></template>
                </DataTable>
            </template>
            <template #datos>
                <section :class="[card, 'p-4 sm:p-6']">
                    <DescriptionList :items="details">
                        <template #status="{ item }"><StatusBadge :value="item.value" /></template>
                    </DescriptionList>
                </section>
            </template>
            <template #licitaciones>
                <DataTable :columns="tenderColumns" :rows="tenders" caption="Licitaciones" empty-icon="gavel" empty-title="Sin licitaciones" empty-description="La empresa activa no tiene licitaciones con esta institución.">
                    <template #cell-reference="{ row }"><Link :href="`/licitaciones?search=${encodeURIComponent(row.reference)}`" :class="tableLink">{{ row.reference }}</Link></template>
                    <template #cell-status="{ value }"><StatusBadge :value="value" /></template>
                </DataTable>
            </template>
            <template #cotizaciones>
                <DataTable :columns="quoteColumns" :rows="quotes" caption="Cotizaciones" empty-icon="file-document-outline" empty-title="Sin cotizaciones" empty-description="La empresa activa no ha cotizado a esta institución.">
                    <template #cell-number="{ row }"><Link :href="`/cotizaciones?search=${encodeURIComponent(row.number)}`" :class="tableLink">{{ row.number }}</Link></template>
                    <template #cell-outcome="{ value }"><StatusBadge :value="value" /></template>
                </DataTable>
            </template>
            <template #contratos>
                <DataTable :columns="contractColumns" :rows="contracts" caption="Contratos" empty-icon="file-sign" empty-title="Sin contratos" empty-description="La empresa activa no tiene contratos con esta institución.">
                    <template #cell-status="{ value }"><StatusBadge :value="value" /></template>
                </DataTable>
            </template>
        </Tabs>

        <InstitutionFormModal :institution="editing" :options="options" @close="editing = null" />
        <ContactFormModal :contact="editingContact" lock-institution @close="editingContact = null" />
    </AppLayout>
</template>
