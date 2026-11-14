<script setup>
// Ficha de un cliente: datos y contacto, ofertas ganadas, contratos y línea de tiempo.
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppButton from '../../Components/AppButton.vue';
import DataTable from '../../Components/DataTable.vue';
import DescriptionList from '../../Components/DescriptionList.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatCard from '../../Components/StatCard.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import Tabs from '../../Components/Tabs.vue';
import Timeline from '../../Components/Timeline.vue';
import Tooltip from '../../Components/Tooltip.vue';
import { useConfirm } from '../../composables/useConfirm.js';
import AppLayout from '../../Layouts/AppLayout.vue';
import { date, money, number } from '../../lib/format.js';
import { visit } from '../../lib/http.js';
import { card, tableLink } from '../../lib/ui.js';
import ClientFormModal from './Partials/ClientFormModal.vue';

const props = defineProps({
    client: { type: Object, required: true },
    quotes: { type: Array, default: () => [] },
    contracts: { type: Array, default: () => [] },
    timeline: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
});

const tab = ref('datos');
const editing = ref(null);
const confirm = useConfirm();

const tabs = computed(() => [
    { value: 'datos', label: 'Datos' },
    { value: 'ofertas', label: 'Ofertas ganadas', count: props.quotes.length },
    { value: 'contratos', label: 'Contratos', count: props.contracts.length },
    { value: 'historial', label: 'Línea de tiempo' },
]);

const details = computed(() => [
    { label: 'Nombre', value: props.client.name, wide: true },
    { label: 'RTN', value: props.client.tax_id },
    { label: 'Institución', key: 'institution', value: props.client.institution?.name },
    { label: 'Sector', value: props.client.sector },
    { key: 'status', label: 'Estado', value: props.client.status },
    { label: 'Persona de contacto', value: props.client.contact_name },
    { label: 'Teléfono', value: props.client.phone },
    { label: 'Correo', value: props.client.email },
    { label: 'Registrado', value: date(props.client.created_at, 'medium') },
    { label: 'Dirección', value: props.client.address, wide: true },
    { label: 'Notas', value: props.client.notes, wide: true },
]);

const timelineItems = computed(() =>
    props.timeline.map((item) => ({
        id: item.id,
        title: item.title,
        meta: [date(item.date, 'medium'), item.amount !== null ? money(item.amount) : null].filter(Boolean).join(' · '),
        icon: item.icon,
        status: item.status ?? undefined,
    })),
);

const quoteColumns = [
    { key: 'number', label: 'Cotización', primary: true },
    { key: 'awarded_at', label: 'Adjudicada', format: (value) => date(value) },
    { key: 'total', label: 'Total cotizado', align: 'right', format: (value) => money(value) },
    { key: 'awarded_amount', label: 'Monto adjudicado', align: 'right', format: (value, row) => money(value ?? row.total) },
];

const contractColumns = [
    { key: 'number', label: 'Contrato', primary: true },
    { key: 'name', label: 'Objeto', wrap: true, hideOnMobile: true },
    { key: 'amount', label: 'Monto', align: 'right', format: (value) => money(value) },
    { key: 'end_date', label: 'Fin', format: (value) => date(value) },
    { key: 'status', label: 'Estado' },
];

function remove() {
    confirm({
        verb: 'Eliminar',
        object: `el cliente ${props.client.name}`,
        noun: 'cliente',
        message: 'Se quitará de la base de clientes. Sus cotizaciones ganadas se conservan.',
        icon: 'trash-can-outline',
        action: () => visit('delete', `/clientes/${props.client.id}`),
    });
}
</script>

<template>
    <AppLayout :title="client.name" back-href="/clientes" :breadcrumbs="[{ label: 'Clientes', href: '/clientes' }, { label: client.name }]">
        <PageHeader>
            <div class="flex min-w-0 flex-wrap items-center gap-2 text-sm text-cap-text-soft">
                <StatusBadge :value="client.status" />
                <span v-if="client.tax_id">RTN {{ client.tax_id }}</span>
                <span v-if="client.institution && client.institution.name !== client.name">· {{ client.institution.name }}</span>
            </div>
            <template #actions>
                <Tooltip v-if="can.delete_blocked && !can.delete" text="Tiene contratos: no se puede eliminar. Márquelo como inactivo.">
                    <AppButton variant="danger" icon="trash-can-outline" disabled>Eliminar</AppButton>
                </Tooltip>
                <AppButton v-else-if="can.delete" variant="danger" icon="trash-can-outline" @click="remove">Eliminar</AppButton>
                <AppButton v-if="can.update" variant="primary" icon="pencil-outline" @click="editing = client">Editar datos</AppButton>
            </template>
        </PageHeader>

        <div class="cap-stagger grid grid-cols-1 gap-4 sm:grid-cols-3">
            <StatCard variant="featured" label="Monto adjudicado acumulado" :value="money(client.accumulated_amount)" :hint="client.last_purchase_at ? `Último ganado: ${date(client.last_purchase_at, 'medium')}` : ''" />
            <StatCard label="Ofertas ganadas" :count="quotes.length" />
            <StatCard label="Contratos" :count="contracts.length" :hint="contracts.length ? `${number(contracts.filter((c) => !['Cerrado', 'Cancelado'].includes(c.status)).length)} vigentes` : 'Sin contratos'" />
        </div>

        <Tabs v-model="tab" :tabs="tabs" label="Secciones del cliente">
            <template #datos>
                <section :class="[card, 'p-4 sm:p-6']">
                    <DescriptionList :items="details">
                        <template #status="{ item }"><StatusBadge :value="item.value" /></template>
                        <template #institution="{ item }">
                            <Link v-if="client.institution" :href="`/instituciones/${client.institution.id}`" :class="tableLink">{{ item.value }}</Link>
                            <span v-else>—</span>
                        </template>
                    </DescriptionList>
                </section>
            </template>
            <template #ofertas>
                <DataTable :columns="quoteColumns" :rows="quotes" caption="Ofertas ganadas" empty-icon="trophy-outline" empty-title="Sin ofertas ganadas" empty-description="Aquí aparecen las cotizaciones adjudicadas a este cliente.">
                    <template #cell-number="{ row }"><Link :href="`/cotizaciones?search=${encodeURIComponent(row.number)}`" :class="tableLink">{{ row.number }}</Link></template>
                </DataTable>
            </template>
            <template #contratos>
                <DataTable :columns="contractColumns" :rows="contracts" caption="Contratos" empty-icon="file-sign" empty-title="Sin contratos" empty-description="El contrato nace al ganar la oferta y se sigue en Post-adjudicación.">
                    <template #cell-number="{ row }"><Link :href="`/post-adjudicacion?search=${encodeURIComponent(row.number)}`" :class="tableLink">{{ row.number }}</Link></template>
                    <template #cell-status="{ value }"><StatusBadge :value="value" /></template>
                </DataTable>
            </template>
            <template #historial>
                <section :class="[card, 'p-4 sm:p-6']">
                    <Timeline :items="timelineItems" empty-text="Aún no hay movimientos." />
                </section>
            </template>
        </Tabs>

        <ClientFormModal :client="editing" @close="editing = null" />
    </AppLayout>
</template>
