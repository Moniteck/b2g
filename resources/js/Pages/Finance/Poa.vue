<script setup>
// Alertas POA: partidas del Plan Operativo Anual que la empresa sigue en las instituciones. Alerta si la
// fecha estimada está a 30 días o menos (o vencida) o si se marcó para el cierre del año fiscal.
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppButton from '@/Components/AppButton.vue';
import BaseModal from '@/Components/BaseModal.vue';
import Checkbox from '@/Components/Checkbox.vue';
import DataTable from '@/Components/DataTable.vue';
import DateInput from '@/Components/DateInput.vue';
import DescriptionList from '@/Components/DescriptionList.vue';
import DropdownMenu from '@/Components/DropdownMenu.vue';
import FormField from '@/Components/FormField.vue';
import MoneyInput from '@/Components/MoneyInput.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import SelectInput from '@/Components/SelectInput.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TextArea from '@/Components/TextArea.vue';
import TextInput from '@/Components/TextInput.vue';
import Toolbar from '@/Components/Toolbar.vue';
import { useConfirm } from '@/composables/useConfirm.js';
import AppLayout from '@/Layouts/AppLayout.vue';
import { focusFirstError } from '@/lib/errors.js';
import { date, money, number, percent, relativeDays } from '@/lib/format.js';
import { visit } from '@/lib/http.js';
import LoadError from '@/Pages/Finance/Partials/LoadError.vue';
import { useServerList } from '@/Pages/Finance/Partials/finance.js';

const props = defineProps({
    records: { type: Object, required: true },
    filters: { type: Object, required: true },
    stats: { type: Object, required: true },
    years: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    institutions: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
});

const confirm = useConfirm();
const list = useServerList('/alertas-poa', props.filters, { only: ['records', 'filters', 'stats', 'years'], defaults: { filter: 'all', search: '', year: null, quarter: null } });
watch(() => [list.filters.filter, list.filters.search, list.filters.year, list.filters.quarter], () => list.reload());

const rows = computed(() => props.records.data ?? []);
// Estado de la partida → estado semántico del badge.
const POA_STATUS = { Identificada: 'neutral', 'En seguimiento': 'info', Comprometida: 'warning', Ejecutada: 'ok', Descartada: 'neutral' };
const badgeOf = (value) => POA_STATUS[value] ?? 'neutral';
const filtered = computed(() => list.filters.filter !== 'all' || Boolean(list.filters.search) || Boolean(list.filters.year) || Boolean(list.filters.quarter));
const yearOptions = computed(() => props.years.map((year) => ({ value: year, label: String(year) })));
const quarterOptions = [1, 2, 3, 4].map((quarter) => ({ value: quarter, label: `Trimestre ${quarter}` }));

const segments = computed(() => [
    { label: 'Todas', value: 'all' },
    { label: 'Con alerta', value: 'alert', count: props.stats.alerts },
    { label: 'Abiertas', value: 'open' },
    { label: 'Cerradas', value: 'closed' },
]);

const columns = [
    { key: 'project', label: 'Proyecto', primary: true },
    { key: 'institution', label: 'Institución', hideOnMobile: true },
    { key: 'year', label: 'Año / trimestre', width: 'w-32', format: (value, row) => (row.quarter ? `${value} · T${row.quarter}` : String(value)) },
    { key: 'estimated_date', label: 'Fecha estimada', width: 'w-40' },
    { key: 'budgeted_amount', label: 'Presupuesto (L)', align: 'right', format: (value) => money(value, 'HNL', { symbol: false }) },
    { key: 'probability', label: 'Prob.', align: 'right', width: 'w-20', format: (value) => `${number(value)} %` },
    { key: 'status', label: 'Estado', width: 'w-40' },
];

// --- Alta y edición ---------------------------------------------------------------------------
const formOpen = ref(false);
const editing = ref(null);
const formRoot = ref(null);
const blank = () => ({
    institution_id: null, project: '', year: new Date().getFullYear(), partida: '', quarter: null, probability: 50,
    budgeted_amount: null, executed_amount: null, estimated_date: '', annual_close_alert: false, status: 'Identificada', notes: '',
});
const form = useForm(blank());
const statusOptions = computed(() => props.statuses.map((status) => ({ value: status, label: status })));

function openForm(row = null) {
    editing.value = row;
    form.defaults(row ? {
        institution_id: row.institution_id, project: row.project, year: row.year, partida: row.partida ?? '', quarter: row.quarter,
        probability: row.probability, budgeted_amount: row.budgeted_amount, executed_amount: row.executed_amount,
        estimated_date: row.estimated_date ?? '', annual_close_alert: row.annual_close_alert, status: row.status, notes: row.notes ?? '',
    } : blank());
    form.reset();
    form.clearErrors();
    detailOpen.value = false;
    formOpen.value = true;
}

function save() {
    const options = { preserveScroll: true, onSuccess: () => (formOpen.value = false), onError: () => focusFirstError(formRoot.value ?? document) };
    if (editing.value) form.put(`/alertas-poa/${editing.value.id}`, options);
    else form.post('/alertas-poa', options);
}

// --- Ficha, ejecutar y eliminar ---------------------------------------------------------------
const detailOpen = ref(false);
const selected = ref(null);
watch(rows, (current) => {
    if (selected.value) selected.value = current.find((row) => row.id === selected.value.id) ?? selected.value;
});

const detailItems = computed(() => {
    const row = selected.value;
    if (!row) return [];
    return [
        { key: 'status', label: 'Estado', value: row.status },
        { label: 'Institución', value: row.institution },
        { label: 'Año y trimestre', value: row.quarter ? `${row.year} · trimestre ${row.quarter}` : String(row.year) },
        { label: 'Partida', value: row.partida },
        { label: 'Fecha estimada', value: row.estimated_date ? `${date(row.estimated_date)} (${relativeDays(row.estimated_date)})` : null },
        { label: 'Probabilidad', value: `${number(row.probability)} %` },
        { label: 'Presupuestado', value: money(row.budgeted_amount) },
        { label: 'Ejecutado', value: `${money(row.executed_amount)}${row.budgeted_amount ? ` (${percent((row.executed_amount / row.budgeted_amount) * 100)})` : ''}` },
        { label: 'Alerta de cierre anual', value: row.annual_close_alert ? 'Sí' : 'No' },
        { label: 'Notas', value: row.notes, wide: true },
    ];
});

function openDetail(row) {
    selected.value = row;
    detailOpen.value = true;
}

function execute(row) {
    confirm({
        verb: 'Marcar como ejecutada',
        object: `la partida «${row.project}»`,
        confirmLabel: 'Marcar ejecutada',
        icon: 'check-circle-outline',
        message: `El monto ejecutado pasará a ${money(row.budgeted_amount)} (el 100 % de lo presupuestado) y la alerta se apagará.`,
        action: () => visit('patch', `/alertas-poa/${row.id}/ejecutar`),
    });
}

function remove(row) {
    confirm({
        verb: 'Eliminar',
        object: `la partida «${row.project}»`,
        noun: 'partida',
        message: 'Se quitará del seguimiento POA y de los reportes. No se puede deshacer.',
        action: () => visit('delete', `/alertas-poa/${row.id}`).then(() => (detailOpen.value = false)),
    });
}

function actionsOf(row) {
    const items = [{ label: 'Ver ficha', icon: 'eye-outline', onSelect: () => openDetail(row) }];
    if (props.can.update) {
        items.push({ label: 'Editar', icon: 'pencil-outline', onSelect: () => openForm(row) });
        if (!row.is_closed) items.push({ label: 'Marcar ejecutada', icon: 'check-circle-outline', onSelect: () => execute(row) });
    }
    if (props.can.delete) items.push({ label: 'Eliminar', icon: 'trash-can-outline', danger: true, separator: true, onSelect: () => remove(row) });
    return items;
}
</script>

<template>
    <AppLayout title="Alertas POA">
        <PageHeader description="Partidas del Plan Operativo Anual que la empresa sigue en las instituciones">
            <template #actions>
                <AppButton v-if="can.create && stats.count > 0" variant="primary" icon="plus" @click="openForm()">Nueva partida</AppButton>
            </template>
        </PageHeader>

        <div class="cap-stagger grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard variant="featured" label="Presupuesto identificado" :value="money(stats.budgeted)" :hint="`${number(stats.count)} partidas${list.filters.year ? ` de ${list.filters.year}` : ''}`" />
            <StatCard label="Ponderado por probabilidad" :value="money(stats.weighted)" hint="Partidas abiertas" />
            <StatCard label="Ejecución" :value="stats.execution === null ? '—' : percent(stats.execution)" :hint="`${money(stats.executed)} ejecutado`" />
            <StatCard :variant="stats.alerts > 0 ? 'alert' : 'normal'" label="Con alerta" :value="number(stats.alerts)" hint="Fecha a 30 días o cierre anual" />
        </div>

        <Toolbar v-model:search="list.filters.search" v-model:filter="list.filters.filter" :filters="segments" filter-label="Filtrar partidas" search-placeholder="Buscar proyecto o institución…">
            <template #filters>
                <div class="flex w-full gap-3 sm:w-auto">
                    <SelectInput v-model="list.filters.year" :options="yearOptions" placeholder="Todos los años" aria-label="Año" class="sm:w-40" />
                    <SelectInput v-model="list.filters.quarter" :options="quarterOptions" placeholder="Todo el año" aria-label="Trimestre" class="sm:w-40" />
                </div>
            </template>
        </Toolbar>

        <LoadError v-if="list.error.value" title="No se pudieron actualizar las partidas" :message="list.error.value" @retry="list.reload()" />

        <div class="flex flex-col">
            <DataTable
                :columns="columns"
                :rows="rows"
                :loading="list.loading.value"
                :filtered="filtered"
                caption="Partidas POA"
                clickable
                empty-icon="bell-alert-outline"
                empty-title="Aún no hay partidas POA"
                empty-description="Registra las partidas del POA de tus instituciones para recibir alertas antes de su fecha."
                @row-click="openDetail"
                @clear-filters="list.reset({ filter: 'all', search: '', year: null, quarter: null })"
            >
                <template #cell-project="{ row }">
                    <span class="flex items-center gap-2">
                        <i v-if="row.has_alert" class="mdi mdi-bell-alert-outline text-base leading-none text-cap-status-warning dark:text-cap-status-dark-warning" aria-label="Con alerta" />
                        <span class="truncate" :title="row.project">{{ row.project }}</span>
                    </span>
                </template>
                <template #cell-estimated_date="{ row }">
                    <span class="tabular-nums">{{ date(row.estimated_date) }}</span>
                    <span v-if="row.estimated_date && !row.is_closed" class="block text-xs text-cap-text-soft">{{ relativeDays(row.estimated_date) }}</span>
                </template>
                <template #cell-status="{ value }"><StatusBadge :status="badgeOf(value)" :label="value" /></template>
                <template #actions="{ row }"><DropdownMenu :label="`Acciones de la partida ${row.project}`" :items="actionsOf(row)" /></template>
                <template v-if="can.create" #empty-action><AppButton variant="primary" icon="plus" @click="openForm()">Nueva partida</AppButton></template>
            </DataTable>
            <Pagination :paginator="records" :only="['records', 'filters']" />
        </div>

        <BaseModal v-model:open="formOpen" as="form" size="lg" :title="editing ? 'Editar partida POA' : 'Nueva partida POA'" :persistent="form.processing" @submit="save">
            <div ref="formRoot" class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
                <FormField label="Proyecto" required :error="form.errors.project" class="md:col-span-2">
                    <TextInput v-model="form.project" maxlength="255" placeholder="Adquisición de equipo de cómputo" />
                </FormField>
                <FormField label="Institución" optional :error="form.errors.institution_id" class="md:col-span-2">
                    <SearchSelect v-model="form.institution_id" :options="institutions" option-value="id" option-label="name" placeholder="Buscar institución…" />
                </FormField>
                <FormField label="Año" required :error="form.errors.year">
                    <TextInput v-model="form.year" type="number" min="2020" max="2100" class="max-w-28" />
                </FormField>
                <FormField label="Trimestre" optional :error="form.errors.quarter">
                    <SelectInput v-model="form.quarter" :options="quarterOptions" placeholder="Sin trimestre" />
                </FormField>
                <FormField label="Partida presupuestaria" optional :error="form.errors.partida">
                    <TextInput v-model="form.partida" maxlength="100" placeholder="42210" />
                </FormField>
                <FormField label="Fecha estimada de compra" optional :error="form.errors.estimated_date">
                    <DateInput v-model="form.estimated_date" />
                </FormField>
                <FormField label="Monto presupuestado" required :error="form.errors.budgeted_amount">
                    <MoneyInput v-model="form.budgeted_amount" :min="0" class="w-full" />
                </FormField>
                <FormField label="Monto ejecutado" optional :error="form.errors.executed_amount">
                    <MoneyInput v-model="form.executed_amount" :min="0" class="w-full" />
                </FormField>
                <FormField label="Probabilidad (%)" required :error="form.errors.probability">
                    <TextInput v-model="form.probability" type="number" min="0" max="100" class="max-w-28" />
                </FormField>
                <FormField label="Estado" required :error="form.errors.status">
                    <SelectInput v-model="form.status" :options="statusOptions" placeholder="" />
                </FormField>
                <div class="md:col-span-2">
                    <Checkbox v-model="form.annual_close_alert" label="Alertar al cierre del año fiscal" description="Mantiene la alerta encendida aunque falte más de un mes para la fecha estimada." />
                </div>
                <FormField label="Notas" optional :error="form.errors.notes" class="md:col-span-2">
                    <TextArea v-model="form.notes" :rows="3" maxlength="5000" />
                </FormField>
            </div>
            <template #footer>
                <AppButton :disabled="form.processing" @click="formOpen = false">Cancelar</AppButton>
                <AppButton variant="primary" type="submit" icon="content-save-outline" :loading="form.processing">{{ editing ? 'Guardar cambios' : 'Registrar partida' }}</AppButton>
            </template>
        </BaseModal>

        <BaseModal v-model:open="detailOpen" size="lg" :title="selected ? selected.project : 'Partida POA'">
            <DescriptionList v-if="selected" :items="detailItems">
                <template #status="{ item }"><StatusBadge :status="badgeOf(item.value)" :label="item.value" /></template>
            </DescriptionList>
            <template #footer>
                <AppButton v-if="can.delete && selected" variant="danger" icon="trash-can-outline" class="sm:mr-auto" @click="remove(selected)">Eliminar</AppButton>
                <AppButton v-if="can.update && selected" icon="pencil-outline" @click="openForm(selected)">Editar</AppButton>
                <AppButton v-if="can.update && selected && !selected.is_closed" variant="primary" icon="check-circle-outline" @click="execute(selected)">Marcar ejecutada</AppButton>
                <AppButton v-else @click="detailOpen = false">Cerrar</AppButton>
            </template>
        </BaseModal>
    </AppLayout>
</template>
