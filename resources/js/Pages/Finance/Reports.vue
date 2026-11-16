<script setup>
// Reportes e indicadores de la empresa activa: cifras del período, embudo del kanban comercial,
// adjudicado contra cobrado por mes, tabla de indicadores y exportes (PDF, XLSX, CSV) con los filtros.
//
// Carga: `report` es una prop diferida. La página llega al instante con sus filtros y un esqueleto con la
// forma del contenido; las cifras entran en una segunda petición. Al cambiar un filtro solo se pide
// `report` (petición parcial), la petición anterior se cancela y lo ya pintado se atenúa sin congelarse.
// Las cifras salen de `BusinessMetrics`, la misma definición del Dashboard y de KPI.
import { Deferred, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import CapSkeleton from '@design/vue/CapSkeleton.vue';
import DataTable from '@/Components/DataTable.vue';
import DropdownMenu from '@/Components/DropdownMenu.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { dateRange, money, number, percent } from '@/lib/format.js';
import BarChart from '@/Pages/Finance/Partials/BarChart.vue';
import ChartCard from '@/Pages/Finance/Partials/ChartCard.vue';
import LoadError from '@/Pages/Finance/Partials/LoadError.vue';
import PeriodFilter from '@/Pages/Finance/Partials/PeriodFilter.vue';
import { cleanQuery, compactMoney, monthLabel, useChartPalette } from '@/Pages/Finance/Partials/finance.js';

const props = defineProps({
    filters: { type: Object, required: true },
    report: { type: Object, default: null },
    modules: { type: Array, default: () => [] },
});

const page = usePage();
const palette = useChartPalette();
const filters = reactive({ module: 'all', ...props.filters });
const loading = ref(false);
const loadError = ref('');
let cancelToken = null;

const metrics = computed(() => props.report?.metrics ?? {});
const funnel = computed(() => props.report?.funnel ?? []);
const rows = computed(() => props.report?.rows ?? []);
const moduleLabel = computed(() => props.modules.find((item) => item.value === props.filters.module)?.label ?? '');

const query = computed(() => new URLSearchParams(cleanQuery({ ...props.filters }, { module: 'all' })).toString());
const exportItems = computed(() => [
    { label: 'Descargar PDF', icon: 'file-pdf-box', href: `/reportes/pdf?${query.value}`, external: true },
    { label: 'Descargar Excel (XLSX)', icon: 'file-excel-outline', href: `/reportes/xlsx?${query.value}`, external: true },
    { label: 'Descargar CSV', icon: 'file-delimited-outline', href: `/reportes/csv?${query.value}`, external: true },
]);

const funnelMax = computed(() => Math.max(1, ...funnel.value.map((stage) => Number(stage.count) || 0)));
const funnelEmpty = computed(() => funnel.value.every((stage) => !Number(stage.count)));
// Serie mensual saneada: un valor que falte se dibuja como 0, nunca como NaN.
const monthly = computed(() => (props.report?.monthly ?? []).map((month) => ({ month: month.month, won: Number(month.won) || 0, collected: Number(month.collected) || 0 })));
const monthlyEmpty = computed(() => monthly.value.every((month) => !month.won && !month.collected));
const monthCategories = computed(() => monthly.value.map((month) => monthLabel(month.month, monthly.value.length > 6)));
const monthSeries = computed(() => [
    { key: 'won', label: 'Adjudicado', values: monthly.value.map((month) => month.won) },
    { key: 'collected', label: 'Cobrado', values: monthly.value.map((month) => month.collected) },
]);

const awardHint = computed(() => {
    const { won_count: won = 0, lost_count: lost = 0, pending_count: pending = 0 } = metrics.value;
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

function openRow(row) {
    if (row.href) router.visit(row.href);
}

function apply(changes = {}) {
    Object.assign(filters, changes);
    loadError.value = '';
    // Un cambio nuevo manda: la petición anterior se cancela y su respuesta ya no pinta nada.
    cancelToken?.cancel();
    router.get('/reportes', cleanQuery({ ...filters }, { module: 'all' }), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['report', 'filters', 'errors'],
        onCancelToken: (token) => (cancelToken = token),
        onStart: () => (loading.value = true),
        onFinish: () => {
            loading.value = false;
            cancelToken = null;
        },
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

onBeforeUnmount(() => cancelToken?.cancel());
</script>

<template>
    <AppLayout title="Reportes e indicadores" :sections="[{ label: 'Reportes', href: '/reportes', active: true }, { label: 'KPI y metas', href: '/kpis' }]">
        <PageHeader :description="`Cifras de la empresa activa del ${dateRange(filters.from, filters.to, 'medium')}`">
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
            <p class="mt-3 text-xs text-cap-text-soft">El período cambia todas las cifras; el módulo elige qué filas muestra la tabla de indicadores (y qué se exporta).</p>
        </section>

        <LoadError v-if="loadError" title="No se pudo actualizar el reporte" :message="loadError" @retry="apply()" />

        <Deferred data="report">
            <template #fallback>
                <div class="flex flex-col gap-6" aria-busy="true">
                    <CapSkeleton variant="stats" label="Cargando cifras del período" />
                    <div class="grid grid-cols-1 gap-6 xl:grid-cols-2" aria-hidden="true">
                        <div v-for="index in 2" :key="index" class="flex flex-col gap-3 rounded-cap-card border border-cap-divider bg-cap-card p-5">
                            <span class="cap-skeleton cap-skeleton--label" />
                            <span v-for="bar in 5" :key="bar" class="cap-skeleton h-7" />
                        </div>
                    </div>
                    <CapSkeleton variant="table" :rows="6" label="Cargando indicadores" />
                </div>
            </template>

            <div :key="'report'" class="flex flex-col gap-6 transition-opacity duration-cap-fast ease-cap" :class="{ 'opacity-50': loading }" :aria-busy="loading ? 'true' : undefined">
                <div class="cap-stagger grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard variant="featured" label="Pipeline ponderado" :value="money(metrics.pipeline_weighted)" :hint="`${number(metrics.pipeline_count)} oportunidades activas creadas en el período`" :chip="`de ${compactMoney(metrics.pipeline_amount)}`" href="/pipeline" />
                    <StatCard label="Monto adjudicado" :value="money(metrics.won_amount)" :hint="`${number(metrics.won_count)} cotizaciones ganadas`" href="/cotizaciones" />
                    <StatCard label="Tasa de adjudicación" :value="metrics.award_rate === null || metrics.award_rate === undefined ? '—' : percent(metrics.award_rate)" :hint="awardHint" />
                    <StatCard label="Cobros recibidos" :value="money(metrics.collected)" :hint="`${number(metrics.collected_count)} facturas pagadas`" href="/cobros" />
                </div>

                <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
                    <ChartCard title="Embudo comercial" description="Oportunidades creadas en el período, por etapa del kanban." :empty="funnelEmpty" empty-text="No se crearon oportunidades en el período.">
                        <ol v-stagger class="flex flex-col gap-3">
                            <li v-for="stage in funnel" :key="stage.stage" class="grid grid-cols-[minmax(0,8rem)_1fr] items-center gap-3 sm:grid-cols-[10rem_1fr]">
                                <span class="truncate text-sm text-gray-900 dark:text-gray-100" :title="stage.label">{{ stage.label }}</span>
                                <div class="flex min-w-0 items-center gap-3">
                                    <div class="h-7 min-w-0 flex-1 rounded-md bg-cap-card-2">
                                        <div
                                            class="m-fill h-full rounded-md transition-[width] duration-cap-slow ease-cap"
                                            :style="{ width: `${Math.max(stage.count ? 3 : 0, ((Number(stage.count) || 0) / funnelMax) * 100)}%`, backgroundColor: stageColor(stage.stage) }"
                                        />
                                    </div>
                                    <span class="w-28 shrink-0 text-right text-xs tabular-nums text-cap-text-soft"><strong class="text-sm text-gray-900 dark:text-gray-100">{{ number(stage.count) }}</strong> · {{ compactMoney(stage.amount) }}</span>
                                </div>
                            </li>
                        </ol>
                    </ChartCard>

                    <ChartCard title="Adjudicado y cobrado por mes" description="Cotizaciones ganadas por fecha de cotización y facturas por fecha de pago." :empty="monthlyEmpty || !monthly.length">
                        <BarChart :categories="monthCategories" :series="monthSeries" title="Adjudicado y cobrado por mes" />
                    </ChartCard>
                </div>

                <section class="flex flex-col gap-3">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Indicadores del período</h2>
                        <p v-if="filters.module && filters.module !== 'all'" class="text-xs text-cap-text-soft">Solo el módulo {{ moduleLabel }}</p>
                    </div>
                    <DataTable
                        :columns="columns"
                        :rows="rows"
                        :row-key="(row) => row.label"
                        :loading="loading"
                        caption="Indicadores del período; cada fila abre su módulo"
                        dense
                        clickable
                        empty-icon="chart-box-outline"
                        empty-title="Sin indicadores para este filtro"
                        empty-description="Elige otro módulo o amplía el período."
                        @row-click="openRow"
                    />
                </section>
            </div>
        </Deferred>
    </AppLayout>
</template>
