<script setup>
// KPI y metas de la empresa activa: valor real del período contra la meta que lo cubre, gráfica de
// cumplimiento y administración de metas (solo administradores de la empresa).
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import AppButton from '@/Components/AppButton.vue';
import BaseModal from '@/Components/BaseModal.vue';
import DataTable from '@/Components/DataTable.vue';
import DateInput from '@/Components/DateInput.vue';
import DropdownMenu from '@/Components/DropdownMenu.vue';
import FormField from '@/Components/FormField.vue';
import IconButton from '@/Components/IconButton.vue';
import MoneyInput from '@/Components/MoneyInput.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import SelectInput from '@/Components/SelectInput.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TextInput from '@/Components/TextInput.vue';
import { useConfirm } from '@/composables/useConfirm.js';
import AppLayout from '@/Layouts/AppLayout.vue';
import { focusFirstError } from '@/lib/errors.js';
import { dateRange, money, percent } from '@/lib/format.js';
import { visit } from '@/lib/http.js';
import ChartCard from '@/Components/Charts/ChartCard.vue';
import LoadError from '@/Pages/Reports/Partials/LoadError.vue';
import PeriodFilter from '@/Pages/Reports/Partials/PeriodFilter.vue';
import { cleanQuery, useChartPalette } from '@/Pages/Reports/Partials/finance.js';

const props = defineProps({
    period: { type: Object, required: true },
    kpis: { type: Array, required: true },
    goals: { type: Object, required: true },
    metrics: { type: Array, required: true },
    can: { type: Object, default: () => ({}) },
});

const page = usePage();
const confirm = useConfirm();
const palette = useChartPalette();
const filters = reactive({ ...props.period });
const loading = ref(false);
const loadError = ref('');
const modalOpen = ref(false);
const editing = ref(null);
const formRoot = ref(null);
const form = useForm({ metric: 'pipeline', target: null, period_start: '', period_end: '' });

const unitOf = (metric) => props.metrics.find((item) => item.value === metric)?.unit ?? 'money';
const show = (value, unit) => (value === null || value === undefined ? '—' : unit === 'percent' ? percent(value) : money(value));
const compliance = (kpi) => (kpi.goal ? Math.round(((kpi.actual ?? 0) / kpi.goal) * 1000) / 10 : null);

function standing(kpi) {
    const value = compliance(kpi);
    if (value === null) return { status: 'neutral', label: 'Sin meta' };
    if (value >= 100) return { status: 'ok', label: 'Cumplida' };
    if (value >= 70) return { status: 'warning', label: 'En camino' };
    return { status: 'error', label: 'Rezagada' };
}

const withGoal = computed(() => props.kpis.filter((kpi) => kpi.goal));
const chartMax = computed(() => Math.max(100, ...withGoal.value.map((kpi) => compliance(kpi) ?? 0)));
const barColor = (kpi) => palette.value.status[standing(kpi).status] ?? palette.value.series[0];

const goalColumns = [
    { key: 'label', label: 'Indicador', primary: true },
    { key: 'period_start', label: 'Período', format: (value, row) => dateRange(value, row.period_end) },
    { key: 'target', label: 'Meta', align: 'right', format: (value, row) => show(value, row.unit) },
];

function apply(changes = {}) {
    Object.assign(filters, changes);
    loadError.value = '';
    router.get('/kpis', cleanQuery({ from: filters.from, to: filters.to }), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['period', 'kpis', 'errors'],
        onStart: () => (loading.value = true),
        onFinish: () => (loading.value = false),
        onHttpException: () => {
            loadError.value = 'El servidor no pudo calcular los indicadores. Vuelve a intentarlo en un momento.';
            return false;
        },
        onNetworkError: () => {
            loadError.value = 'No hay conexión con el servidor. Revisa tu red y vuelve a intentarlo.';
            return false;
        },
    });
}

function openGoal(source = null) {
    editing.value = source?.id && source.period_start ? source : null;
    form.defaults({
        metric: source?.metric ?? 'pipeline',
        target: source?.target ?? source?.goal ?? null,
        period_start: source?.period_start ?? props.period.from,
        period_end: source?.period_end ?? props.period.to,
    });
    form.reset();
    form.clearErrors();
    modalOpen.value = true;
}

// Desde una tarjeta: si ya tiene meta, se edita esa; si no, se crea para el período en pantalla.
function openFromCard(kpi) {
    if (kpi.goal_id) {
        openGoal({ id: kpi.goal_id, metric: kpi.metric, target: kpi.goal, period_start: kpi.goal_period.from, period_end: kpi.goal_period.to });
    } else {
        openGoal({ metric: kpi.metric });
    }
}

function save() {
    const options = {
        preserveScroll: true,
        onSuccess: () => (modalOpen.value = false),
        onError: () => focusFirstError(formRoot.value ?? document),
    };
    if (editing.value) form.put(`/kpis/${editing.value.id}`, options);
    else form.post('/kpis', options);
}

function remove(goal) {
    confirm({
        verb: 'Eliminar',
        object: `la meta de ${goal.label}`,
        noun: 'meta',
        message: `Se quitará la meta del ${dateRange(goal.period_start, goal.period_end)}. El indicador seguirá calculándose sin meta.`,
        action: () => visit('delete', `/kpis/${goal.id}`),
    });
}

const goalActions = (goal) => [
    { label: 'Editar meta', icon: 'pencil-outline', onSelect: () => openGoal(goal) },
    { label: 'Eliminar meta', icon: 'trash-can-outline', danger: true, separator: true, onSelect: () => remove(goal) },
];
</script>

<template>
    <AppLayout title="KPI y metas" :sections="[{ label: 'Reportes', href: '/reportes' }, { label: 'KPI y metas', href: '/kpis', active: true }]">
        <PageHeader :description="`Valores reales del ${dateRange(period.from, period.to, 'medium')} contra la meta que cubre el período`">
            <template #actions>
                <AppButton v-if="can.manage" variant="primary" icon="bullseye-arrow" @click="openGoal()">Fijar meta</AppButton>
            </template>
        </PageHeader>

        <section class="rounded-cap-card border border-cap-divider bg-cap-card p-4 sm:p-5" aria-label="Período de los indicadores">
            <PeriodFilter v-model:from="filters.from" v-model:to="filters.to" :errors="page.props.errors ?? {}" @apply="apply" />
        </section>

        <LoadError v-if="loadError" title="No se pudieron actualizar los indicadores" :message="loadError" @retry="apply()" />

        <div class="cap-stagger grid grid-cols-1 gap-4 transition-opacity duration-cap-fast ease-cap md:grid-cols-2 xl:grid-cols-3" :class="{ 'opacity-50': loading }">
            <article v-for="kpi in kpis" :key="kpi.metric" class="flex flex-col gap-3 rounded-cap-card border border-cap-divider bg-cap-card p-5">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <h2 class="text-xs font-medium text-cap-text-soft">{{ kpi.label }}</h2>
                        <p class="mt-2 font-display text-3xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ show(kpi.actual, kpi.unit) }}</p>
                    </div>
                    <IconButton v-if="can.manage" :icon="kpi.goal_id ? 'pencil-outline' : 'bullseye-arrow'" :label="kpi.goal_id ? `Editar la meta de ${kpi.label}` : `Fijar meta de ${kpi.label}`" @click="openFromCard(kpi)" />
                </div>
                <div class="flex items-center justify-between gap-2 text-sm">
                    <span class="text-cap-text-soft">Meta: <span class="tabular-nums text-gray-900 dark:text-gray-100">{{ show(kpi.goal, kpi.unit) }}</span></span>
                    <StatusBadge :status="standing(kpi).status" :label="standing(kpi).label" />
                </div>
                <div
                    class="h-2 overflow-hidden rounded-full bg-cap-card-2"
                    role="progressbar"
                    :aria-label="`Cumplimiento de ${kpi.label}`"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    :aria-valuenow="Math.min(100, compliance(kpi) ?? 0)"
                >
                    <div class="m-fill h-full rounded-full transition-[width] duration-cap-slow ease-cap" :style="{ width: `${Math.min(100, compliance(kpi) ?? 0)}%`, backgroundColor: barColor(kpi) }" />
                </div>
                <p class="text-xs text-cap-text-soft">
                    <template v-if="kpi.goal">{{ percent(compliance(kpi)) }} de la meta · </template>{{ kpi.description }}
                </p>
            </article>
        </div>

        <ChartCard
            title="Cumplimiento de metas"
            description="Valor real ÷ meta. La línea marca el 100 %."
            :empty="withGoal.length === 0"
            :empty-text="can.manage ? 'Ningún indicador tiene meta para este período. Fija una para medir el cumplimiento.' : 'Ningún indicador tiene meta para este período.'"
        >
            <ol v-stagger class="flex flex-col gap-3" :class="{ 'opacity-50': loading }">
                <li v-for="kpi in withGoal" :key="kpi.metric" class="grid grid-cols-1 gap-1 sm:grid-cols-[12rem_1fr] sm:items-center sm:gap-3">
                    <span class="truncate text-sm text-gray-900 dark:text-gray-100" :title="kpi.label">{{ kpi.label }}</span>
                    <div class="flex min-w-0 items-center gap-3">
                        <div class="relative h-6 min-w-0 flex-1 rounded-md bg-cap-card-2">
                            <div class="m-fill h-full rounded-md transition-[width] duration-cap-slow ease-cap" :style="{ width: `${Math.max(1, ((compliance(kpi) ?? 0) / chartMax) * 100)}%`, backgroundColor: barColor(kpi) }" />
                            <span class="absolute inset-y-0 w-0.5 bg-gray-900 dark:bg-gray-100" :style="{ left: `${(100 / chartMax) * 100}%` }" aria-hidden="true" />
                        </div>
                        <span class="w-16 shrink-0 text-right text-sm font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ percent(compliance(kpi)) }}</span>
                    </div>
                </li>
            </ol>
        </ChartCard>

        <section class="flex flex-col gap-3">
            <h2 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Metas registradas</h2>
            <DataTable
                :columns="goalColumns"
                :rows="goals.data"
                caption="Metas registradas"
                empty-icon="bullseye-arrow"
                empty-title="Aún no hay metas"
                :empty-description="can.manage ? 'Fija la primera meta para medir el cumplimiento de los indicadores.' : 'El administrador de la empresa fija las metas.'"
            >
                <template v-if="can.manage" #actions="{ row }">
                    <DropdownMenu :label="`Acciones de la meta de ${row.label}`" :items="goalActions(row)" />
                </template>
            </DataTable>
            <Pagination :paginator="goals" :per-page-options="[]" :only="['goals']" />
        </section>

        <BaseModal v-model:open="modalOpen" as="form" size="md" :title="editing ? 'Editar meta' : 'Fijar meta'" description="Una meta por indicador y período. Si ya existe para ese período, se actualiza." :persistent="form.processing" @submit="save">
            <div ref="formRoot" class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
                <FormField label="Indicador" required :error="form.errors.metric" class="md:col-span-2">
                    <SelectInput v-model="form.metric" :options="metrics" placeholder="" :disabled="Boolean(editing)" />
                </FormField>
                <FormField :label="unitOf(form.metric) === 'percent' ? 'Meta (%)' : 'Meta (L)'" required :error="form.errors.target" class="md:col-span-2">
                    <MoneyInput v-if="unitOf(form.metric) === 'money'" v-model="form.target" :min="0" />
                    <TextInput v-else v-model="form.target" type="number" min="0" max="100" step="0.1" class="max-w-28" />
                </FormField>
                <FormField label="Inicio del período" required :error="form.errors.period_start">
                    <DateInput v-model="form.period_start" />
                </FormField>
                <FormField label="Fin del período" required :error="form.errors.period_end">
                    <DateInput v-model="form.period_end" :min="form.period_start" />
                </FormField>
            </div>
            <template #footer>
                <AppButton :disabled="form.processing" @click="modalOpen = false">Cancelar</AppButton>
                <AppButton variant="primary" type="submit" icon="content-save-outline" :loading="form.processing">Guardar meta</AppButton>
            </template>
        </BaseModal>
    </AppLayout>
</template>
