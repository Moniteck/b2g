<script setup>
// CRM político: relaciones de la empresa activa con instituciones, su línea de tiempo de interacciones
// y la próxima acción de cada una.
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppButton from '@/Components/AppButton.vue';
import BaseModal from '@/Components/BaseModal.vue';
import DataTable from '@/Components/DataTable.vue';
import DateInput from '@/Components/DateInput.vue';
import DateTimeInput from '@/Components/DateTimeInput.vue';
import DescriptionList from '@/Components/DescriptionList.vue';
import DropdownMenu from '@/Components/DropdownMenu.vue';
import ErrorState from '@/Components/ErrorState.vue';
import FormField from '@/Components/FormField.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import SelectInput from '@/Components/SelectInput.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TextArea from '@/Components/TextArea.vue';
import TextInput from '@/Components/TextInput.vue';
import Timeline from '@/Components/Timeline.vue';
import Toolbar from '@/Components/Toolbar.vue';
import CapSkeleton from '@design/vue/CapSkeleton.vue';
import { useConfirm } from '@/composables/useConfirm.js';
import AppLayout from '@/Layouts/AppLayout.vue';
import { focusFirstError } from '@/lib/errors.js';
import { EMPTY, date, dateTime, daysUntil, number, relativeDays } from '@/lib/format.js';
import { getJson, visit } from '@/lib/http.js';
import { useListFilters } from '@/Pages/Tools/Partials/useListFilters.js';

const props = defineProps({
    records: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    counts: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
});

const ONLY = ['records', 'filters', 'counts'];
const { filters, loading, error, active, reset, reload } = useListFilters('/relaciones-institucionales', props.filters, { only: ONLY, defaults: { search: '', filter: 'all' } });

const STATUS = { Activa: 'ok', 'En pausa': 'warning', Inactiva: 'neutral' };
const INTERACTION_ICONS = { Reunión: 'account-group-outline', Llamada: 'phone-outline', Correo: 'email-outline', Visita: 'map-marker-outline', Evento: 'calendar-star', Otro: 'message-text-outline' };

const confirm = useConfirm();
const detail = ref(null);
const timeline = ref(null);
const timelineError = ref('');
const editing = ref(null);
const formBody = ref(null);
const interactionOpen = ref(false);
const interactionBody = ref(null);

const blank = { institution_id: null, contact_id: null, relationship_type: '', influence: '', channel: '', status: 'Activa', started_at: '', next_contact_at: '', poa_interest: '', notes: '' };
const form = useForm({ ...blank });
const interaction = useForm({ type: 'Reunión', occurred_at: '', description: '', result: '', next_action_at: '' });

const toOptions = (values = []) => values.map((value) => ({ value, label: value }));
const segments = computed(() => [
    { label: 'Todas', value: 'all', count: props.counts.total ?? 0 },
    { label: 'Esta semana', value: 'upcoming', count: props.counts.upcoming ?? 0 },
    { label: 'Atrasadas', value: 'overdue', count: props.counts.overdue ?? 0 },
    { label: 'Inactivas', value: 'inactive', count: props.counts.inactive ?? 0 },
]);

const columns = [
    { key: 'institution', label: 'Institución', primary: true, wrap: true },
    { key: 'contact', label: 'Contacto', wrap: true, hideOnMobile: true },
    { key: 'influence', label: 'Influencia', hideOnMobile: true },
    { key: 'last_contact_at', label: 'Último contacto', width: 'w-36', format: (value) => date(value) },
    { key: 'next_contact_at', label: 'Próxima acción' },
    { key: 'status', label: 'Estado' },
];

const isNew = computed(() => !editing.value?.id);
const formOpen = computed({ get: () => editing.value !== null, set: (value) => !value && (editing.value = null) });
const detailOpen = computed({ get: () => detail.value !== null, set: (value) => !value && (detail.value = null) });

const detailItems = computed(() => {
    const row = detail.value ?? {};
    return [
        { label: 'Contacto', value: row.contact ? [row.contact.name, row.contact.position].filter(Boolean).join(' · ') : null },
        { key: 'status', label: 'Estado', value: row.status },
        { label: 'Tipo de relación', value: row.relationship_type },
        { label: 'Influencia', value: row.influence },
        { label: 'Canal preferido', value: row.channel },
        { label: 'Responsable', value: row.owner },
        { label: 'Inicio de la relación', value: date(row.started_at) },
        { label: 'Último contacto', value: date(row.last_contact_at) },
        { key: 'next', label: 'Próxima acción', value: row.next_contact_at },
        { label: 'Interés en el POA', value: row.poa_interest },
        { label: 'Notas', value: row.notes, wide: true },
    ];
});

const timelineItems = computed(() =>
    (timeline.value ?? []).map((item) => ({
        id: item.id,
        title: `${item.type}${item.result ? ` · ${item.result}` : ''}`,
        description: item.description,
        meta: [item.user, dateTime(item.occurred_at, 'medium'), item.next_action_at ? `próxima acción ${date(item.next_action_at, 'medium')}` : null].filter(Boolean).join(' · '),
        icon: INTERACTION_ICONS[item.type] ?? 'message-text-outline',
    })),
);

function dueStatus(value) {
    const days = daysUntil(value);
    if (days === null) return null;
    if (days < 0) return 'error';
    if (days <= 7) return 'warning';
    return 'neutral';
}

async function loadTimeline() {
    timeline.value = null;
    timelineError.value = '';
    try {
        const response = await getJson(`/relaciones-institucionales/${detail.value.id}`);
        detail.value = response.relation;
        timeline.value = response.interactions;
    } catch (problem) {
        timelineError.value = problem.message;
    }
}

function show(row) {
    detail.value = row;
    loadTimeline();
}

function open(row = {}) {
    detail.value = null;
    editing.value = row;
    form.defaults(Object.fromEntries(Object.keys(blank).map((key) => [key, row[key] ?? blank[key]])));
    form.reset();
    form.clearErrors();
}

function save() {
    const options = { preserveScroll: true, only: [...ONLY, 'flash'], onSuccess: () => (editing.value = null), onError: () => focusFirstError(formBody.value) };
    if (isNew.value) form.post('/relaciones-institucionales', options);
    else form.put(`/relaciones-institucionales/${editing.value.id}`, options);
}

function nowLocal() {
    const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Tegucigalpa', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false }).formatToParts(new Date());
    const get = (type) => parts.find((part) => part.type === type)?.value;
    return `${get('year')}-${get('month')}-${get('day')}T${get('hour') === '24' ? '00' : get('hour')}:${get('minute')}`;
}

function logInteraction() {
    interaction.defaults({ type: 'Reunión', occurred_at: nowLocal(), description: '', result: '', next_action_at: '' });
    interaction.reset();
    interaction.clearErrors();
    interactionOpen.value = true;
}

function saveInteraction() {
    interaction.post(`/relaciones-institucionales/${detail.value.id}/interacciones`, {
        preserveScroll: true,
        only: [...ONLY, 'flash'],
        onSuccess: () => {
            interactionOpen.value = false;
            loadTimeline();
        },
        onError: () => focusFirstError(interactionBody.value),
    });
}

function remove(row) {
    confirm({
        verb: 'Eliminar',
        object: `la relación con ${row.institution?.name ?? 'la institución'}`,
        noun: 'relación',
        message: 'Se borrarán también sus interacciones registradas. No se puede deshacer.',
        icon: 'trash-can-outline',
        action: () => visit('delete', `/relaciones-institucionales/${row.id}`, {}, { only: [...ONLY, 'flash'], onSuccess: () => (detail.value = null) }),
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
    <AppLayout title="Relaciones institucionales" :fab-label="can.create ? 'Nueva relación' : ''" @fab="open()">
        <PageHeader description="Con quién trata la empresa en cada institución, su influencia y la próxima acción.">
            <template #actions>
                <AppButton v-if="can.create && counts.total" variant="primary" icon="plus" @click="open()">Nueva relación</AppButton>
            </template>
        </PageHeader>

        <Toolbar v-model:search="filters.search" v-model:filter="filters.filter" :filters="segments" filter-label="Filtrar por próxima acción" search-placeholder="Buscar institución o contacto" />

        <div>
            <DataTable
                :columns="columns"
                :rows="records.data"
                :loading="loading"
                :error="error"
                :filtered="active"
                caption="Relaciones institucionales"
                clickable
                empty-icon="handshake-outline"
                empty-title="Aún no hay relaciones registradas"
                empty-description="Registra a las personas clave de cada institución y planifica la próxima acción con cada una."
                @row-click="show"
                @retry="reload"
                @clear-filters="reset"
            >
                <template #cell-institution="{ row }">
                    <div class="min-w-0">
                        <p class="font-medium text-gray-900 dark:text-gray-100">{{ row.institution?.acronym || row.institution?.name }}</p>
                        <p v-if="row.institution?.acronym" class="truncate text-xs text-cap-text-soft" :title="row.institution.name">{{ row.institution.name }}</p>
                    </div>
                </template>
                <template #cell-contact="{ row }">
                    <div v-if="row.contact" class="min-w-0">
                        <p class="text-gray-900 dark:text-gray-100">{{ row.contact.name }}</p>
                        <p v-if="row.contact.position" class="truncate text-xs text-cap-text-soft">{{ row.contact.position }}</p>
                    </div>
                    <span v-else class="text-cap-text-soft">{{ EMPTY }}</span>
                </template>
                <template #cell-next_contact_at="{ value }">
                    <StatusBadge v-if="value" :status="dueStatus(value)" :label="`${date(value)} · ${relativeDays(value)}`" />
                    <span v-else class="text-cap-text-soft">Sin programar</span>
                </template>
                <template #cell-status="{ value }"><StatusBadge :status="STATUS[value] ?? 'neutral'" :label="value" /></template>
                <template #actions="{ row }"><DropdownMenu :label="`Acciones de la relación con ${row.institution?.name}`" :items="actionsOf(row)" /></template>
                <template #empty-action><AppButton v-if="can.create" variant="primary" icon="plus" @click="open()">Nueva relación</AppButton></template>
            </DataTable>
            <Pagination :paginator="records" :only="ONLY" />
        </div>

        <BaseModal v-model:open="detailOpen" size="xl" :title="detail?.institution?.name ?? ''" description="Ficha de la relación">
            <div class="grid gap-6 lg:grid-cols-5">
                <section class="lg:col-span-2">
                    <DescriptionList :items="detailItems" :columns="1">
                        <template #status="{ item }"><StatusBadge :status="STATUS[item.value] ?? 'neutral'" :label="item.value" /></template>
                        <template #next="{ item }">
                            <StatusBadge v-if="item.value" :status="dueStatus(item.value)" :label="`${date(item.value, 'medium')} · ${relativeDays(item.value)}`" />
                            <span v-else>Sin programar</span>
                        </template>
                    </DescriptionList>
                </section>
                <section class="lg:col-span-3">
                    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <h3 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Interacciones <span v-if="timeline" class="text-sm font-normal text-cap-text-soft">({{ number(timeline.length) }})</span></h3>
                        <AppButton v-if="can.update" variant="primary" icon="plus" @click="logInteraction">Registrar interacción</AppButton>
                    </div>
                    <CapSkeleton v-if="timeline === null && !timelineError" variant="line" :rows="4" />
                    <ErrorState v-else-if="timelineError" compact title="No se pudo cargar la línea de tiempo" :description="timelineError" @retry="loadTimeline" />
                    <Timeline v-else :items="timelineItems" empty-text="Aún no hay interacciones. Registra la primera reunión, llamada o correo." />
                </section>
            </div>
            <template #footer>
                <AppButton v-if="can.delete" variant="danger" icon="trash-can-outline" class="mr-auto" @click="remove(detail)">Eliminar</AppButton>
                <AppButton @click="detail = null">Cerrar</AppButton>
                <AppButton v-if="can.update" icon="pencil-outline" @click="open(detail)">Editar</AppButton>
            </template>
        </BaseModal>

        <BaseModal v-model:open="interactionOpen" as="form" size="md" title="Registrar interacción" :description="detail?.institution?.name" :persistent="interaction.processing" @submit="saveInteraction">
            <div ref="interactionBody" class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
                <FormField label="Tipo" required :error="interaction.errors.type"><SelectInput v-model="interaction.type" :options="toOptions(options.interaction_types)" /></FormField>
                <FormField label="Fecha y hora" required :error="interaction.errors.occurred_at"><DateTimeInput v-model="interaction.occurred_at" /></FormField>
                <FormField label="Qué se trató" required :error="interaction.errors.description" class="md:col-span-2"><TextArea v-model="interaction.description" maxlength="5000" /></FormField>
                <FormField label="Resultado" optional :error="interaction.errors.result" class="md:col-span-2"><TextInput v-model="interaction.result" maxlength="255" placeholder="Acordó enviar el plan de compras" /></FormField>
                <FormField label="Próxima acción" optional help="Se actualiza en la relación." :error="interaction.errors.next_action_at"><DateInput v-model="interaction.next_action_at" /></FormField>
            </div>
            <template #footer>
                <AppButton :disabled="interaction.processing" @click="interactionOpen = false">Cancelar</AppButton>
                <AppButton variant="primary" type="submit" icon="content-save-outline" :loading="interaction.processing">Registrar interacción</AppButton>
            </template>
        </BaseModal>

        <BaseModal v-model:open="formOpen" as="form" size="lg" :title="isNew ? 'Nueva relación' : 'Editar relación'" :description="isNew ? '' : editing?.institution?.name" :persistent="form.processing" @submit="save">
            <div ref="formBody" class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
                <FormField label="Institución" required :error="form.errors.institution_id">
                    <SearchSelect v-model="form.institution_id" url="/instituciones/buscar" :params="{ todas: 1 }" option-value="id" option-label="name" option-description="description" :selected="editing?.institution ?? null" :min-chars="1" placeholder="Buscar institución…" @update:model-value="form.contact_id = null" />
                </FormField>
                <FormField label="Contacto" optional :error="form.errors.contact_id">
                    <SearchSelect v-model="form.contact_id" url="/contactos/buscar" :params="{ institution_id: form.institution_id }" option-value="id" option-label="name" option-description="description" :selected="editing?.contact ?? null" :min-chars="0" :disabled="!form.institution_id" placeholder="Buscar contacto de la institución…" />
                </FormField>
                <FormField label="Tipo de relación" optional :error="form.errors.relationship_type"><SelectInput v-model="form.relationship_type" :options="toOptions(options.types)" /></FormField>
                <FormField label="Influencia" optional :error="form.errors.influence"><SelectInput v-model="form.influence" :options="toOptions(options.influence)" /></FormField>
                <FormField label="Canal preferido" optional :error="form.errors.channel"><SelectInput v-model="form.channel" :options="toOptions(options.channels)" /></FormField>
                <FormField label="Estado" required :error="form.errors.status"><SelectInput v-model="form.status" :options="toOptions(options.statuses)" /></FormField>
                <FormField label="Inicio de la relación" optional :error="form.errors.started_at"><DateInput v-model="form.started_at" /></FormField>
                <FormField label="Próxima acción" optional :error="form.errors.next_contact_at"><DateInput v-model="form.next_contact_at" /></FormField>
                <FormField label="Interés en el POA" optional :error="form.errors.poa_interest" class="md:col-span-2"><TextInput v-model="form.poa_interest" maxlength="255" placeholder="Renovación de flota 2027" /></FormField>
                <FormField label="Notas" optional :error="form.errors.notes" class="md:col-span-2"><TextArea v-model="form.notes" maxlength="5000" /></FormField>
            </div>
            <template #footer>
                <AppButton :disabled="form.processing" @click="editing = null">Cancelar</AppButton>
                <AppButton variant="primary" type="submit" :icon="isNew ? 'plus' : 'content-save-outline'" :loading="form.processing">{{ isNew ? 'Crear relación' : 'Guardar cambios' }}</AppButton>
            </template>
        </BaseModal>
    </AppLayout>
</template>
