<script setup>
// Matrices documentales de la empresa activa: por licitación, cuántos requisitos cumplen, están
// pendientes o vencidos. La ficha de cada matriz está en ComplianceMatrices/Show.
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppButton from '@/Components/AppButton.vue';
import BaseModal from '@/Components/BaseModal.vue';
import DataTable from '@/Components/DataTable.vue';
import DateInput from '@/Components/DateInput.vue';
import FormField from '@/Components/FormField.vue';
import IconButton from '@/Components/IconButton.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import TextInput from '@/Components/TextInput.vue';
import Toolbar from '@/Components/Toolbar.vue';
import CapAlert from '@design/vue/CapAlert.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { fieldError, focusFirstError } from '@/lib/errors.js';
import { EMPTY, date, number, relativeDays } from '@/lib/format.js';
import ProgressBar from '@/Components/ProgressBar.vue';
import { useListFilters } from '@/composables/useListFilters.js';

const props = defineProps({
    records: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    tenders: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
});

const ONLY = ['records', 'filters'];
const { filters, loading, error, active, reset, reload } = useListFilters('/matriz-cumplimiento', props.filters, { only: ONLY, defaults: { search: '', filter: 'all' } });

// Requisitos que piden casi todos los pliegos; el usuario los ajusta antes de guardar.
const COMMON = ['RTN numérico de la empresa', 'Escritura de constitución', 'Poder del representante legal', 'DNI del representante legal', 'Solvencia fiscal (SAR)', 'Solvencia municipal', 'Constancia de inscripción en ONCAE', 'Constancia del IHSS'];

const segments = [
    { label: 'Todas', value: 'all' },
    { label: 'Con pendientes', value: 'pending' },
    { label: 'Con vencidos', value: 'expired' },
    { label: 'Completas', value: 'complete' },
];

const columns = [
    { key: 'name', label: 'Matriz', primary: true, wrap: true },
    { key: 'progress', label: 'Progreso', width: 'w-56' },
    { key: 'status', label: 'Requisitos', wrap: true },
    { key: 'next_due_at', label: 'Próximo vencimiento', hideOnMobile: true },
];

const creating = ref(false);
const formBody = ref(null);
const form = useForm({ tender_id: null, name: 'Documentos de la oferta', items: [] });
const total = computed(() => props.records.total ?? props.records.data.length);

function open() {
    form.defaults({ tender_id: null, name: 'Documentos de la oferta', items: [{ requirement: '', renewal_due_at: '' }] });
    form.reset();
    form.clearErrors();
    creating.value = true;
}

function addCommon() {
    const present = new Set(form.items.map((item) => item.requirement.trim().toLowerCase()));
    form.items = [...form.items.filter((item) => item.requirement.trim()), ...COMMON.filter((name) => !present.has(name.toLowerCase())).map((requirement) => ({ requirement, renewal_due_at: '' }))];
}

function save() {
    form.post('/matriz-cumplimiento', { onSuccess: () => (creating.value = false), onError: () => focusFirstError(formBody.value) });
}

function show(row) {
    router.visit(`/matriz-cumplimiento/${row.id}`);
}

function progressStatus(row) {
    if (row.expired > 0) return 'error';
    return row.progress === 100 ? 'ok' : 'warning';
}
</script>

<template>
    <AppLayout title="Matriz documental" :fab-label="can.create ? 'Nueva matriz' : ''" @fab="open">
        <PageHeader description="Requisitos documentales de cada licitación, con su estado y vencimiento.">
            <template #actions>
                <AppButton v-if="can.create && total" variant="primary" icon="plus" @click="open">Nueva matriz</AppButton>
            </template>
        </PageHeader>

        <Toolbar v-model:search="filters.search" v-model:filter="filters.filter" :filters="segments" filter-label="Filtrar por estado" search-placeholder="Buscar matriz o expediente" />

        <div>
            <DataTable
                :columns="columns"
                :rows="records.data"
                :loading="loading"
                :error="error"
                :filtered="active"
                caption="Matrices documentales"
                clickable
                empty-icon="clipboard-check-outline"
                empty-title="Aún no hay matrices documentales"
                empty-description="Crea una matriz por licitación con los documentos que exige el pliego para saber qué falta antes de presentar."
                @row-click="show"
                @retry="reload"
                @clear-filters="reset"
            >
                <template #cell-name="{ row }">
                    <div class="min-w-0">
                        <p class="font-medium text-gray-900 dark:text-gray-100">{{ row.name }}</p>
                        <p v-if="row.tender" class="truncate text-xs text-cap-text-soft" :title="row.tender.title">{{ row.tender.reference }} · {{ row.tender.title }}</p>
                    </div>
                </template>
                <template #cell-progress="{ row }"><ProgressBar :value="row.progress" :status="progressStatus(row)" :label="`Progreso de ${row.name}`" show-value /></template>
                <template #cell-status="{ row }">
                    <p class="text-sm tabular-nums">
                        <span class="text-cap-status-ok-ink">{{ number(row.complies) }} cumplen</span>
                        <span class="text-cap-text-soft"> · </span>
                        <span :class="row.pending ? 'text-cap-status-warning-ink' : 'text-cap-text-soft'">{{ number(row.pending) }} pendientes</span>
                        <template v-if="row.expired"><span class="text-cap-text-soft"> · </span><span class="text-cap-status-error-ink">{{ number(row.expired) }} vencidos</span></template>
                    </p>
                </template>
                <template #cell-next_due_at="{ value }">
                    <span v-if="value">{{ date(value) }} <span class="text-xs text-cap-text-soft">({{ relativeDays(value) }})</span></span>
                    <span v-else class="text-cap-text-soft">{{ EMPTY }}</span>
                </template>
                <template #empty-action><AppButton v-if="can.create" variant="primary" icon="plus" @click="open">Nueva matriz</AppButton></template>
            </DataTable>
            <Pagination :paginator="records" :only="ONLY" />
        </div>

        <BaseModal v-model:open="creating" as="form" size="xl" title="Nueva matriz documental" :persistent="form.processing" @submit="save">
            <div ref="formBody" class="flex flex-col gap-8">
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
                    <FormField label="Licitación" required :error="form.errors.tender_id">
                        <SearchSelect v-model="form.tender_id" :options="tenders" option-value="id" option-label="name" option-description="description" placeholder="Buscar por expediente…" />
                    </FormField>
                    <FormField label="Nombre de la matriz" required :error="form.errors.name"><TextInput v-model="form.name" maxlength="255" /></FormField>
                </div>
                <CapAlert v-if="!tenders.length" status="info" title="No hay licitaciones en la empresa activa" detail="Registra primero la licitación para poder armar su matriz documental." />

                <section>
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Requisitos</h3>
                            <p class="text-xs text-cap-text-soft">Un documento por fila, como lo pide el pliego.</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <AppButton icon="playlist-plus" @click="addCommon">Agregar los habituales</AppButton>
                            <AppButton icon="plus" @click="form.items.push({ requirement: '', renewal_due_at: '' })">Agregar requisito</AppButton>
                        </div>
                    </div>
                    <CapAlert v-if="form.errors.items" class="mb-3" status="error" title="Faltan requisitos" :detail="form.errors.items" />
                    <div class="flex flex-col gap-3">
                        <div v-for="(item, index) in form.items" :key="index" class="grid grid-cols-[1fr_auto] items-start gap-3 rounded-cap-control bg-cap-card-2 p-3 md:grid-cols-[1fr_160px_auto]">
                            <FormField :label="`Requisito ${index + 1}`" required :error="fieldError(form.errors, `items.${index}.requirement`)"><TextInput v-model="item.requirement" maxlength="255" /></FormField>
                            <FormField label="Vence" optional :error="fieldError(form.errors, `items.${index}.renewal_due_at`)" class="col-span-2 row-start-2 md:col-span-1 md:row-start-auto"><DateInput v-model="item.renewal_due_at" /></FormField>
                            <IconButton class="mt-6" icon="trash-can-outline" :label="`Quitar requisito ${index + 1}`" :disabled="form.items.length === 1" @click="form.items.splice(index, 1)" />
                        </div>
                    </div>
                </section>
            </div>
            <template #footer>
                <AppButton :disabled="form.processing" @click="creating = false">Cancelar</AppButton>
                <AppButton variant="primary" type="submit" icon="plus" :loading="form.processing">Crear matriz</AppButton>
            </template>
        </BaseModal>
    </AppLayout>
</template>
