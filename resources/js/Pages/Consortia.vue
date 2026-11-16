<script setup>
// Consorcios de la empresa activa: empresas asociadas para una oferta conjunta. La participación de los
// miembros debe sumar exactamente 100 %; se valida aquí mientras se escribe y en el servidor al guardar.
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppButton from '@/Components/AppButton.vue';
import BaseModal from '@/Components/BaseModal.vue';
import DataTable from '@/Components/DataTable.vue';
import DateInput from '@/Components/DateInput.vue';
import DescriptionList from '@/Components/DescriptionList.vue';
import DropdownMenu from '@/Components/DropdownMenu.vue';
import FormField from '@/Components/FormField.vue';
import IconButton from '@/Components/IconButton.vue';
import MoneyInput from '@/Components/MoneyInput.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import SelectInput from '@/Components/SelectInput.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TextArea from '@/Components/TextArea.vue';
import TextInput from '@/Components/TextInput.vue';
import Toolbar from '@/Components/Toolbar.vue';
import CapAlert from '@design/vue/CapAlert.vue';
import { useConfirm } from '@/composables/useConfirm.js';
import AppLayout from '@/Layouts/AppLayout.vue';
import { fieldError, focusFirstError } from '@/lib/errors.js';
import { EMPTY, date, money, number, percent } from '@/lib/format.js';
import { visit } from '@/lib/http.js';
import ProgressBar from '@/Pages/Tools/Partials/ProgressBar.vue';
import { useListFilters } from '@/Pages/Tools/Partials/useListFilters.js';

const props = defineProps({
    records: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    counts: { type: Object, default: () => ({}) },
    statuses: { type: Array, default: () => [] },
    tenders: { type: Array, default: () => [] },
    companies: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
});

const ONLY = ['records', 'filters', 'counts'];
const { filters, loading, error, active, reset, reload } = useListFilters('/consorcios', props.filters, { only: ONLY, defaults: { search: '', status: 'all' } });

const STATUS = { Activo: 'ok', 'En formación': 'info', Disuelto: 'neutral' };

const confirm = useConfirm();
const detail = ref(null);
const editing = ref(null);
const formBody = ref(null);
const blankMember = () => ({ id: null, name: '', company_id: null, tax_id: '', role: '', email: '', phone: '', participation_percentage: null });
const blank = { name: '', status: 'En formación', tender_id: null, institution_id: null, leader_company_id: null, amount: null, formed_at: '', notes: '', members: [] };
const form = useForm({ ...blank });

const segments = computed(() => [{ label: 'Todos', value: 'all', count: props.counts.all ?? 0 }, ...props.statuses.map((value) => ({ label: value, value, count: props.counts[value] ?? 0 }))]);
const statusOptions = computed(() => props.statuses.map((value) => ({ value, label: value })));
const companyOptions = computed(() => props.companies.map((company) => ({ value: company.id, label: company.name })));
const isNew = computed(() => !editing.value?.id);
const formOpen = computed({ get: () => editing.value !== null, set: (value) => !value && (editing.value = null) });
const detailOpen = computed({ get: () => detail.value !== null, set: (value) => !value && (detail.value = null) });

const membersTotal = computed(() => Math.round(form.members.reduce((sum, member) => sum + (Number(member.participation_percentage) || 0), 0) * 100) / 100);
const totalStatus = computed(() => (form.members.length === 0 ? 'info' : membersTotal.value === 100 ? 'ok' : membersTotal.value > 100 ? 'error' : 'warning'));

const columns = [
    { key: 'name', label: 'Consorcio', primary: true, wrap: true },
    { key: 'tender', label: 'Licitación', hideOnMobile: true },
    { key: 'members', label: 'Miembros', wrap: true },
    { key: 'amount', label: 'Monto (L)', align: 'right', format: (value) => (Number(value) ? money(value, 'HNL', { symbol: false }) : EMPTY) },
    { key: 'status', label: 'Estado' },
];

const memberColumns = [
    { key: 'name', label: 'Miembro', primary: true, wrap: true },
    { key: 'role', label: 'Rol' },
    { key: 'tax_id', label: 'RTN' },
    { key: 'contact', label: 'Contacto', wrap: true },
    { key: 'participation_percentage', label: 'Participación', align: 'right', format: (value) => percent(value, 2) },
];

const detailItems = computed(() => {
    const row = detail.value ?? {};
    return [
        { key: 'status', label: 'Estado', value: row.status },
        { label: 'Empresa líder', value: row.leader_company },
        { label: 'Licitación', value: row.tender ? `${row.tender.name} · ${row.tender.description ?? ''}` : null, wide: true },
        { label: 'Institución', value: row.institution?.name },
        { label: 'Monto', value: Number(row.amount) ? money(row.amount) : null },
        { label: 'Fecha de constitución', value: date(row.formed_at) },
        { label: 'Notas', value: row.notes, wide: true },
    ];
});

function open(row = {}) {
    detail.value = null;
    editing.value = row;
    form.defaults({
        ...Object.fromEntries(Object.keys(blank).map((key) => [key, row[key] ?? blank[key]])),
        members: (row.members ?? []).map((member) => ({ ...blankMember(), ...member })),
    });
    form.reset();
    form.clearErrors();
}

function addMember() {
    form.members.push(blankMember());
}

function balance() {
    if (!form.members.length) return;
    const share = Math.floor((100 / form.members.length) * 100) / 100;
    form.members.forEach((member, index) => {
        member.participation_percentage = index === 0 ? Math.round((100 - share * (form.members.length - 1)) * 100) / 100 : share;
    });
}

function save() {
    const options = { preserveScroll: true, only: [...ONLY, 'flash'], onSuccess: () => (editing.value = null), onError: () => focusFirstError(formBody.value) };
    if (isNew.value) form.post('/consorcios', options);
    else form.put(`/consorcios/${editing.value.id}`, options);
}

function remove(row) {
    confirm({
        verb: 'Eliminar',
        object: `el consorcio ${row.name}`,
        noun: 'consorcio',
        message: 'Se borrarán también sus miembros. No se puede deshacer.',
        icon: 'trash-can-outline',
        action: () => visit('delete', `/consorcios/${row.id}`, {}, { only: [...ONLY, 'flash'], onSuccess: () => (detail.value = null) }),
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
    <AppLayout title="Consorcios" :fab-label="can.create ? 'Nuevo consorcio' : ''" @fab="open()">
        <PageHeader description="Empresas asociadas para presentar ofertas conjuntas.">
            <template #actions>
                <AppButton v-if="can.create && counts.all" variant="primary" icon="plus" @click="open()">Nuevo consorcio</AppButton>
            </template>
        </PageHeader>

        <Toolbar v-model:search="filters.search" v-model:filter="filters.status" :filters="segments" filter-label="Filtrar por estado" search-placeholder="Buscar consorcio o miembro" />

        <div>
            <DataTable
                :columns="columns"
                :rows="records.data"
                :loading="loading"
                :error="error"
                :filtered="active"
                caption="Consorcios"
                clickable
                empty-icon="link-variant"
                empty-title="Aún no hay consorcios"
                empty-description="Crea un consorcio cuando la empresa se asocie con otras para una oferta conjunta."
                @row-click="(row) => (detail = row)"
                @retry="reload"
                @clear-filters="reset"
            >
                <template #cell-tender="{ row }">
                    <div v-if="row.tender" class="min-w-0">
                        <p class="text-gray-900 dark:text-gray-100">{{ row.tender.name }}</p>
                        <p class="truncate text-xs text-cap-text-soft" :title="row.tender.description">{{ row.tender.description }}</p>
                    </div>
                    <span v-else class="text-cap-text-soft">{{ EMPTY }}</span>
                </template>
                <template #cell-members="{ row }">
                    <div class="min-w-0">
                        <p class="text-gray-900 dark:text-gray-100">{{ number(row.members.length) }} {{ row.members.length === 1 ? 'miembro' : 'miembros' }}</p>
                        <p v-if="row.members.length && row.members_total !== 100" class="text-xs text-cap-status-warning-ink">Participación incompleta: {{ percent(row.members_total, 2) }}</p>
                        <p v-else-if="row.members.length" class="truncate text-xs text-cap-text-soft" :title="row.members.map((member) => member.name).join(', ')">{{ row.members.map((member) => member.name).join(', ') }}</p>
                    </div>
                </template>
                <template #cell-status="{ value }"><StatusBadge :status="STATUS[value] ?? 'neutral'" :label="value" /></template>
                <template #actions="{ row }"><DropdownMenu :label="`Acciones de ${row.name}`" :items="actionsOf(row)" /></template>
                <template #empty-action><AppButton v-if="can.create" variant="primary" icon="plus" @click="open()">Nuevo consorcio</AppButton></template>
            </DataTable>
            <Pagination :paginator="records" :only="ONLY" />
        </div>

        <BaseModal v-model:open="detailOpen" size="xl" :title="detail?.name ?? ''" description="Detalle del consorcio">
            <div class="flex flex-col gap-6">
                <DescriptionList :items="detailItems">
                    <template #status="{ item }"><StatusBadge :status="STATUS[item.value] ?? 'neutral'" :label="item.value" /></template>
                </DescriptionList>
                <section>
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <h3 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Miembros</h3>
                        <span class="text-sm tabular-nums text-cap-text-soft">Total {{ percent(detail?.members_total ?? 0, 2) }}</span>
                    </div>
                    <ProgressBar class="mb-4" :value="detail?.members_total ?? 0" :status="detail?.members_total === 100 ? 'ok' : 'warning'" label="Participación asignada" />
                    <DataTable :columns="memberColumns" :rows="detail?.members ?? []" dense caption="Miembros del consorcio" empty-icon="account-group-outline" empty-title="Sin miembros" empty-description="Edita el consorcio para agregar a las empresas que lo forman.">
                        <template #cell-contact="{ row }">{{ [row.email, row.phone].filter(Boolean).join(' · ') || EMPTY }}</template>
                    </DataTable>
                </section>
            </div>
            <template #footer>
                <AppButton v-if="can.delete" variant="danger" icon="trash-can-outline" class="mr-auto" @click="remove(detail)">Eliminar</AppButton>
                <AppButton @click="detail = null">Cerrar</AppButton>
                <AppButton v-if="can.update" variant="primary" icon="pencil-outline" @click="open(detail)">Editar</AppButton>
            </template>
        </BaseModal>

        <BaseModal v-model:open="formOpen" as="form" size="xl" :title="isNew ? 'Nuevo consorcio' : 'Editar consorcio'" :description="isNew ? '' : editing?.name" :persistent="form.processing" @submit="save">
            <div ref="formBody" class="flex flex-col gap-8">
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
                    <FormField label="Nombre" required :error="form.errors.name"><TextInput v-model="form.name" maxlength="255" placeholder="Consorcio Vial Norte" /></FormField>
                    <FormField label="Estado" required :error="form.errors.status"><SelectInput v-model="form.status" :options="statusOptions" /></FormField>
                    <FormField label="Licitación" optional :error="form.errors.tender_id">
                        <SearchSelect v-model="form.tender_id" :options="tenders" option-value="id" option-label="name" option-description="description" :selected="editing?.tender ?? null" placeholder="Buscar por expediente…" />
                    </FormField>
                    <FormField label="Institución" optional :error="form.errors.institution_id">
                        <SearchSelect v-model="form.institution_id" url="/instituciones/buscar" :params="{ todas: 1 }" option-value="id" option-label="name" option-description="description" :selected="editing?.institution ?? null" :min-chars="1" placeholder="Buscar institución…" />
                    </FormField>
                    <FormField label="Empresa líder del grupo" optional :error="form.errors.leader_company_id"><SelectInput v-model="form.leader_company_id" :options="companyOptions" placeholder="Ninguna" /></FormField>
                    <FormField label="Monto de la oferta" optional :error="form.errors.amount"><MoneyInput v-model="form.amount" :min="0" /></FormField>
                    <FormField label="Fecha de constitución" optional :error="form.errors.formed_at"><DateInput v-model="form.formed_at" /></FormField>
                    <FormField label="Notas" optional :error="form.errors.notes" class="md:col-span-2"><TextArea v-model="form.notes" rows="3" maxlength="5000" /></FormField>
                </div>

                <section>
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Miembros</h3>
                            <p class="text-xs text-cap-text-soft">La participación debe sumar exactamente 100 %.</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <AppButton v-if="form.members.length > 1" icon="scale-balance" @click="balance">Repartir en partes iguales</AppButton>
                            <AppButton icon="account-plus-outline" @click="addMember">Agregar miembro</AppButton>
                        </div>
                    </div>

                    <div class="mb-4 flex flex-col gap-2">
                        <ProgressBar :value="membersTotal" :status="totalStatus" label="Participación asignada" show-value />
                        <CapAlert v-if="form.errors.members" status="error" title="Revisa la participación" :detail="form.errors.members" />
                        <p v-else-if="form.members.length && membersTotal !== 100" class="text-xs text-cap-status-warning-ink">Faltan {{ percent(Math.round((100 - membersTotal) * 100) / 100, 2) }} por asignar.</p>
                    </div>

                    <p v-if="!form.members.length" class="rounded-cap-control bg-cap-card-2 p-4 text-sm text-cap-text-soft">Aún no hay miembros. Puedes guardar el consorcio y agregarlos después.</p>
                    <div v-else class="flex flex-col gap-3">
                        <div v-for="(member, index) in form.members" :key="member.id ?? `nuevo-${index}`" class="rounded-cap-control bg-cap-card-2 p-3">
                            <div class="mb-2 flex items-center justify-between gap-2">
                                <p class="text-xs font-semibold text-cap-text-soft">Miembro {{ index + 1 }}</p>
                                <IconButton icon="trash-can-outline" :label="`Quitar a ${member.name || `miembro ${index + 1}`}`" @click="form.members.splice(index, 1)" />
                            </div>
                            <div class="grid grid-cols-1 gap-x-4 gap-y-3 md:grid-cols-6">
                                <FormField label="Nombre" required :error="fieldError(form.errors, `members.${index}.name`)" class="md:col-span-3"><TextInput v-model="member.name" maxlength="255" /></FormField>
                                <FormField label="Participación %" required :error="fieldError(form.errors, `members.${index}.participation_percentage`)" class="md:col-span-1"><TextInput v-model="member.participation_percentage" type="number" min="0.01" max="100" step="0.01" /></FormField>
                                <FormField label="Rol" optional :error="fieldError(form.errors, `members.${index}.role`)" class="md:col-span-2"><TextInput v-model="member.role" maxlength="255" placeholder="Líder, socio técnico…" /></FormField>
                                <FormField label="RTN" optional :error="fieldError(form.errors, `members.${index}.tax_id`)" class="md:col-span-2"><TextInput v-model="member.tax_id" maxlength="100" inputmode="numeric" /></FormField>
                                <FormField label="Correo" optional :error="fieldError(form.errors, `members.${index}.email`)" class="md:col-span-2"><TextInput v-model="member.email" type="email" maxlength="255" /></FormField>
                                <FormField label="Teléfono" optional :error="fieldError(form.errors, `members.${index}.phone`)" class="md:col-span-2"><TextInput v-model="member.phone" type="tel" maxlength="50" /></FormField>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
            <template #footer>
                <AppButton :disabled="form.processing" @click="editing = null">Cancelar</AppButton>
                <AppButton variant="primary" type="submit" :icon="isNew ? 'plus' : 'content-save-outline'" :loading="form.processing">{{ isNew ? 'Crear consorcio' : 'Guardar cambios' }}</AppButton>
            </template>
        </BaseModal>
    </AppLayout>
</template>
