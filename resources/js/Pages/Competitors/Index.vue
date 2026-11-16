<script setup>
// Inteligencia competitiva: catálogo de competidores compartido por el grupo y, en su ficha, las
// participaciones registradas en cotizaciones de la empresa activa (solo lectura).
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppButton from '@/Components/AppButton.vue';
import BaseModal from '@/Components/BaseModal.vue';
import DataTable from '@/Components/DataTable.vue';
import DateInput from '@/Components/DateInput.vue';
import DescriptionList from '@/Components/DescriptionList.vue';
import DropdownMenu from '@/Components/DropdownMenu.vue';
import EmptyState from '@/Components/EmptyState.vue';
import ErrorState from '@/Components/ErrorState.vue';
import FormField from '@/Components/FormField.vue';
import MoneyInput from '@/Components/MoneyInput.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import SelectInput from '@/Components/SelectInput.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Tabs from '@/Components/Tabs.vue';
import TextArea from '@/Components/TextArea.vue';
import TextInput from '@/Components/TextInput.vue';
import Toolbar from '@/Components/Toolbar.vue';
import CapSkeleton from '@design/vue/CapSkeleton.vue';
import { useConfirm } from '@/composables/useConfirm.js';
import AppLayout from '@/Layouts/AppLayout.vue';
import { focusFirstError } from '@/lib/errors.js';
import { EMPTY, date, money, number, percent } from '@/lib/format.js';
import { getJson, visit } from '@/lib/http.js';
import { useListFilters } from '@/composables/useListFilters.js';

const props = defineProps({
    records: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    categories: { type: Array, default: () => [] },
    analytics: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
});

const ONLY = ['records', 'filters', 'analytics', 'categories'];
const { filters, loading, error, active, reset, reload } = useListFilters('/inteligencia-competitiva', props.filters, { only: ONLY, defaults: { search: '', category: '' } });

const confirm = useConfirm();
const detail = ref(null);
const tab = ref('ficha');
const participations = ref(null);
const participationsError = ref('');
const editing = ref(null);
const formBody = ref(null);
const blank = { name: '', tax_id: '', category: '', wins: 0, losses: 0, average_award_price: null, last_participation_at: '', institutions_text: '', notes: '' };
const form = useForm({ ...blank });

const categoryOptions = computed(() => props.categories.map((value) => ({ value, label: value })));
const isNew = computed(() => !editing.value?.id);
const formOpen = computed({ get: () => editing.value !== null, set: (value) => !value && (editing.value = null) });
const detailOpen = computed({ get: () => detail.value !== null, set: (value) => !value && (detail.value = null) });

const columns = [
    { key: 'name', label: 'Competidor', primary: true, wrap: true },
    { key: 'category', label: 'Categoría', hideOnMobile: true },
    { key: 'wins', label: 'Ganadas', align: 'right', format: (value) => number(value) },
    { key: 'losses', label: 'Perdidas', align: 'right', format: (value) => number(value) },
    { key: 'win_rate', label: 'Éxito', align: 'right', format: (value) => (value === null ? EMPTY : percent(value)) },
    { key: 'average_award_price', label: 'Precio prom. (L)', align: 'right', hideOnMobile: true, format: (value) => money(value, 'HNL', { symbol: false }) },
    { key: 'last_participation_at', label: 'Última vez', width: 'w-32', format: (value) => date(value) },
];

const participationColumns = [
    { key: 'quote_number', label: 'Cotización', primary: true },
    { key: 'institution', label: 'Institución', wrap: true },
    { key: 'quoted_at', label: 'Fecha', format: (value) => date(value) },
    { key: 'amount', label: 'Monto', align: 'right', format: (value, row) => money(value, row.currency) },
    { key: 'won', label: 'Resultado' },
];

const detailItems = computed(() => {
    const row = detail.value ?? {};
    return [
        { label: 'RTN', value: row.tax_id },
        { label: 'Categoría', value: row.category },
        { label: 'Adjudicaciones', value: number(row.wins) },
        { label: 'No adjudicaciones', value: number(row.losses) },
        { label: 'Tasa de éxito', value: row.win_rate === null ? EMPTY : percent(row.win_rate) },
        { label: 'Precio promedio adjudicado', value: money(row.average_award_price) },
        { label: 'Última participación', value: date(row.last_participation_at) },
        { key: 'institutions', label: 'Instituciones donde participa', value: row.institutions, wide: true },
        { label: 'Notas', value: row.notes, wide: true },
    ];
});

async function loadParticipations() {
    if (!detail.value) return;
    participations.value = null;
    participationsError.value = '';
    try {
        participations.value = await getJson(`/inteligencia-competitiva/${detail.value.id}/participaciones`);
    } catch (problem) {
        participationsError.value = problem.message;
    }
}

watch(tab, (value) => value === 'participaciones' && participations.value === null && loadParticipations());

function show(row) {
    detail.value = row;
    tab.value = 'ficha';
    participations.value = null;
    participationsError.value = '';
}

function open(row = {}) {
    detail.value = null;
    editing.value = row;
    form.defaults({ ...Object.fromEntries(Object.keys(blank).map((key) => [key, row[key] ?? blank[key]])), institutions_text: (row.institutions ?? []).join(', ') });
    form.reset();
    form.clearErrors();
}

function save() {
    const options = { preserveScroll: true, only: [...ONLY, 'flash'], onSuccess: () => (editing.value = null), onError: () => focusFirstError(formBody.value) };
    const payload = ({ institutions_text, ...data }) => ({ ...data, institutions: institutions_text.split(',').map((name) => name.trim()).filter(Boolean) });
    form.transform(payload);
    if (isNew.value) form.post('/inteligencia-competitiva', options);
    else form.put(`/inteligencia-competitiva/${editing.value.id}`, options);
}

function remove(row) {
    confirm({
        verb: 'Eliminar',
        object: `al competidor ${row.name}`,
        noun: 'competidor',
        message: 'Se quitará del catálogo de todas las empresas del grupo. No se puede deshacer.',
        icon: 'trash-can-outline',
        action: () => visit('delete', `/inteligencia-competitiva/${row.id}`, {}, { only: [...ONLY, 'flash'], onSuccess: () => (detail.value = null) }),
    });
}

function actionsOf(row) {
    const items = [{ label: 'Ver ficha', icon: 'eye-outline', onSelect: () => show(row) }];
    if (props.can.update) items.push({ label: 'Editar', icon: 'pencil-outline', onSelect: () => open(row) });
    if (props.can.delete) items.push({ label: 'Eliminar', icon: 'trash-can-outline', danger: true, separator: true, onSelect: () => remove(row) });
    return items;
}
</script>

<template>
    <AppLayout title="Inteligencia competitiva" :fab-label="can.create ? 'Nuevo competidor' : ''" @fab="open()">
        <PageHeader description="Catálogo de competidores compartido por las empresas del grupo.">
            <template #actions>
                <AppButton v-if="can.create && analytics.competitors" variant="primary" icon="plus" @click="open()">Nuevo competidor</AppButton>
            </template>
        </PageHeader>

        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <StatCard variant="featured" label="Tasa de éxito del mercado" :value="analytics.win_rate === null ? EMPTY : percent(analytics.win_rate)" hint="Adjudicaciones sobre decisiones registradas" />
            <StatCard label="Competidores" :value="number(analytics.competitors)" />
            <StatCard label="Adjudicaciones" :value="number(analytics.wins)" />
            <StatCard label="No adjudicaciones" :value="number(analytics.losses)" />
        </div>

        <Toolbar v-model:search="filters.search" search-placeholder="Buscar por nombre, RTN o categoría">
            <template v-if="categories.length" #filters>
                <SelectInput v-model="filters.category" class="sm:w-56" :options="categoryOptions" placeholder="Todas las categorías" aria-label="Filtrar por categoría" />
            </template>
        </Toolbar>

        <div>
            <DataTable
                :columns="columns"
                :rows="records.data"
                :loading="loading"
                :error="error"
                :filtered="active"
                caption="Competidores"
                clickable
                empty-icon="binoculars"
                empty-title="Aún no hay competidores"
                empty-description="Registra a las empresas que compiten contigo en los procesos para comparar precios y resultados."
                @row-click="show"
                @retry="reload"
                @clear-filters="reset"
            >
                <template #cell-name="{ row }">
                    <div class="min-w-0">
                        <p class="font-medium text-gray-900 dark:text-gray-100">{{ row.name }}</p>
                        <p v-if="row.tax_id" class="text-xs text-cap-text-soft">RTN {{ row.tax_id }}</p>
                    </div>
                </template>
                <template #actions="{ row }"><DropdownMenu :label="`Acciones de ${row.name}`" :items="actionsOf(row)" /></template>
                <template #empty-action><AppButton v-if="can.create" variant="primary" icon="plus" @click="open()">Nuevo competidor</AppButton></template>
            </DataTable>
            <Pagination :paginator="records" :only="ONLY" />
        </div>

        <BaseModal v-model:open="detailOpen" size="xl" :title="detail?.name ?? ''" description="Ficha del competidor">
            <Tabs v-model="tab" :tabs="[{ value: 'ficha', label: 'Ficha' }, { value: 'participaciones', label: 'Participaciones', count: participations?.summary?.count }]">
                <template #ficha>
                    <DescriptionList :items="detailItems">
                        <template #institutions="{ item }">
                            <div v-if="item.value?.length" class="flex flex-wrap gap-2">
                                <span v-for="name in item.value" :key="name" class="rounded-md bg-cap-card-2 px-2 py-1 text-xs font-medium text-gray-800 dark:text-gray-200">{{ name }}</span>
                            </div>
                            <span v-else>{{ EMPTY }}</span>
                        </template>
                    </DescriptionList>
                </template>
                <template #participaciones>
                    <p class="mb-4 text-sm text-cap-text-soft">Participantes registrados en las cotizaciones de la empresa activa con el mismo RTN o el mismo nombre.</p>
                    <CapSkeleton v-if="participations === null && !participationsError" variant="table" :rows="3" />
                    <ErrorState v-else-if="participationsError" compact title="No se pudieron cargar las participaciones" :description="participationsError" @retry="loadParticipations" />
                    <EmptyState v-else-if="!participations.data.length" compact icon="clipboard-text-search-outline" title="Sin participaciones registradas" description="Cuando registres a este competidor como participante de una cotización, aparecerá aquí." />
                    <template v-else>
                        <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <div class="rounded-cap-control bg-cap-card-2 p-3"><p class="text-xs text-cap-text-soft">Participaciones</p><p class="font-display text-lg font-semibold tabular-nums">{{ number(participations.summary.count) }}</p></div>
                            <div class="rounded-cap-control bg-cap-card-2 p-3"><p class="text-xs text-cap-text-soft">Ganadas</p><p class="font-display text-lg font-semibold tabular-nums">{{ number(participations.summary.won) }}</p></div>
                            <div class="rounded-cap-control bg-cap-card-2 p-3"><p class="text-xs text-cap-text-soft">Monto promedio</p><p class="font-display text-lg font-semibold tabular-nums">{{ money(participations.summary.average_amount) }}</p></div>
                        </div>
                        <DataTable :columns="participationColumns" :rows="participations.data" dense caption="Participaciones del competidor">
                            <template #cell-won="{ value }"><StatusBadge :status="value ? 'error' : 'neutral'" :label="value ? 'Nos ganó' : 'Participó'" /></template>
                        </DataTable>
                    </template>
                </template>
            </Tabs>
            <template #footer>
                <AppButton v-if="can.delete" variant="danger" icon="trash-can-outline" class="mr-auto" @click="remove(detail)">Eliminar</AppButton>
                <AppButton @click="detail = null">Cerrar</AppButton>
                <AppButton v-if="can.update" variant="primary" icon="pencil-outline" @click="open(detail)">Editar</AppButton>
            </template>
        </BaseModal>

        <BaseModal v-model:open="formOpen" as="form" size="lg" :title="isNew ? 'Nuevo competidor' : 'Editar competidor'" :description="isNew ? '' : editing?.name" :persistent="form.processing" @submit="save">
            <div ref="formBody" class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
                <FormField label="Nombre" required :error="form.errors.name" class="md:col-span-2"><TextInput v-model="form.name" maxlength="255" /></FormField>
                <FormField label="RTN" optional :error="form.errors.tax_id"><TextInput v-model="form.tax_id" maxlength="100" inputmode="numeric" /></FormField>
                <FormField label="Categoría" optional :error="form.errors.category"><TextInput v-model="form.category" maxlength="100" placeholder="Llantas, equipo médico…" /></FormField>
                <FormField label="Adjudicaciones" required :error="form.errors.wins"><TextInput v-model="form.wins" type="number" min="0" step="1" /></FormField>
                <FormField label="No adjudicaciones" required :error="form.errors.losses"><TextInput v-model="form.losses" type="number" min="0" step="1" /></FormField>
                <FormField label="Precio promedio adjudicado" optional :error="form.errors.average_award_price"><MoneyInput v-model="form.average_award_price" :min="0" /></FormField>
                <FormField label="Última participación" optional :error="form.errors.last_participation_at"><DateInput v-model="form.last_participation_at" /></FormField>
                <FormField label="Instituciones donde participa" optional help="Sepáralas con comas." :error="form.errors.institutions" class="md:col-span-2"><TextInput v-model="form.institutions_text" placeholder="SESAL, ENEE, IHSS" /></FormField>
                <FormField label="Notas" optional :error="form.errors.notes" class="md:col-span-2"><TextArea v-model="form.notes" maxlength="5000" /></FormField>
            </div>
            <template #footer>
                <AppButton :disabled="form.processing" @click="editing = null">Cancelar</AppButton>
                <AppButton variant="primary" type="submit" :icon="isNew ? 'plus' : 'content-save-outline'" :loading="form.processing">{{ isNew ? 'Crear competidor' : 'Guardar cambios' }}</AppButton>
            </template>
        </BaseModal>
    </AppLayout>
</template>
