<script setup>
// Presupuesto revolvente de la empresa activa: saldo, evolución del saldo, movimientos paginados en el
// servidor, registro manual, monto inicial, sincronización con cotizaciones, licitaciones y cobros, y exportes.
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppButton from '@/Components/AppButton.vue';
import BaseModal from '@/Components/BaseModal.vue';
import DataTable from '@/Components/DataTable.vue';
import DateInput from '@/Components/DateInput.vue';
import DropdownMenu from '@/Components/DropdownMenu.vue';
import FormField from '@/Components/FormField.vue';
import MoneyInput from '@/Components/MoneyInput.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TextInput from '@/Components/TextInput.vue';
import Toolbar from '@/Components/Toolbar.vue';
import CapSegmented from '@design/vue/CapSegmented.vue';
import { useConfirm } from '@/composables/useConfirm.js';
import AppLayout from '@/Layouts/AppLayout.vue';
import { focusFirstError } from '@/lib/errors.js';
import { date, money, number } from '@/lib/format.js';
import { visit } from '@/lib/http.js';
import ChartCard from '@/Pages/Finance/Partials/ChartCard.vue';
import LineChart from '@/Pages/Finance/Partials/LineChart.vue';
import LoadError from '@/Pages/Finance/Partials/LoadError.vue';
import { FINANCE_SECTIONS } from '@/Pages/Finance/Partials/sections.js';
import { cleanQuery, useServerList } from '@/Pages/Finance/Partials/finance.js';

const props = defineProps({
    budget: { type: Object, required: true },
    records: { type: Object, required: true },
    filters: { type: Object, required: true },
    stats: { type: Object, required: true },
    chart: { type: Array, default: () => [] },
    types: { type: Array, default: () => ['Ingreso', 'Egreso'] },
    can: { type: Object, default: () => ({}) },
});

const confirm = useConfirm();
const list = useServerList('/presupuesto-revolvente', props.filters, { only: ['records', 'filters'], defaults: { filter: 'all', search: '' } });
watch(() => [list.filters.filter, list.filters.search], () => list.reload());

const rows = computed(() => props.records.data ?? []);
const filtered = computed(() => list.filters.filter !== 'all' || Boolean(list.filters.search));
const exportQuery = computed(() => new URLSearchParams(cleanQuery({ ...props.filters }, { filter: 'all', search: '' })).toString());
const points = computed(() => props.chart.map((point) => ({ label: date(point.date, 'medium').replace(/ \d{4}$/, ''), value: point.balance })));

const segments = [
    { label: 'Todos', value: 'all' },
    { label: 'Ingresos', value: 'income' },
    { label: 'Egresos', value: 'expense' },
    { label: 'Sincronizados', value: 'synced' },
];

const exportItems = computed(() => [
    { label: 'Descargar PDF', icon: 'file-pdf-box', href: `/presupuesto-revolvente/export/pdf?${exportQuery.value}`, external: true },
    { label: 'Descargar Excel (XLSX)', icon: 'file-excel-outline', href: `/presupuesto-revolvente/export/xlsx?${exportQuery.value}`, external: true },
]);

const columns = [
    { key: 'moved_at', label: 'Fecha', width: 'w-32', format: (value) => date(value) },
    { key: 'concept', label: 'Concepto', primary: true },
    { key: 'type', label: 'Tipo', width: 'w-44' },
    { key: 'reference', label: 'Referencia', hideOnMobile: true },
    { key: 'amount', label: 'Monto (L)', align: 'right', format: (value, row) => money(row.is_expense ? -value : value, 'HNL', { symbol: false }) },
    { key: 'balance_after', label: 'Saldo (L)', align: 'right', format: (value) => money(value, 'HNL', { symbol: false }) },
];

const typeStatus = (row) => (row.is_expense ? 'error' : row.type === 'Ingreso proyectado' ? 'info' : 'ok');

// --- Movimiento manual ------------------------------------------------------------------------
const today = new Date().toLocaleDateString('en-CA', { timeZone: 'America/Tegucigalpa' });
const movementOpen = ref(false);
const formRoot = ref(null);
const form = useForm({ moved_at: today, type: 'Egreso', concept: '', amount: null, reference: '' });
const typeItems = computed(() => props.types.map((type) => ({ label: type, value: type })));

function openMovement() {
    form.defaults({ moved_at: today, type: 'Egreso', concept: '', amount: null, reference: '' });
    form.reset();
    form.clearErrors();
    movementOpen.value = true;
}

function saveMovement() {
    form.post('/presupuesto-revolvente', { preserveScroll: true, onSuccess: () => (movementOpen.value = false), onError: () => focusFirstError(formRoot.value ?? document) });
}

// --- Monto inicial ----------------------------------------------------------------------------
const initialOpen = ref(false);
const initialForm = useForm({ initial_amount: props.budget.initial_amount });

function openInitial() {
    initialForm.defaults({ initial_amount: props.budget.initial_amount });
    initialForm.reset();
    initialForm.clearErrors();
    initialOpen.value = true;
}

function saveInitial() {
    initialForm.post('/presupuesto-revolvente/inicializar', { preserveScroll: true, onSuccess: () => (initialOpen.value = false) });
}

// --- Sincronizar y eliminar -------------------------------------------------------------------
const syncing = ref(false);

function sync() {
    router.post('/presupuesto-revolvente/sincronizar', {}, { preserveScroll: true, onStart: () => (syncing.value = true), onFinish: () => (syncing.value = false) });
}

function remove(row) {
    confirm({
        verb: 'Eliminar',
        object: `el movimiento «${row.concept}»`,
        noun: 'movimiento',
        message: `Se quitará el ${row.is_expense ? 'egreso' : 'ingreso'} de ${money(row.amount)} y se recalculará el saldo. No se puede deshacer.`,
        action: () => visit('delete', `/presupuesto-revolvente/${row.id}`),
    });
}

const actionsOf = (row) => [
    { label: 'Eliminar movimiento', icon: 'trash-can-outline', danger: true, onSelect: () => remove(row) },
];
</script>

<template>
    <AppLayout title="Presupuesto revolvente" :sections="FINANCE_SECTIONS('/presupuesto-revolvente')">
        <PageHeader description="Saldo del fondo revolvente de la empresa activa">
            <template #actions>
                <AppButton v-if="can.create" icon="sync" :loading="syncing" @click="sync">Sincronizar fuentes</AppButton>
                <AppButton v-if="can.create" icon="cash-edit" @click="openInitial">Monto inicial</AppButton>
                <DropdownMenu v-if="can.export" :items="exportItems" label="Exportar presupuesto" width="w-60">
                    <template #trigger="{ toggle, attrs }">
                        <button type="button" class="cap-button cap-button--secondary" v-bind="attrs" @click="toggle">
                            <i class="mdi mdi-download-outline text-xl leading-none" aria-hidden="true" />Exportar
                        </button>
                    </template>
                </DropdownMenu>
                <AppButton v-if="can.create && stats.count > 0" variant="primary" icon="plus" @click="openMovement">Registrar movimiento</AppButton>
            </template>
        </PageHeader>

        <div class="cap-stagger grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard variant="featured" label="Saldo actual" :value="money(budget.current_balance)" :hint="`Monto inicial ${money(budget.initial_amount)}`" />
            <StatCard label="Ingresos" :value="money(stats.income)" hint="Registrados y cobros sincronizados" />
            <StatCard label="Ingresos proyectados" :value="money(stats.projected)" hint="Licitaciones adjudicadas" />
            <StatCard :variant="budget.current_balance < 0 ? 'alert' : 'normal'" label="Egresos" :value="money(stats.expense)" :hint="`${number(stats.count)} movimientos · ${number(stats.synced)} sincronizados`" />
        </div>

        <ChartCard title="Evolución del saldo" description="Saldo al cierre de cada día con movimientos." :empty="points.length < 2" empty-text="Hacen falta movimientos en al menos dos fechas para ver la evolución.">
            <LineChart :points="points" title="Evolución del saldo del presupuesto revolvente" />
        </ChartCard>

        <Toolbar v-model:search="list.filters.search" v-model:filter="list.filters.filter" :filters="segments" filter-label="Filtrar movimientos" search-placeholder="Buscar concepto o referencia…" />

        <LoadError v-if="list.error.value" title="No se pudieron actualizar los movimientos" :message="list.error.value" @retry="list.reload()" />

        <div class="flex flex-col">
            <DataTable
                :columns="columns"
                :rows="rows"
                :loading="list.loading.value"
                :filtered="filtered"
                caption="Movimientos del presupuesto revolvente"
                empty-icon="cash-sync"
                empty-title="Aún no hay movimientos"
                empty-description="Registra un movimiento o sincroniza las cotizaciones ganadas, licitaciones adjudicadas y cobros."
                @clear-filters="list.reset({ filter: 'all', search: '' })"
            >
                <template #cell-type="{ row }">
                    <StatusBadge :status="typeStatus(row)" :label="row.type" />
                    <span v-if="row.synchronized" class="mt-0.5 block text-xs text-cap-text-soft">{{ row.source ?? 'Sincronizado' }}</span>
                </template>
                <template #actions="{ row }">
                    <DropdownMenu v-if="can.delete && !row.synchronized" :label="`Acciones del movimiento ${row.concept}`" :items="actionsOf(row)" />
                </template>
                <template v-if="can.create" #empty-action><AppButton variant="primary" icon="plus" @click="openMovement">Registrar movimiento</AppButton></template>
            </DataTable>
            <Pagination :paginator="records" :only="['records', 'filters']" />
        </div>

        <BaseModal v-model:open="movementOpen" as="form" size="md" title="Registrar movimiento" :persistent="form.processing" @submit="saveMovement">
            <div ref="formRoot" class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
                <FormField label="Tipo" required :error="form.errors.type" class="md:col-span-2">
                    <CapSegmented v-model="form.type" :items="typeItems" label="Tipo de movimiento" />
                </FormField>
                <FormField label="Fecha" required :error="form.errors.moved_at">
                    <DateInput v-model="form.moved_at" />
                </FormField>
                <FormField label="Monto" required :error="form.errors.amount">
                    <MoneyInput v-model="form.amount" :min="0" class="w-full" />
                </FormField>
                <FormField label="Concepto" required :error="form.errors.concept" class="md:col-span-2">
                    <TextInput v-model="form.concept" maxlength="255" placeholder="Compra de materiales para entrega" />
                </FormField>
                <FormField label="Referencia" optional help="Número de cheque, transferencia o documento. Único en la empresa." :error="form.errors.reference" class="md:col-span-2">
                    <TextInput v-model="form.reference" maxlength="100" />
                </FormField>
            </div>
            <template #footer>
                <AppButton :disabled="form.processing" @click="movementOpen = false">Cancelar</AppButton>
                <AppButton variant="primary" type="submit" icon="content-save-outline" :loading="form.processing">Registrar movimiento</AppButton>
            </template>
        </BaseModal>

        <BaseModal v-model:open="initialOpen" as="form" size="sm" title="Monto inicial del presupuesto" description="El saldo se recalcula desde este monto sumando y restando todos los movimientos." :persistent="initialForm.processing" @submit="saveInitial">
            <FormField label="Monto inicial" required :error="initialForm.errors.initial_amount">
                <MoneyInput v-model="initialForm.initial_amount" :min="0" class="w-full" />
            </FormField>
            <template #footer>
                <AppButton :disabled="initialForm.processing" @click="initialOpen = false">Cancelar</AppButton>
                <AppButton variant="primary" type="submit" icon="content-save-outline" :loading="initialForm.processing">Guardar monto</AppButton>
            </template>
        </BaseModal>
    </AppLayout>
</template>
