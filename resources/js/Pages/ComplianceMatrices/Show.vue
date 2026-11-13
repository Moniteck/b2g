<script setup>
// Ficha de una matriz documental: requisitos con estado (cumple, pendiente, vencido), documento que los
// respalda, vencimiento y renovación.
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppButton from '../../Components/AppButton.vue';
import BaseModal from '../../Components/BaseModal.vue';
import DataTable from '../../Components/DataTable.vue';
import DateInput from '../../Components/DateInput.vue';
import DropdownMenu from '../../Components/DropdownMenu.vue';
import FormField from '../../Components/FormField.vue';
import PageHeader from '../../Components/PageHeader.vue';
import SearchSelect from '../../Components/SearchSelect.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import TextArea from '../../Components/TextArea.vue';
import TextInput from '../../Components/TextInput.vue';
import Toggle from '../../Components/Toggle.vue';
import Toolbar from '../../Components/Toolbar.vue';
import CapAlert from '../../../design/vue/CapAlert.vue';
import { useConfirm } from '../../composables/useConfirm.js';
import AppLayout from '../../Layouts/AppLayout.vue';
import { focusFirstError } from '../../lib/errors.js';
import { EMPTY, date, dateTime, daysUntil, number, relativeDays } from '../../lib/format.js';
import { visit } from '../../lib/http.js';
import ProgressBar from '../Tools/Partials/ProgressBar.vue';

const props = defineProps({
    matrix: { type: Object, required: true },
    items: { type: Array, default: () => [] },
    documents: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
});

const STATUS = { Cumple: 'ok', Pendiente: 'warning', Vencido: 'error' };
const ONLY = ['matrix', 'items', 'flash'];
const base = `/matriz-cumplimiento/${props.matrix.id}`;

const confirm = useConfirm();
const filter = ref('all');
const search = ref('');

const segments = computed(() => [
    { label: 'Todos', value: 'all', count: props.matrix.summary.total },
    { label: 'Pendientes', value: 'Pendiente', count: props.matrix.summary.pending },
    { label: 'Vencidos', value: 'Vencido', count: props.matrix.summary.expired },
    { label: 'Cumplen', value: 'Cumple', count: props.matrix.summary.complies },
]);

const normalize = (value) => String(value ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
const rows = computed(() =>
    props.items.filter((item) => (filter.value === 'all' || item.status === filter.value) && (!search.value || normalize(`${item.requirement} ${item.document ?? ''}`).includes(normalize(search.value)))),
);

const columns = [
    { key: 'requirement', label: 'Requisito', primary: true, wrap: true },
    { key: 'document', label: 'Documento', wrap: true },
    { key: 'renewal_due_at', label: 'Vence' },
    { key: 'status', label: 'Estado' },
];

const progressStatus = computed(() => (props.matrix.summary.expired ? 'error' : props.matrix.summary.progress === 100 ? 'ok' : 'warning'));

// Requisito: alta y edición
const editing = ref(null);
const itemBody = ref(null);
const itemForm = useForm({ requirement: '', document_id: null, renewal_due_at: '', completed: false, notes: '' });
const itemOpen = computed({ get: () => editing.value !== null, set: (value) => !value && (editing.value = null) });
const isNewItem = computed(() => !editing.value?.id);

function openItem(row = {}) {
    editing.value = row;
    itemForm.defaults({ requirement: row.requirement ?? '', document_id: row.document_id ?? null, renewal_due_at: row.renewal_due_at ?? '', completed: row.completed ?? false, notes: row.notes ?? '' });
    itemForm.reset();
    itemForm.clearErrors();
}

function saveItem() {
    const options = { preserveScroll: true, only: ONLY, onSuccess: () => (editing.value = null), onError: () => focusFirstError(itemBody.value) };
    if (isNewItem.value) itemForm.post(`${base}/items`, options);
    else itemForm.put(`${base}/items/${editing.value.id}`, options);
}

// Renovación
const renewing = ref(null);
const renewBody = ref(null);
const renewForm = useForm({ document_id: null, renewal_due_at: '', notes: '' });
const renewOpen = computed({ get: () => renewing.value !== null, set: (value) => !value && (renewing.value = null) });

function openRenew(row) {
    renewing.value = row;
    renewForm.defaults({ document_id: row.document_id ?? null, renewal_due_at: '', notes: row.notes ?? '' });
    renewForm.reset();
    renewForm.clearErrors();
}

function pickDocument(option) {
    if (option?.expires_at && !renewForm.renewal_due_at) renewForm.renewal_due_at = option.expires_at;
}

function saveRenew() {
    renewForm.patch(`${base}/items/${renewing.value.id}/renovar`, { preserveScroll: true, only: ONLY, onSuccess: () => (renewing.value = null), onError: () => focusFirstError(renewBody.value) });
}

// Nombre de la matriz
const renaming = ref(false);
const nameForm = useForm({ name: props.matrix.name });

function openRename() {
    nameForm.defaults({ name: props.matrix.name });
    nameForm.reset();
    nameForm.clearErrors();
    renaming.value = true;
}

function saveName() {
    nameForm.put(base, { preserveScroll: true, only: ONLY, onSuccess: () => (renaming.value = false) });
}

function toggle(row) {
    visit('patch', `${base}/items/${row.id}`, { completed: !row.completed }, { only: ONLY });
}

function removeItem(row) {
    confirm({
        verb: 'Eliminar',
        object: `el requisito «${row.requirement}»`,
        noun: 'requisito',
        message: 'Se quitará de la matriz. No se puede deshacer.',
        icon: 'trash-can-outline',
        action: () => visit('delete', `${base}/items/${row.id}`, {}, { only: ONLY }),
    });
}

function removeMatrix() {
    confirm({
        verb: 'Eliminar',
        object: `la matriz «${props.matrix.name}»`,
        noun: 'matriz',
        message: `Se borrarán sus ${props.matrix.summary.total} requisitos. Los documentos del directorio no se tocan. No se puede deshacer.`,
        icon: 'trash-can-outline',
        action: () => visit('delete', base, {}, { preserveScroll: false }),
    });
}

function actionsOf(row) {
    if (!props.can.update) return [];
    const items = [
        row.completed
            ? { label: 'Marcar como pendiente', icon: 'progress-clock', onSelect: () => toggle(row) }
            : { label: 'Marcar como cumplido', icon: 'check-circle-outline', onSelect: () => toggle(row) },
        { label: 'Renovar', icon: 'autorenew', onSelect: () => openRenew(row) },
        { label: 'Editar', icon: 'pencil-outline', onSelect: () => openItem(row) },
    ];
    if (props.can.delete) items.push({ label: 'Eliminar', icon: 'trash-can-outline', danger: true, separator: true, onSelect: () => removeItem(row) });
    return items;
}

function dueHint(value) {
    const days = daysUntil(value);
    if (days === null) return '';
    return days < 0 ? `venció ${relativeDays(value)}` : days <= 30 ? `vence ${relativeDays(value)}` : '';
}
</script>

<template>
    <AppLayout :title="matrix.name" back-href="/matriz-cumplimiento" :breadcrumbs="[{ label: 'Matriz documental', href: '/matriz-cumplimiento' }, { label: matrix.name }]">
        <PageHeader>
            <p class="text-sm text-cap-text-soft">
                <template v-if="matrix.tender">{{ matrix.tender.reference }} · {{ matrix.tender.title }}<template v-if="matrix.tender.presentation_deadline"> · presentación {{ dateTime(matrix.tender.presentation_deadline, 'medium') }}</template></template>
                <template v-else>Sin licitación vinculada</template>
            </p>
            <template #actions>
                <AppButton external :href="`${base}/pdf`" icon="file-pdf-box">PDF</AppButton>
                <AppButton v-if="can.update" icon="pencil-outline" @click="openRename">Renombrar</AppButton>
                <AppButton v-if="can.delete" variant="danger" icon="trash-can-outline" @click="removeMatrix">Eliminar matriz</AppButton>
                <AppButton v-if="can.update && items.length" variant="primary" icon="plus" @click="openItem()">Agregar requisito</AppButton>
            </template>
        </PageHeader>

        <section class="rounded-cap-card border border-cap-divider bg-cap-card p-5">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="text-xs font-medium text-cap-text-soft">Progreso de la matriz</p>
                    <p class="mt-1 font-display text-3xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ matrix.summary.progress }} %</p>
                </div>
                <p class="text-sm tabular-nums">
                    <span class="text-cap-status-ok-ink">{{ number(matrix.summary.complies) }} cumplen</span><span class="text-cap-text-soft"> · </span>
                    <span class="text-cap-status-warning-ink">{{ number(matrix.summary.pending) }} pendientes</span><span class="text-cap-text-soft"> · </span>
                    <span :class="matrix.summary.expired ? 'text-cap-status-error-ink' : 'text-cap-text-soft'">{{ number(matrix.summary.expired) }} vencidos</span>
                </p>
            </div>
            <ProgressBar class="mt-4" :value="matrix.summary.progress" :status="progressStatus" label="Progreso de la matriz" />
        </section>

        <CapAlert v-if="matrix.summary.expired" status="error" :title="`${matrix.summary.expired} ${matrix.summary.expired === 1 ? 'requisito vencido' : 'requisitos vencidos'}`" detail="Renuévalos con el documento vigente antes de presentar la oferta." />

        <Toolbar v-model:search="search" v-model:filter="filter" :filters="segments" filter-label="Filtrar requisitos" search-placeholder="Buscar requisito o documento" />

        <DataTable
            :columns="columns"
            :rows="rows"
            :filtered="Boolean(search) || filter !== 'all'"
            caption="Requisitos de la matriz"
            empty-icon="clipboard-list-outline"
            empty-title="Esta matriz no tiene requisitos"
            empty-description="Agrega los documentos que exige el pliego, uno por fila."
            @clear-filters="search = ''; filter = 'all'"
        >
            <template #cell-requirement="{ row }">
                <div class="min-w-0">
                    <p class="font-medium text-gray-900 dark:text-gray-100">{{ row.requirement }}</p>
                    <p v-if="row.notes" class="truncate text-xs text-cap-text-soft" :title="row.notes">{{ row.notes }}</p>
                </div>
            </template>
            <template #cell-document="{ value }"><span :class="value ? '' : 'text-cap-text-soft'">{{ value || 'Sin vincular' }}</span></template>
            <template #cell-renewal_due_at="{ value }">
                <div v-if="value">
                    <p class="tabular-nums">{{ date(value) }}</p>
                    <p v-if="dueHint(value)" class="text-xs text-cap-text-soft">{{ dueHint(value) }}</p>
                </div>
                <span v-else class="text-cap-text-soft">{{ EMPTY }}</span>
            </template>
            <template #cell-status="{ value }"><StatusBadge :status="STATUS[value]" :label="value" /></template>
            <template #actions="{ row }"><DropdownMenu v-if="can.update" :label="`Acciones de ${row.requirement}`" :items="actionsOf(row)" width="w-56" /></template>
            <template #empty-action><AppButton v-if="can.update" variant="primary" icon="plus" @click="openItem()">Agregar requisito</AppButton></template>
        </DataTable>

        <BaseModal v-model:open="itemOpen" as="form" size="lg" :title="isNewItem ? 'Agregar requisito' : 'Editar requisito'" :persistent="itemForm.processing" @submit="saveItem">
            <div ref="itemBody" class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
                <FormField label="Requisito" required :error="itemForm.errors.requirement" class="md:col-span-2"><TextInput v-model="itemForm.requirement" maxlength="255" placeholder="Solvencia fiscal vigente" /></FormField>
                <FormField label="Documento que lo respalda" optional :error="itemForm.errors.document_id" class="md:col-span-2">
                    <SearchSelect v-model="itemForm.document_id" :options="documents" option-value="id" option-label="name" option-description="description" :selected="editing?.document_id ? { id: editing.document_id, name: editing.document } : null" placeholder="Buscar en el directorio…" />
                </FormField>
                <FormField label="Vence" optional :error="itemForm.errors.renewal_due_at"><DateInput v-model="itemForm.renewal_due_at" /></FormField>
                <div class="flex items-end pb-2"><Toggle v-model="itemForm.completed" label="Cumple" /></div>
                <FormField label="Notas" optional :error="itemForm.errors.notes" class="md:col-span-2"><TextArea v-model="itemForm.notes" rows="3" maxlength="2000" /></FormField>
            </div>
            <template #footer>
                <AppButton :disabled="itemForm.processing" @click="editing = null">Cancelar</AppButton>
                <AppButton variant="primary" type="submit" :icon="isNewItem ? 'plus' : 'content-save-outline'" :loading="itemForm.processing">{{ isNewItem ? 'Agregar requisito' : 'Guardar cambios' }}</AppButton>
            </template>
        </BaseModal>

        <BaseModal v-model:open="renewOpen" as="form" size="md" title="Renovar requisito" :description="renewing?.requirement" :persistent="renewForm.processing" @submit="saveRenew">
            <div ref="renewBody" class="grid grid-cols-1 gap-y-4">
                <CapAlert v-if="!documents.length" status="info" title="El directorio no tiene documentos vigentes" detail="Sube primero el documento renovado al Directorio Institucional." />
                <FormField label="Documento renovado" required :error="renewForm.errors.document_id">
                    <SearchSelect v-model="renewForm.document_id" :options="documents" option-value="id" option-label="name" option-description="description" :selected="renewing?.document_id ? { id: renewing.document_id, name: renewing.document } : null" placeholder="Buscar en el directorio…" @select="pickDocument" />
                </FormField>
                <FormField label="Nuevo vencimiento" required :error="renewForm.errors.renewal_due_at"><DateInput v-model="renewForm.renewal_due_at" /></FormField>
                <FormField label="Notas" optional :error="renewForm.errors.notes"><TextArea v-model="renewForm.notes" rows="3" maxlength="2000" /></FormField>
            </div>
            <template #footer>
                <AppButton :disabled="renewForm.processing" @click="renewing = null">Cancelar</AppButton>
                <AppButton variant="primary" type="submit" icon="autorenew" :loading="renewForm.processing">Renovar requisito</AppButton>
            </template>
        </BaseModal>

        <BaseModal v-model:open="renaming" as="form" size="sm" title="Renombrar matriz" :persistent="nameForm.processing" @submit="saveName">
            <FormField label="Nombre" required :error="nameForm.errors.name"><TextInput v-model="nameForm.name" maxlength="255" /></FormField>
            <template #footer>
                <AppButton :disabled="nameForm.processing" @click="renaming = false">Cancelar</AppButton>
                <AppButton variant="primary" type="submit" icon="content-save-outline" :loading="nameForm.processing">Guardar nombre</AppButton>
            </template>
        </BaseModal>
    </AppLayout>
</template>
