<script setup>
// Historial de procesos de HonduCompras de la empresa activa: participación, montos, resultado y causa.
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppButton from '@/Components/AppButton.vue';
import BaseModal from '@/Components/BaseModal.vue';
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
import Toggle from '@/Components/Toggle.vue';
import Toolbar from '@/Components/Toolbar.vue';
import { useConfirm } from '@/composables/useConfirm.js';
import AppLayout from '@/Layouts/AppLayout.vue';
import { focusFirstError } from '@/lib/errors.js';
import { EMPTY, date, money, number, percent } from '@/lib/format.js';
import { visit } from '@/lib/http.js';
import { useListFilters } from '@/Pages/Tools/Partials/useListFilters.js';

const props = defineProps({
    records: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    counts: { type: Object, default: () => ({}) },
    results: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
});

const ONLY = ['records', 'filters', 'counts'];
const { filters, loading, error, active, reset, reload } = useListFilters('/historial', props.filters, { only: ONLY, defaults: { search: '', filter: 'all' } });

const RESULT_STATUS = { Adjudicado: 'ok', 'No adjudicado': 'error', Desierto: 'neutral', Cancelado: 'neutral', 'En evaluación': 'info', 'No participó': 'neutral' };
const MODALITIES = ['Licitación Pública Nacional', 'Licitación Pública Internacional', 'Licitación Privada', 'Concurso Público', 'Contratación Directa', 'Otra'];

const confirm = useConfirm();
const detail = ref(null);
const editing = ref(null);
const formBody = ref(null);
const blank = { process_number: '', name: '', institution_id: null, modality: '', category: '', participated: true, decision: '', estimated_amount: null, offered_amount: null, result: '', cause: '', published_at: '', closed_at: '', notes: '' };
const form = useForm({ ...blank });

const segments = computed(() => [
    { label: 'Todos', value: 'all', count: props.counts.total ?? 0 },
    { label: 'Participó', value: 'participated', count: props.counts.participated ?? 0 },
    { label: 'Adjudicados', value: 'awarded', count: props.counts.awarded ?? 0 },
    { label: 'No adjudicados', value: 'not_awarded', count: props.counts.not_awarded ?? 0 },
]);

const columns = [
    { key: 'process_number', label: 'Proceso', primary: true, wrap: true },
    { key: 'institution', label: 'Institución', wrap: true, hideOnMobile: true },
    { key: 'closed_at', label: 'Cierre', width: 'w-32', format: (value) => date(value) },
    { key: 'estimated_amount', label: 'Estimado (L)', align: 'right', format: (value) => money(value, 'HNL', { symbol: false }) },
    { key: 'offered_amount', label: 'Ofertado (L)', align: 'right', hideOnMobile: true, format: (value) => money(value, 'HNL', { symbol: false }) },
    { key: 'result', label: 'Resultado' },
];

const isNew = computed(() => !editing.value?.id);
const formOpen = computed({ get: () => editing.value !== null, set: (value) => !value && (editing.value = null) });
const detailOpen = computed({ get: () => detail.value !== null, set: (value) => !value && (detail.value = null) });
const resultOptions = computed(() => props.results.map((value) => ({ value, label: value })));
const modalityOptions = MODALITIES.map((value) => ({ value, label: value }));

const detailItems = computed(() => {
    const row = detail.value ?? {};
    return [
        { label: 'Número de proceso', value: row.process_number },
        { label: 'Institución', value: row.institution },
        { label: 'Nombre', value: row.name, wide: true },
        { label: 'Modalidad', value: row.modality },
        { label: 'Categoría', value: row.category },
        { label: 'Participó', value: row.participated ? 'Sí' : 'No' },
        { label: 'Decisión', value: row.decision },
        { label: 'Monto estimado', value: money(row.estimated_amount) },
        { label: 'Monto ofertado', value: money(row.offered_amount) },
        { key: 'result', label: 'Resultado', value: row.result },
        { label: 'Causa', value: row.cause },
        { label: 'Publicación', value: date(row.published_at) },
        { label: 'Cierre', value: date(row.closed_at) },
        { label: 'Notas', value: row.notes, wide: true },
    ];
});

function open(row = {}) {
    detail.value = null;
    editing.value = row;
    form.defaults(Object.fromEntries(Object.keys(blank).map((key) => [key, row[key] ?? blank[key]])));
    form.reset();
    form.clearErrors();
}

function save() {
    const options = { preserveScroll: true, only: [...ONLY, 'flash'], onSuccess: () => (editing.value = null), onError: () => focusFirstError(formBody.value) };
    if (isNew.value) form.post('/historial', options);
    else form.put(`/historial/${editing.value.id}`, options);
}

function remove(row) {
    confirm({
        verb: 'Eliminar',
        object: `el proceso ${row.process_number}`,
        noun: 'proceso',
        message: 'Se quitará del historial de la empresa. No se puede deshacer.',
        icon: 'trash-can-outline',
        action: () => visit('delete', `/historial/${row.id}`, {}, { only: [...ONLY, 'flash'], onSuccess: () => (detail.value = null) }),
    });
}

function actionsOf(row) {
    const items = [{ label: 'Ver detalle', icon: 'eye-outline', onSelect: () => (detail.value = row) }];
    if (props.can.update) items.push({ label: 'Editar', icon: 'pencil-outline', onSelect: () => open(row) });
    if (props.can.delete) items.push({ label: 'Eliminar', icon: 'trash-can-outline', danger: true, separator: true, onSelect: () => remove(row) });
    return items;
}
</script>

<template>
    <AppLayout title="Historial HonduCompras" :fab-label="can.create ? 'Registrar proceso' : ''" @fab="open()">
        <PageHeader description="Procesos en los que la empresa participó o decidió no participar, con su resultado.">
            <template #actions>
                <AppButton v-if="can.create && counts.total" variant="primary" icon="plus" @click="open()">Registrar proceso</AppButton>
            </template>
        </PageHeader>

        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <StatCard variant="featured" label="Tasa de adjudicación" :value="counts.win_rate === null ? EMPTY : percent(counts.win_rate)" hint="Adjudicados sobre procesos en que participó" />
            <StatCard label="Procesos registrados" :value="number(counts.total)" />
            <StatCard label="Con participación" :value="number(counts.participated)" />
            <StatCard label="Adjudicados" :value="number(counts.awarded)" />
        </div>

        <Toolbar v-model:search="filters.search" v-model:filter="filters.filter" :filters="segments" filter-label="Filtrar por resultado" search-placeholder="Buscar proceso o institución" />

        <div>
            <DataTable
                :columns="columns"
                :rows="records.data"
                :loading="loading"
                :error="error"
                :filtered="active"
                caption="Historial de procesos"
                clickable
                empty-icon="history"
                empty-title="Aún no hay procesos en el historial"
                empty-description="Registra los procesos de HonduCompras en que la empresa participó para medir su tasa de adjudicación."
                @row-click="(row) => (detail = row)"
                @retry="reload"
                @clear-filters="reset"
            >
                <template #cell-process_number="{ row }">
                    <div class="min-w-0">
                        <p class="font-medium text-gray-900 dark:text-gray-100">{{ row.process_number }}</p>
                        <p class="truncate text-xs text-cap-text-soft" :title="row.name">{{ row.name }}</p>
                    </div>
                </template>
                <template #cell-result="{ value }">
                    <StatusBadge v-if="value" :status="RESULT_STATUS[value] ?? 'neutral'" :label="value" />
                    <span v-else class="text-cap-text-soft">{{ EMPTY }}</span>
                </template>
                <template #actions="{ row }"><DropdownMenu :label="`Acciones de ${row.process_number}`" :items="actionsOf(row)" /></template>
                <template #empty-action><AppButton v-if="can.create" variant="primary" icon="plus" @click="open()">Registrar proceso</AppButton></template>
            </DataTable>
            <Pagination :paginator="records" :only="ONLY" />
        </div>

        <BaseModal v-model:open="detailOpen" size="lg" title="Detalle del proceso" :description="detail?.process_number">
            <DescriptionList :items="detailItems">
                <template #result="{ item }"><StatusBadge v-if="item.value" :status="RESULT_STATUS[item.value] ?? 'neutral'" :label="item.value" /><span v-else>{{ EMPTY }}</span></template>
            </DescriptionList>
            <template #footer>
                <AppButton v-if="can.delete" variant="danger" icon="trash-can-outline" class="mr-auto" @click="remove(detail)">Eliminar</AppButton>
                <AppButton @click="detail = null">Cerrar</AppButton>
                <AppButton v-if="can.update" variant="primary" icon="pencil-outline" @click="open(detail)">Editar</AppButton>
            </template>
        </BaseModal>

        <BaseModal v-model:open="formOpen" as="form" size="lg" :title="isNew ? 'Registrar proceso' : 'Editar proceso'" :description="isNew ? '' : editing?.process_number" :persistent="form.processing" @submit="save">
            <div ref="formBody" class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
                <FormField label="Número de proceso" required :error="form.errors.process_number"><TextInput v-model="form.process_number" maxlength="100" placeholder="LPN-SESAL-001-2026" /></FormField>
                <FormField label="Institución" optional :error="form.errors.institution_id">
                    <SearchSelect v-model="form.institution_id" url="/instituciones/buscar" :params="{ todas: 1 }" option-value="id" option-label="name" option-description="description" :selected="editing?.institution_id ? { id: editing.institution_id, name: editing.institution } : null" :min-chars="1" placeholder="Buscar institución…" />
                </FormField>
                <FormField label="Nombre del proceso" required :error="form.errors.name" class="md:col-span-2"><TextInput v-model="form.name" maxlength="255" /></FormField>
                <FormField label="Modalidad" optional :error="form.errors.modality"><SelectInput v-model="form.modality" :options="modalityOptions" /></FormField>
                <FormField label="Categoría" optional :error="form.errors.category"><TextInput v-model="form.category" maxlength="100" placeholder="Llantas, papelería…" /></FormField>
                <div class="md:col-span-2"><Toggle v-model="form.participated" label="La empresa presentó oferta" /></div>
                <FormField label="Monto estimado" required :error="form.errors.estimated_amount"><MoneyInput v-model="form.estimated_amount" :min="0" /></FormField>
                <FormField label="Monto ofertado" optional :error="form.errors.offered_amount"><MoneyInput v-model="form.offered_amount" :min="0" /></FormField>
                <FormField label="Resultado" optional :error="form.errors.result"><SelectInput v-model="form.result" :options="resultOptions" /></FormField>
                <FormField label="Decisión" optional :error="form.errors.decision"><TextInput v-model="form.decision" maxlength="100" placeholder="Participar, no participar…" /></FormField>
                <FormField label="Causa del resultado" optional :error="form.errors.cause" class="md:col-span-2"><TextInput v-model="form.cause" maxlength="255" placeholder="Precio, documentación, plazo de entrega…" /></FormField>
                <FormField label="Publicación" optional :error="form.errors.published_at"><DateInput v-model="form.published_at" /></FormField>
                <FormField label="Cierre" optional :error="form.errors.closed_at"><DateInput v-model="form.closed_at" /></FormField>
                <FormField label="Notas" optional :error="form.errors.notes" class="md:col-span-2"><TextArea v-model="form.notes" maxlength="5000" /></FormField>
            </div>
            <template #footer>
                <AppButton :disabled="form.processing" @click="editing = null">Cancelar</AppButton>
                <AppButton variant="primary" type="submit" :icon="isNew ? 'plus' : 'content-save-outline'" :loading="form.processing">{{ isNew ? 'Registrar proceso' : 'Guardar cambios' }}</AppButton>
            </template>
        </BaseModal>
    </AppLayout>
</template>
