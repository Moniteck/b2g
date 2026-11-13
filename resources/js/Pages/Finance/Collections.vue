<script setup>
// Cobros y cartera de la empresa activa: cifras, lista paginada en el servidor con filtro y búsqueda,
// alta y edición de facturas, ficha con nota de cobro y recordatorio por WhatsApp, pago con interés
// moratorio, eliminación (administrador) y exportes con los filtros aplicados.
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppButton from '../../Components/AppButton.vue';
import BaseModal from '../../Components/BaseModal.vue';
import DataTable from '../../Components/DataTable.vue';
import DateInput from '../../Components/DateInput.vue';
import DescriptionList from '../../Components/DescriptionList.vue';
import DropdownMenu from '../../Components/DropdownMenu.vue';
import FormField from '../../Components/FormField.vue';
import MoneyInput from '../../Components/MoneyInput.vue';
import PageHeader from '../../Components/PageHeader.vue';
import Pagination from '../../Components/Pagination.vue';
import SearchSelect from '../../Components/SearchSelect.vue';
import StatCard from '../../Components/StatCard.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import TextArea from '../../Components/TextArea.vue';
import TextInput from '../../Components/TextInput.vue';
import Toolbar from '../../Components/Toolbar.vue';
import { useConfirm } from '../../composables/useConfirm.js';
import AppLayout from '../../Layouts/AppLayout.vue';
import { focusFirstError } from '../../lib/errors.js';
import { date, daysUntil, money, number, relativeDays } from '../../lib/format.js';
import { getJson, visit } from '../../lib/http.js';
import { FINANCE_SECTIONS } from './Partials/sections.js';
import LoadError from './Partials/LoadError.vue';
import { cleanQuery, useServerList } from './Partials/finance.js';

const props = defineProps({
    records: { type: Object, required: true },
    filters: { type: Object, required: true },
    stats: { type: Object, required: true },
    clients: { type: Array, default: () => [] },
    institutions: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
});

const confirm = useConfirm();
const list = useServerList('/cobros', props.filters, { only: ['records', 'stats', 'filters'], defaults: { filter: 'all', search: '' } });

const rows = computed(() => props.records.data ?? []);
const filtered = computed(() => list.filters.filter !== 'all' || Boolean(list.filters.search));
const hasAny = computed(() => props.stats.total_count > 0);
const exportQuery = computed(() => new URLSearchParams(cleanQuery({ ...props.filters }, { filter: 'all', search: '' })).toString());

watch(() => [list.filters.filter, list.filters.search], () => list.reload());

const segments = computed(() => [
    { label: 'Todas', value: 'all', count: props.stats.total_count },
    { label: 'Pendientes', value: 'pending', count: props.stats.pending_count },
    { label: 'Vencidas', value: 'overdue', count: props.stats.overdue_count },
    { label: 'Pagadas', value: 'paid', count: props.stats.paid_count },
]);

const exportItems = computed(() => [
    { label: 'Descargar PDF', icon: 'file-pdf-box', href: `/cobros/export/pdf?${exportQuery.value}`, external: true },
    { label: 'Descargar Excel (XLSX)', icon: 'file-excel-outline', href: `/cobros/export/xlsx?${exportQuery.value}`, external: true },
]);

const columns = [
    { key: 'number', label: 'Factura', primary: true, width: 'w-36' },
    { key: 'client', label: 'Cliente o institución', format: (value, row) => value ?? row.institution ?? '—' },
    { key: 'concept', label: 'Concepto', hideOnMobile: true },
    { key: 'due_at', label: 'Vencimiento', width: 'w-40' },
    { key: 'amount', label: 'Monto (L)', align: 'right', format: (value) => money(value, 'HNL', { symbol: false }) },
    { key: 'display_status', label: 'Estado', width: 'w-32' },
];

// --- Alta y edición --------------------------------------------------------------------------
const formOpen = ref(false);
const editing = ref(null);
const formRoot = ref(null);
const today = new Date().toLocaleDateString('en-CA', { timeZone: 'America/Tegucigalpa' });
const blank = () => ({ number: '', concept: '', client_id: null, institution_id: null, amount: null, interest_rate: 0, issued_at: today, due_at: '', notes: '' });
const form = useForm(blank());

function openForm(row = null) {
    editing.value = row;
    form.defaults(row ? {
        number: row.number, concept: row.concept, client_id: row.client_id, institution_id: row.institution_id, amount: row.amount,
        interest_rate: row.interest_rate, issued_at: row.issued_at, due_at: row.due_at, notes: row.notes ?? '',
    } : blank());
    form.reset();
    form.clearErrors();
    detailOpen.value = false;
    formOpen.value = true;
}

function save() {
    const options = { preserveScroll: true, onSuccess: () => (formOpen.value = false), onError: () => focusFirstError(formRoot.value ?? document) };
    if (editing.value) form.put(`/cobros/${editing.value.id}`, options);
    else form.post('/cobros', options);
}

// --- Ficha ------------------------------------------------------------------------------------
const detailOpen = ref(false);
const selected = ref(null);
const noteForm = useForm({ collection_note: '' });
const reminder = ref('');
const reminderError = ref('');
const reminderLoading = ref(false);
const copied = ref(false);

const detailItems = computed(() => {
    const row = selected.value;
    if (!row) return [];
    return [
        { key: 'status', label: 'Estado', value: row.display_status },
        { label: 'Monto', value: money(row.amount) },
        { label: 'Cliente', value: row.client },
        { label: 'Institución', value: row.institution },
        { label: 'Emisión', value: date(row.issued_at) },
        { label: 'Vencimiento', value: `${date(row.due_at)}${row.is_paid ? '' : ` (${relativeDays(row.due_at)})`}` },
        { label: 'Interés moratorio anual', value: `${number(row.interest_rate, 2)} %` },
        { label: 'Pago', value: row.is_paid ? `${date(row.paid_at)} · interés ${money(row.interest_amount)}` : null },
        { label: 'Concepto', value: row.concept, wide: true },
        { label: 'Notas', value: row.notes, wide: true },
    ];
});

async function openDetail(row) {
    selected.value = row;
    noteForm.defaults({ collection_note: row.collection_note ?? '' });
    noteForm.reset();
    noteForm.clearErrors();
    reminder.value = '';
    reminderError.value = '';
    copied.value = false;
    detailOpen.value = true;
    reminderLoading.value = true;
    try {
        reminder.value = (await getJson(`/cobros/${row.id}/whatsapp`)).message;
    } catch (error) {
        reminderError.value = error.message || 'No se pudo preparar el mensaje.';
    } finally {
        reminderLoading.value = false;
    }
}

// Tras guardar o pagar, la ficha abierta muestra los datos nuevos de la fila.
watch(rows, (current) => {
    if (selected.value) selected.value = current.find((row) => row.id === selected.value.id) ?? selected.value;
});

function saveNote() {
    noteForm.patch(`/cobros/${selected.value.id}/nota`, { preserveScroll: true, onSuccess: () => noteForm.defaults({ collection_note: noteForm.collection_note }) });
}

async function copyReminder() {
    try {
        await navigator.clipboard.writeText(reminder.value);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2500);
    } catch {
        reminderError.value = 'El navegador no permitió copiar. Selecciona el texto y cópialo a mano.';
    }
}

const whatsappUrl = computed(() => `https://wa.me/?text=${encodeURIComponent(reminder.value)}`);

// --- Pago y eliminación -----------------------------------------------------------------------
const payOpen = ref(false);
const payForm = useForm({ paid_at: today });
const paying = ref(null);
const estimatedInterest = computed(() => {
    const row = paying.value;
    if (!row || !payForm.paid_at) return 0;
    const late = Math.max(0, -daysUntil(row.due_at, new Date(`${payForm.paid_at}T12:00:00-06:00`)));
    return Math.round(row.amount * (row.interest_rate / 100) * (late / 365) * 100) / 100;
});

function openPay(row) {
    paying.value = row;
    payForm.defaults({ paid_at: today });
    payForm.reset();
    payForm.clearErrors();
    payOpen.value = true;
}

function pay() {
    payForm.patch(`/cobros/${paying.value.id}/pagar`, { preserveScroll: true, onSuccess: () => (payOpen.value = false) });
}

function remove(row) {
    confirm({
        verb: 'Eliminar',
        object: `la factura ${row.number}`,
        noun: 'factura',
        message: 'Se quitará de la cartera y de los reportes. No se puede deshacer.',
        action: () => visit('delete', `/cobros/${row.id}`).then(() => (detailOpen.value = false)),
    });
}

function actionsOf(row) {
    const items = [{ label: 'Ver ficha', icon: 'eye-outline', onSelect: () => openDetail(row) }];
    if (props.can.update && !row.is_paid) {
        items.push({ label: 'Registrar pago', icon: 'cash-check', onSelect: () => openPay(row) });
        items.push({ label: 'Editar', icon: 'pencil-outline', onSelect: () => openForm(row) });
    }
    items.push({ label: 'Nota de cobro (PDF)', icon: 'file-pdf-box', href: `/cobros/${row.id}/nota.pdf`, external: true });
    if (props.can.delete) items.push({ label: 'Eliminar', icon: 'trash-can-outline', danger: true, separator: true, onSelect: () => remove(row) });
    return items;
}

const dueTone = (row) => {
    if (row.is_paid) return 'text-cap-text-soft';
    const days = daysUntil(row.due_at);
    return days < 0 ? 'text-cap-status-error dark:text-cap-status-dark-error' : 'text-cap-text-soft';
};
</script>

<template>
    <AppLayout title="Cobros y cartera" :sections="FINANCE_SECTIONS('/cobros')">
        <PageHeader description="Facturas por cobrar de la empresa activa">
            <template #actions>
                <DropdownMenu v-if="can.export" :items="exportItems" label="Exportar cartera" width="w-60">
                    <template #trigger="{ toggle, attrs }">
                        <button type="button" class="cap-button cap-button--secondary" v-bind="attrs" @click="toggle">
                            <i class="mdi mdi-download-outline text-xl leading-none" aria-hidden="true" />Exportar
                        </button>
                    </template>
                </DropdownMenu>
                <AppButton v-if="can.create && hasAny" variant="primary" icon="plus" @click="openForm()">Nueva factura</AppButton>
            </template>
        </PageHeader>

        <div class="cap-stagger grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard variant="featured" label="Por cobrar" :value="money(stats.receivable)" :hint="`${number(stats.unpaid_count)} facturas sin pagar`" />
            <StatCard :variant="stats.overdue > 0 ? 'alert' : 'normal'" label="Cartera vencida" :value="money(stats.overdue)" :hint="`${number(stats.overdue_count)} facturas vencidas`" />
            <StatCard label="Cobrado este mes" :value="money(stats.collected_month)" hint="Por fecha de pago" />
            <StatCard label="Facturas pagadas" :value="number(stats.paid_count)" :hint="`de ${number(stats.total_count)} registradas`" />
        </div>

        <Toolbar v-model:search="list.filters.search" v-model:filter="list.filters.filter" :filters="segments" filter-label="Filtrar por estado" search-placeholder="Buscar factura o cliente…" />

        <LoadError v-if="list.error.value" title="No se pudo actualizar la cartera" :message="list.error.value" @retry="list.reload()" />

        <div class="flex flex-col">
            <DataTable
                :columns="columns"
                :rows="rows"
                :loading="list.loading.value"
                :filtered="filtered"
                caption="Facturas por cobrar"
                clickable
                empty-icon="cash-multiple"
                empty-title="Aún no hay facturas por cobrar"
                empty-description="Registra la primera factura para seguir su cobro."
                @row-click="openDetail"
                @clear-filters="list.reset({ filter: 'all', search: '' })"
            >
                <template #cell-due_at="{ row }">
                    <span class="tabular-nums">{{ date(row.due_at) }}</span>
                    <span v-if="!row.is_paid" class="block text-xs" :class="dueTone(row)">{{ relativeDays(row.due_at) }}</span>
                </template>
                <template #cell-display_status="{ value }"><StatusBadge :value="value" /></template>
                <template #actions="{ row }"><DropdownMenu :label="`Acciones de la factura ${row.number}`" :items="actionsOf(row)" /></template>
                <template v-if="can.create" #empty-action><AppButton variant="primary" icon="plus" @click="openForm()">Nueva factura</AppButton></template>
            </DataTable>
            <Pagination :paginator="records" :only="['records', 'filters']" />
        </div>

        <!-- Alta y edición -->
        <BaseModal v-model:open="formOpen" as="form" size="lg" :title="editing ? `Editar factura ${editing.number}` : 'Nueva factura por cobrar'" :persistent="form.processing" @submit="save">
            <div ref="formRoot" class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
                <FormField label="Número de factura" required :error="form.errors.number">
                    <TextInput v-model="form.number" maxlength="100" placeholder="001-001-01-00001234" />
                </FormField>
                <FormField label="Monto" required :error="form.errors.amount">
                    <MoneyInput v-model="form.amount" :min="0" class="w-full" />
                </FormField>
                <FormField label="Concepto" required :error="form.errors.concept" class="md:col-span-2">
                    <TextInput v-model="form.concept" maxlength="255" placeholder="Suministro de equipo según contrato" />
                </FormField>
                <FormField label="Cliente" optional :error="form.errors.client_id">
                    <SearchSelect v-model="form.client_id" :options="clients" option-value="id" option-label="name" placeholder="Buscar cliente…" />
                </FormField>
                <FormField label="Institución" optional :error="form.errors.institution_id">
                    <SearchSelect v-model="form.institution_id" :options="institutions" option-value="id" option-label="name" placeholder="Buscar institución…" />
                </FormField>
                <FormField label="Fecha de emisión" required :error="form.errors.issued_at">
                    <DateInput v-model="form.issued_at" />
                </FormField>
                <FormField label="Fecha de vencimiento" required :error="form.errors.due_at">
                    <DateInput v-model="form.due_at" :min="form.issued_at" />
                </FormField>
                <FormField label="Interés moratorio anual (%)" optional help="Se aplica por día de atraso al registrar el pago." :error="form.errors.interest_rate">
                    <TextInput v-model="form.interest_rate" type="number" min="0" max="100" step="0.01" class="max-w-28" />
                </FormField>
                <FormField label="Notas" optional :error="form.errors.notes" class="md:col-span-2">
                    <TextArea v-model="form.notes" :rows="3" maxlength="5000" />
                </FormField>
            </div>
            <template #footer>
                <AppButton :disabled="form.processing" @click="formOpen = false">Cancelar</AppButton>
                <AppButton variant="primary" type="submit" icon="content-save-outline" :loading="form.processing">{{ editing ? 'Guardar cambios' : 'Registrar factura' }}</AppButton>
            </template>
        </BaseModal>

        <!-- Ficha -->
        <BaseModal v-model:open="detailOpen" size="lg" :title="selected ? `Factura ${selected.number}` : 'Factura'">
            <div v-if="selected" class="flex flex-col gap-6">
                <DescriptionList :items="detailItems">
                    <template #status="{ item }"><StatusBadge :value="item.value" /></template>
                </DescriptionList>

                <section class="flex flex-col gap-3 border-t border-cap-divider pt-5">
                    <h3 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Nota de cobro</h3>
                    <FormField label="Nota interna de la gestión" hide-label :error="noteForm.errors.collection_note">
                        <TextArea v-model="noteForm.collection_note" :rows="3" maxlength="5000" :readonly="!can.update" placeholder="Llamada al jefe de compras: pagan el viernes." />
                    </FormField>
                    <div v-if="can.update" class="flex justify-end">
                        <AppButton icon="content-save-outline" :loading="noteForm.processing" :disabled="!noteForm.isDirty" @click="saveNote">Guardar nota</AppButton>
                    </div>
                </section>

                <section v-if="!selected.is_paid" class="flex flex-col gap-3 border-t border-cap-divider pt-5">
                    <h3 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Recordatorio por WhatsApp</h3>
                    <span v-if="reminderLoading" class="cap-skeleton h-16 w-full" />
                    <p v-else-if="reminderError" class="text-sm text-cap-status-error dark:text-cap-status-dark-error">{{ reminderError }}</p>
                    <p v-else class="rounded-cap-control bg-cap-card-2 p-3 text-sm text-gray-900 dark:text-gray-100">{{ reminder }}</p>
                    <div v-if="reminder" class="flex flex-wrap justify-end gap-2">
                        <AppButton :icon="copied ? 'check' : 'content-copy'" @click="copyReminder">{{ copied ? 'Copiado' : 'Copiar mensaje' }}</AppButton>
                        <AppButton :href="whatsappUrl" external icon="whatsapp">Abrir WhatsApp</AppButton>
                    </div>
                </section>
            </div>
            <template #footer>
                <AppButton v-if="can.delete" variant="danger" icon="trash-can-outline" class="sm:mr-auto" @click="remove(selected)">Eliminar</AppButton>
                <AppButton external :href="selected ? `/cobros/${selected.id}/nota.pdf` : ''" icon="file-pdf-box">Nota PDF</AppButton>
                <AppButton v-if="can.update && selected && !selected.is_paid" icon="pencil-outline" @click="openForm(selected)">Editar</AppButton>
                <AppButton v-if="can.update && selected && !selected.is_paid" variant="primary" icon="cash-check" @click="openPay(selected)">Registrar pago</AppButton>
            </template>
        </BaseModal>

        <!-- Pago -->
        <BaseModal v-model:open="payOpen" as="form" size="sm" :title="paying ? `Registrar pago de ${paying.number}` : 'Registrar pago'" :persistent="payForm.processing" @submit="pay">
            <div v-if="paying" class="flex flex-col gap-4">
                <p class="text-sm text-gray-700 dark:text-gray-300">La factura quedará como pagada y el cobro entrará en reportes y flujo de caja.</p>
                <FormField label="Fecha de pago" required :error="payForm.errors.paid_at">
                    <DateInput v-model="payForm.paid_at" :max="today" :min="paying.issued_at" />
                </FormField>
                <dl class="grid grid-cols-2 gap-3 rounded-cap-control bg-cap-card-2 p-3 text-sm">
                    <div><dt class="text-xs text-cap-text-soft">Monto</dt><dd class="tabular-nums text-gray-900 dark:text-gray-100">{{ money(paying.amount) }}</dd></div>
                    <div><dt class="text-xs text-cap-text-soft">Interés moratorio</dt><dd class="tabular-nums text-gray-900 dark:text-gray-100">{{ money(estimatedInterest) }}</dd></div>
                </dl>
            </div>
            <template #footer>
                <AppButton :disabled="payForm.processing" @click="payOpen = false">Cancelar</AppButton>
                <AppButton variant="primary" type="submit" icon="cash-check" :loading="payForm.processing">Registrar pago</AppButton>
            </template>
        </BaseModal>
    </AppLayout>
</template>
