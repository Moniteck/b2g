<script setup>
// Listado genérico con alta, edición y eliminación, armado con la biblioteca compartida.
// Sirve a las pantallas que aún no tienen diseño propio; conserva su API de siempre.
// Props: title, records (paginador de Laravel o { data }), fields ([{ key, label, type?, options?,
//        default? }]; type: text | email | number | date | select | institution | textarea | checkbox),
//        createUrl, updateUrl y deleteUrl (con `:id`), institutions ([{ id, name }]), statistics (objeto
//        de cifras), noun (nombre del registro en singular, para los textos: «cliente»).
// Slot `actions`: botones secundarios junto a «Nuevo» (exportar PDF, Excel…).
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppButton from '../../Components/AppButton.vue';
import BaseModal from '../../Components/BaseModal.vue';
import Checkbox from '../../Components/Checkbox.vue';
import DataTable from '../../Components/DataTable.vue';
import DateInput from '../../Components/DateInput.vue';
import DescriptionList from '../../Components/DescriptionList.vue';
import DropdownMenu from '../../Components/DropdownMenu.vue';
import FormField from '../../Components/FormField.vue';
import PageHeader from '../../Components/PageHeader.vue';
import Pagination from '../../Components/Pagination.vue';
import SearchSelect from '../../Components/SearchSelect.vue';
import SelectInput from '../../Components/SelectInput.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import TextArea from '../../Components/TextArea.vue';
import TextInput from '../../Components/TextInput.vue';
import Toolbar from '../../Components/Toolbar.vue';
import CapAlert from '../../../design/vue/CapAlert.vue';
import { useConfirm } from '../../composables/useConfirm.js';
import { errorList, focusFirstError } from '../../lib/errors.js';
import { EMPTY, date, number, quantity } from '../../lib/format.js';
import { visit } from '../../lib/http.js';

const props = defineProps({
    title: { type: String, default: '' },
    records: { type: Object, default: () => ({}) },
    fields: { type: Array, required: true },
    createUrl: { type: String, default: '' },
    updateUrl: { type: String, default: '' },
    deleteUrl: { type: String, default: '' },
    institutions: { type: Array, default: () => [] },
    statistics: { type: Object, default: null },
    noun: { type: String, default: 'registro' },
});

const SUMMARY_THRESHOLD = 3;

const confirm = useConfirm();
const query = ref('');
const modalOpen = ref(false);
const selected = ref(null);
const formRoot = ref(null);
const form = useForm(blank());

const rows = computed(() => props.records?.data ?? []);
const total = computed(() => props.records?.total ?? props.records?.meta?.total ?? rows.value.length);
const canEdit = computed(() => Boolean(selected.value ? props.updateUrl : props.createUrl));
const institutionNames = computed(() => new Map(props.institutions.map((institution) => [institution.id, institution.name])));
const institutionOptions = computed(() => props.institutions.map((institution) => ({ value: institution.id, label: institution.name })));
const errors = computed(() => errorList(form.errors));

function blank() {
    return Object.fromEntries(props.fields.map((field) => [field.key, field.default ?? (field.type === 'checkbox' ? false : '')]));
}

function isMoney(field) {
    return field.type === 'number' && /amount|price|monto|precio|total/i.test(field.key);
}

function display(field, value) {
    if (field.type === 'checkbox') return value ? 'Sí' : 'No';
    if (value === null || value === undefined || value === '') return EMPTY;
    if (field.type === 'institution') return institutionNames.value.get(value) ?? EMPTY;
    if (field.type === 'select') return field.options?.find((option) => option.value === value)?.label ?? value;
    if (field.type === 'date') return date(value);
    if (field.type === 'number') return isMoney(field) ? number(value, 2) : quantity(value);
    return String(value);
}

const columns = computed(() =>
    props.fields
        .filter((field) => field.type !== 'textarea')
        .map((field, index) => ({
            key: field.key,
            label: isMoney(field) ? `${field.label} (L)` : field.label,
            align: field.type === 'number' ? 'right' : 'left',
            sortable: true,
            primary: index === 0,
            format: (value) => display(field, value),
        })),
);

const normalize = (value) => String(value).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

const visibleRows = computed(() => {
    const needle = normalize(query.value.trim());
    if (!needle) return rows.value;
    return rows.value.filter((row) => normalize(props.fields.map((field) => display(field, row[field.key])).join(' ')).includes(needle));
});

const figures = computed(() =>
    Object.entries(props.statistics ?? {})
        .filter(([, value]) => typeof value === 'number' || typeof value === 'string')
        .map(([key, value]) => ({ label: key.replace(/[_-]+/g, ' ').replace(/^./, (letter) => letter.toUpperCase()), value: typeof value === 'number' ? quantity(value) : value })),
);

const detailItems = computed(() => props.fields.map((field) => ({ key: field.key, label: field.label, value: display(field, selected.value?.[field.key]), wide: field.type === 'textarea' })));

function isStatus(field) {
    return field.type === 'select' && /status|estado/i.test(field.key);
}

function open(row = null) {
    selected.value = row;
    form.defaults(row ? Object.fromEntries(props.fields.map((field) => [field.key, row[field.key] ?? (field.type === 'checkbox' ? false : '')])) : blank());
    form.reset();
    form.clearErrors();
    modalOpen.value = true;
}

function save() {
    const url = selected.value ? props.updateUrl.replace(':id', selected.value.id) : props.createUrl;
    form.submit(selected.value ? 'put' : 'post', url, {
        preserveScroll: true,
        onSuccess: () => {
            modalOpen.value = false;
        },
        onError: () => focusFirstError(formRoot.value ?? document),
    });
}

function nameOf(row) {
    return String(row[props.fields[0].key] ?? `#${row.id}`);
}

function remove(row) {
    confirm({
        verb: 'Eliminar',
        object: `${props.noun} «${nameOf(row)}»`,
        noun: props.noun,
        message: 'Se quitará de la lista y de los registros que lo usan. No se puede deshacer.',
        action: () => visit('delete', props.deleteUrl.replace(':id', row.id)),
    });
}

function rowActions(row) {
    return [
        { label: props.updateUrl ? 'Editar' : 'Ver detalle', icon: props.updateUrl ? 'pencil-outline' : 'eye-outline', onSelect: () => open(row) },
        ...(props.deleteUrl ? [{ label: 'Eliminar', icon: 'trash-can-outline', danger: true, separator: true, onSelect: () => remove(row) }] : []),
    ];
}
</script>

<template>
    <PageHeader :description="`${number(total)} ${total === 1 ? 'registro' : 'registros'}`">
        <template #actions>
            <slot name="actions" />
            <AppButton v-if="createUrl && rows.length" variant="primary" icon="plus" @click="open()">Agregar {{ noun }}</AppButton>
        </template>
    </PageHeader>

    <div v-if="figures.length" class="grid grid-cols-2 gap-3 md:grid-cols-4">
        <div v-for="figure in figures" :key="figure.label" class="rounded-cap-card border border-cap-divider bg-cap-card p-4">
            <p class="truncate font-display text-3xl font-semibold tabular-nums text-gray-900 dark:text-gray-100" :title="String(figure.value)">{{ figure.value }}</p>
            <p class="mt-1 truncate text-xs text-cap-text-soft" :title="figure.label">{{ figure.label }}</p>
        </div>
    </div>

    <Toolbar v-if="rows.length" v-model:search="query" :search-placeholder="`Buscar en ${title.toLowerCase() || 'la lista'}…`" />

    <div class="min-w-0">
        <DataTable
            :columns="columns"
            :rows="visibleRows"
            :caption="title"
            :filtered="Boolean(query)"
            clickable
            empty-title="Aún no hay registros"
            :empty-description="createUrl ? `Usa «Agregar ${noun}» para crear el primero.` : 'Los registros aparecerán aquí cuando existan.'"
            @row-click="open"
            @clear-filters="query = ''"
        >
            <template v-for="field in fields.filter(isStatus)" :key="field.key" #[`cell-${field.key}`]="{ value }">
                <StatusBadge v-if="value" :value="display(field, value)" />
                <template v-else>{{ EMPTY }}</template>
            </template>
            <template #actions="{ row }">
                <DropdownMenu :label="`Acciones de ${nameOf(row)}`" :items="rowActions(row)" />
            </template>
            <template v-if="createUrl" #empty-action>
                <AppButton variant="primary" icon="plus" @click="open()">Agregar {{ noun }}</AppButton>
            </template>
        </DataTable>
        <Pagination :paginator="records" />
    </div>

    <BaseModal
        v-model:open="modalOpen"
        :as="canEdit ? 'form' : 'div'"
        size="lg"
        :title="!canEdit ? `Detalle de ${noun}` : selected ? `Editar ${noun}` : `Agregar ${noun}`"
        :persistent="form.processing"
        @submit="save"
    >
        <DescriptionList v-if="!canEdit" :items="detailItems" />
        <div v-else ref="formRoot" class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
            <CapAlert v-if="errors.length > SUMMARY_THRESHOLD" class="md:col-span-2" status="error" title="Revisa los campos marcados" :detail="`Hay ${errors.length} datos por corregir.`" />
            <template v-for="field in fields" :key="field.key">
                <Checkbox v-if="field.type === 'checkbox'" v-model="form[field.key]" :label="field.label" :invalid="Boolean(form.errors[field.key])" :description="form.errors[field.key]" />
                <FormField v-else :label="field.label" :error="form.errors[field.key]" :class="field.type === 'textarea' ? 'md:col-span-2' : ''">
                    <SelectInput v-if="field.type === 'select'" v-model="form[field.key]" :options="field.options ?? []" />
                    <SearchSelect v-else-if="field.type === 'institution'" v-model="form[field.key]" :options="institutionOptions" placeholder="Buscar institución…" />
                    <TextArea v-else-if="field.type === 'textarea'" v-model="form[field.key]" />
                    <DateInput v-else-if="field.type === 'date'" v-model="form[field.key]" />
                    <TextInput v-else v-model="form[field.key]" :type="field.type ?? 'text'" :step="field.type === 'number' ? 'any' : undefined" />
                </FormField>
            </template>
        </div>
        <template #footer>
            <AppButton @click="modalOpen = false">{{ canEdit ? 'Cancelar' : 'Cerrar' }}</AppButton>
            <AppButton v-if="canEdit" variant="primary" type="submit" :loading="form.processing">{{ selected ? 'Guardar cambios' : `Agregar ${noun}` }}</AppButton>
        </template>
    </BaseModal>
</template>
