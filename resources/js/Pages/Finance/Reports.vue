<script setup>
// Reportes e indicadores de la empresa activa: cifras del período, embudo del kanban comercial,
// adjudicado contra cobrado por mes, tabla de indicadores y exportes (PDF, XLSX, CSV) con los filtros.
import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import DataTable from '../../Components/DataTable.vue';
import DropdownMenu from '../../Components/DropdownMenu.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatCard from '../../Components/StatCard.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { dateRange, money, number, percent } from '../../lib/format.js';
import BarChart from './Partials/BarChart.vue';
import ChartCard from './Partials/ChartCard.vue';
import LoadError from './Partials/LoadError.vue';
import PeriodFilter from './Partials/PeriodFilter.vue';
import { cleanQuery, compactMoney, monthLabel, useChartPalette } from './Partials/finance.js';

const props = defineProps({
    report: { type: Object, required: true },
    modules: { type: Array, default: () => [] },
});

const page = usePage();
const palette = useChartPalette();
const filters = reactive({ ...props.report.filters });
const loading = ref(false);
const loadError = ref('');

const metrics = computed(() => props.report.metrics);
const query = computed(() => new URLSearchParams(cleanQuery({ ...props.report.filters }, { module: 'all' })).toString());
const exportItems = computed(() => [
    { label: 'Descargar PDF', icon: 'file-pdf-box', href: `/reportes/pdf?${query.value}`, external: true },
    { label: 'Descargar Excel (XLSX)', icon: 'file-excel-outline', href: `/reportes/xlsx?${query.value}`, external: true },
    { label: 'Descargar CSV', icon: 'file-delimited-outline', href: `/reportes/csv?${query.value}`, external: true },
]);

const funnelMax = computed(() => Math.max(1, ...props.report.funnel.map((stage) => stage.count)));
const funnelEmpty = computed(() => props.report.funnel.every((stage) => stage.count === 0));
const monthly = computed(() => props.report.monthly);
const monthlyEmpty = computed(() => monthly.value.every((month) => !month.won && !month.collected));
const monthCategories = computed(() => monthly.value.map((month) => monthLabel(month.month, monthly.value.length > 6)));
const monthSeries = computed(() => [
    { key: 'won', label: 'Adjudicado', values: monthly.value.map((month) => month.won) },
    { key: 'collected', label: 'Cobrado', values: monthly.value.map((month) => month.collected) },
]);

const awardHint = computed(() => {
    const { won_count: won, lost_count: lost, pending_count: pending } = metrics.value;
    return `${number(won)} ganadas · ${number(lost)} perdidas · ${number(pending)} pendientes`;
});

const columns = [
    { key: 'label', label: 'Indicador', primary: true },
    { key: 'module_label', label: 'Módulo' },
    { key: 'count', label: 'Registros', align: 'right', format: (value) => number(value) },
    { key: 'amount', label: 'Monto (L)', align: 'right', format: (value) => money(value, 'HNL', { symbol: false }) },
];

// Etapas activas en el neutro cálido de la paleta; los resultados con el color de su estado.
function stageColor(stage) {
    if (stage === 'adjudicado') return palette.value.status.ok;
    if (stage === 'no_adjudicado') return palette.value.status.error;
    return palette.value.series[2];
}

function apply(changes = {}) {
    Object.assign(filters, changes);
    loadError.value = '';
    router.get('/reportes', cleanQuery({ ...filters }, { module: 'all' }), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['report', 'errors'],
        onStart: () => (loading.value = true),
        onFinish: () => (loading.value = false),
        onHttpException: () => {
            loadError.value = 'El servidor no pudo calcular el reporte. Vuelve a intentarlo en un momento.';
            return false;
        },
        onNetworkError: () => {
            loadError.value = 'No hay conexión con el servidor. Revisa tu red y vuelve a intentarlo.';
            return false;
        },
    });
}
</script>

<template>
    <AppLayout title="Reportes e indicadores" :sections="[{ label: 'Reportes', href: '/reportes', active: true }, { label: 'KPI y metas', href: '/kpis' }]">
        <PageHeader :description="`Cifras de la empresa activa del ${dateRange(report.filters.from, report.filters.to, 'medium')}`">
            <template #actions>
                <DropdownMenu :items="exportItems" label="Exportar reporte" width="w-60">
                    <template #trigger="{ toggle, attrs }">
                        <button type="button" class="cap-button cap-button--secondary" v-bind="attrs" @click="toggle">
                            <i class="mdi mdi-download-outline text-xl leading-none" aria-hidden="true" />Exportar
                        </button>
                    </template>
                </DropdownMenu>
            </template>
        </PageHeader>

        <section class="rounded-cap-card border border-cap-divider bg-cap-card p-4 sm:p-5" aria-label="Filtros del reporte">
            <PeriodFilter v-model:from="filters.from" v-model:to="filters.to" v-model:module="filters.module" :modules="modules" :errors="page.props.errors ?? {}" @apply="apply" />
        </section>

        <LoadError v-if="loadError" title="No se pudo actualizar el reporte" :message="loadError" @retry="apply()" />

        <div class="cap-stagger grid grid-cols-1 gap-4 transition-opacity duration-cap-fast ease-cap sm:grid-cols-2 xl:grid-cols-4" :class="{ 'opacity-50': loading }">
            <StatCard variant="featured" label="Pipeline ponderado" :value="money(metrics.pipeline_weighted)" :hint="`${number(metrics.pipeline_count)} oportunidades activas`" :chip="`de ${compactMoney(metrics.pipeline_amount)}`" href="/pipeline" />
            <StatCard label="Monto adjudicado" :value="money(metrics.won_amount)" :hint="`${number(metrics.won_count)} cotizaciones ganadas`" />
            <StatCard label="Tasa de adjudicación" :value="metrics.award_rate === null ? '—' : percent(metrics.award_rate)" :hint="awardHint" />
            <StatCard label="Cobros recibidos" :value="money(metrics.collected)" :hint="`${number(metrics.collected_count)} facturas pagadas`" href="/cobros" />
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-2" :class="{ 'opacity-50': loading }">
            <ChartCard title="Embudo comercial" description="Oportunidades creadas en el período, por etapa del kanban." :empty="funnelEmpty" empty-text="No se crearon oportunidades en el período.">
                <ol v-stagger class="flex flex-col gap-3">
                    <li v-for="stage in report.funnel" :key="stage.stage" class="grid grid-cols-[minmax(0,8rem)_1fr] items-center gap-3 sm:grid-cols-[10rem_1fr]">
                        <span class="truncate text-sm text-gray-900 dark:text-gray-100" :title="stage.label">{{ stage.label }}</span>
                        <div class="flex min-w-0 items-center gap-3">
                            <div class="h-7 min-w-0 flex-1 rounded-md bg-cap-card-2">
                                <div
                                    class="m-fill h-full rounded-md transition-[width] duration-cap-slow ease-cap"
                                    :style="{ width: `${Math.max(stage.count ? 3 : 0, (stage.count / funnelMax) * 100)}%`, backgroundColor: stageColor(stage.stage) }"
                                />
                            </div>
                            <span class="w-28 shrink-0 text-right text-xs tabular-nums text-cap-text-soft"><strong class="text-sm text-gray-900 dark:text-gray-100">{{ number(stage.count) }}</strong> · {{ money(stage.amount) }}</span>
                        </div>
                    </li>
                </ol>
            </ChartCard>

            <ChartCard title="Adjudicado y cobrado por mes" description="Cotizaciones ganadas por fecha de cotización y facturas por fecha de pago." :empty="monthlyEmpty">
                <BarChart :categories="monthCategories" :series="monthSeries" title="Adjudicado y cobrado por mes" />
            </ChartCard>
        </div>

        <section class="flex flex-col gap-3">
            <h2 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Indicadores del período</h2>
            <DataTable
                :columns="columns"
                :rows="report.rows"
                :row-key="(row) => row.label"
                :loading="loading"
                caption="Indicadores del período"
                dense
                empty-icon="chart-box-outline"
                empty-title="Sin indicadores para este filtro"
                empty-description="Elige otro módulo o amplía el período."
            />
        </section>
    </AppLayout>
</template>
