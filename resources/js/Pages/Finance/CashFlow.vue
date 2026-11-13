<script setup>
// Flujo de caja de la empresa activa: cobrado, ingresos manuales y proyección por cobrar por mes,
// próximas facturas por vencer e ingresos manuales (alta, edición y eliminación).
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppButton from '../../Components/AppButton.vue';
import BaseModal from '../../Components/BaseModal.vue';
import DataTable from '../../Components/DataTable.vue';
import DateInput from '../../Components/DateInput.vue';
import DropdownMenu from '../../Components/DropdownMenu.vue';
import EmptyState from '../../Components/EmptyState.vue';
import FormField from '../../Components/FormField.vue';
import MoneyInput from '../../Components/MoneyInput.vue';
import PageHeader from '../../Components/PageHeader.vue';
import Pagination from '../../Components/Pagination.vue';
import StatCard from '../../Components/StatCard.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import TextArea from '../../Components/TextArea.vue';
import TextInput from '../../Components/TextInput.vue';
import Toolbar from '../../Components/Toolbar.vue';
import { useConfirm } from '../../composables/useConfirm.js';
import AppLayout from '../../Layouts/AppLayout.vue';
import { focusFirstError } from '../../lib/errors.js';
import { date, money, number, relativeDays } from '../../lib/format.js';
import { visit } from '../../lib/http.js';
import { textLink } from '../../lib/ui.js';
import BarChart from './Partials/BarChart.vue';
import ChartCard from './Partials/ChartCard.vue';
import LoadError from './Partials/LoadError.vue';
import { FINANCE_SECTIONS } from './Partials/sections.js';
import { monthLabel, useServerList } from './Partials/finance.js';

const props = defineProps({
    records: { type: Object, required: true },
    filters: { type: Object, required: true },
    totals: { type: Object, required: true },
    chart: { type: Array, default: () => [] },
    pendingInvoices: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
});

const confirm = useConfirm();
const list = useServerList('/flujo-caja', props.filters, { only: ['records', 'filters'], defaults: { search: '' } });
watch(() => list.filters.search, () => list.reload());

const rows = computed(() => props.records.data ?? []);
const currentMonth = new Date().toLocaleDateString('en-CA', { timeZone: 'America/Tegucigalpa' }).slice(0, 7);
const categories = computed(() => props.chart.map((month) => monthLabel(month.month, true)));
const highlight = computed(() => props.chart.findIndex((month) => month.month === currentMonth));
const series = computed(() => [
    { key: 'collected', label: 'Cobrado', values: props.chart.map((month) => month.collected) },
    { key: 'manual', label: 'Ingresos manuales', values: props.chart.map((month) => month.manual) },
    { key: 'projected', label: 'Por cobrar (vencimiento)', values: props.chart.map((month) => month.projected) },
]);
const chartEmpty = computed(() => props.chart.every((month) => !month.collected && !month.manual && !month.projected));

const columns = [
    { key: 'received_at', label: 'Fecha', width: 'w-32', format: (value) => date(value) },
    { key: 'concept', label: 'Concepto', primary: true },
    { key: 'user', label: 'Registró', hideOnMobile: true },
    { key: 'amount', label: 'Monto (L)', align: 'right', format: (value) => money(value, 'HNL', { symbol: false }) },
];

// --- Alta y edición ---------------------------------------------------------------------------
const today = new Date().toLocaleDateString('en-CA', { timeZone: 'America/Tegucigalpa' });
const formOpen = ref(false);
const editing = ref(null);
const formRoot = ref(null);
const blank = () => ({ concept: '', amount: null, received_at: today, notes: '' });
const form = useForm(blank());

function openForm(row = null) {
    editing.value = row;
    form.defaults(row ? { concept: row.concept, amount: row.amount, received_at: row.received_at, notes: row.notes ?? '' } : blank());
    form.reset();
    form.clearErrors();
    formOpen.value = true;
}

function save() {
    const options = { preserveScroll: true, onSuccess: () => (formOpen.value = false), onError: () => focusFirstError(formRoot.value ?? document) };
    if (editing.value) form.put(`/flujo-caja/${editing.value.id}`, options);
    else form.post('/flujo-caja', options);
}

function remove(row) {
    confirm({
        verb: 'Eliminar',
        object: `el ingreso «${row.concept}»`,
        noun: 'ingreso',
        message: `Se quitarán ${money(row.amount)} del flujo de caja. No se puede deshacer.`,
        action: () => visit('delete', `/flujo-caja/${row.id}`),
    });
}

function actionsOf(row) {
    const items = [];
    if (props.can.update) items.push({ label: 'Editar', icon: 'pencil-outline', onSelect: () => openForm(row) });
    if (props.can.delete) items.push({ label: 'Eliminar', icon: 'trash-can-outline', danger: true, separator: items.length > 0, onSelect: () => remove(row) });
    return items;
}
</script>

<template>
    <AppLayout title="Flujo de caja" :sections="FINANCE_SECTIONS('/flujo-caja')">
        <PageHeader description="Entradas de dinero de la empresa activa y lo que falta cobrar">
            <template #actions>
                <AppButton v-if="can.create && totals.manual > 0" variant="primary" icon="plus" @click="openForm()">Registrar ingreso</AppButton>
            </template>
        </PageHeader>

        <div class="cap-stagger grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard variant="featured" label="Entradas este mes" :value="money(totals.received_month + totals.manual_month)" :hint="`Cobrado ${money(totals.received_month)} · manual ${money(totals.manual_month)}`" />
            <StatCard label="Cobrado acumulado" :value="money(totals.received)" hint="Facturas pagadas" href="/cobros" />
            <StatCard label="Por cobrar" :value="money(totals.pending)" :hint="`${number(totals.pending_count)} facturas pendientes`" href="/cobros" />
            <StatCard :variant="totals.overdue > 0 ? 'alert' : 'normal'" label="Vencido sin cobrar" :value="money(totals.overdue)" hint="Cae en la proyección del mes actual" />
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
            <ChartCard class="xl:col-span-2" title="Flujo por mes" description="Últimos meses, el actual resaltado y la proyección de cobro por vencimiento." :empty="chartEmpty" empty-text="Aún no hay cobros, ingresos ni facturas por cobrar en estos meses.">
                <BarChart :categories="categories" :series="series" :highlight="highlight" title="Flujo de caja por mes" />
            </ChartCard>

            <section class="flex flex-col gap-3 rounded-cap-card border border-cap-divider bg-cap-card p-4 sm:p-5">
                <header class="flex items-center justify-between gap-2">
                    <h2 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Próximos cobros</h2>
                    <Link href="/cobros" :class="['text-sm', textLink]">Ver cartera</Link>
                </header>
                <EmptyState v-if="pendingInvoices.length === 0" compact icon="cash-check" title="Sin facturas pendientes" description="Toda la cartera registrada está cobrada." />
                <ul v-else class="flex flex-col divide-y divide-cap-divider">
                    <li v-for="invoice in pendingInvoices" :key="invoice.id" class="flex items-center justify-between gap-3 py-2.5">
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-medium text-gray-900 dark:text-gray-100" :title="invoice.concept">{{ invoice.number }}</span>
                            <span class="block text-xs text-cap-text-soft">{{ date(invoice.due_at) }} · {{ relativeDays(invoice.due_at) }}</span>
                        </span>
                        <span class="flex shrink-0 flex-col items-end gap-1">
                            <span class="text-sm font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ money(invoice.amount) }}</span>
                            <StatusBadge :value="invoice.display_status" />
                        </span>
                    </li>
                </ul>
            </section>
        </div>

        <section class="flex flex-col gap-3">
            <h2 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Ingresos manuales</h2>
            <Toolbar v-model:search="list.filters.search" search-placeholder="Buscar concepto o nota…" />
            <LoadError v-if="list.error.value" title="No se pudieron actualizar los ingresos" :message="list.error.value" @retry="list.reload()" />
            <div class="flex flex-col">
                <DataTable
                    :columns="columns"
                    :rows="rows"
                    :loading="list.loading.value"
                    :filtered="Boolean(list.filters.search)"
                    caption="Ingresos manuales"
                    empty-icon="cash-plus"
                    empty-title="Aún no hay ingresos manuales"
                    empty-description="Registra aquí entradas de dinero que no vienen de una factura de cobro."
                    @clear-filters="list.reset({ search: '' })"
                >
                    <template #actions="{ row }">
                        <DropdownMenu v-if="actionsOf(row).length" :label="`Acciones del ingreso ${row.concept}`" :items="actionsOf(row)" />
                    </template>
                    <template v-if="can.create" #empty-action><AppButton variant="primary" icon="plus" @click="openForm()">Registrar ingreso</AppButton></template>
                </DataTable>
                <Pagination :paginator="records" :only="['records', 'filters']" />
            </div>
        </section>

        <BaseModal v-model:open="formOpen" as="form" size="md" :title="editing ? 'Editar ingreso' : 'Registrar ingreso'" :persistent="form.processing" @submit="save">
            <div ref="formRoot" class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
                <FormField label="Concepto" required :error="form.errors.concept" class="md:col-span-2">
                    <TextInput v-model="form.concept" maxlength="255" placeholder="Anticipo de contrato" />
                </FormField>
                <FormField label="Monto" required :error="form.errors.amount">
                    <MoneyInput v-model="form.amount" :min="0" class="w-full" />
                </FormField>
                <FormField label="Fecha de recepción" required :error="form.errors.received_at">
                    <DateInput v-model="form.received_at" />
                </FormField>
                <FormField label="Notas" optional :error="form.errors.notes" class="md:col-span-2">
                    <TextArea v-model="form.notes" :rows="3" maxlength="5000" />
                </FormField>
            </div>
            <template #footer>
                <AppButton :disabled="form.processing" @click="formOpen = false">Cancelar</AppButton>
                <AppButton variant="primary" type="submit" icon="content-save-outline" :loading="form.processing">{{ editing ? 'Guardar cambios' : 'Registrar ingreso' }}</AppButton>
            </template>
        </BaseModal>
    </AppLayout>
</template>
