<script setup>
// Dashboard: la pantalla de entrada. Saludo y período, «Requiere atención hoy», 4 KPI con variación y
// minigráfica, plazos de 14 días, mis tareas, pipeline por etapa, adjudicado frente a cobrado, lo que
// vence y la actividad del equipo. Las cifras salen de `BusinessMetrics` (las mismas de Reportes y KPI).
//
// Props: period { preset, label, from, to, previous, months }, periods ([{ value, label }]),
//        kpis ([{ key, label, value, format, previous, hint, spark, href, featured?, alert? }]),
//        attention ([{ id, tone, icon, title, detail, due_at, action }]),
//        charts (diferida: { stages, monthly, open_tenders }),
//        agenda (diferida: { timeline, expiring, activity, tasks, onboarding }),
//        can { create, reports }, maxImageMb.
// Móvil: KPI en 2×2 y, tras plazos, tareas y pipeline, el resto se abre con «Ver más del tablero».
import { Deferred, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import CapSegmented from '../../design/vue/CapSegmented.vue';
import AppButton from '../Components/AppButton.vue';
import EmptyState from '../Components/EmptyState.vue';
import AppLayout from '../Layouts/AppLayout.vue';
import { date } from '../lib/format.js';
import ActivityFeed from '../Modules/Dashboard/ActivityFeed.vue';
import AttentionStrip from '../Modules/Dashboard/AttentionStrip.vue';
import BlockCard from '../Modules/Dashboard/BlockCard.vue';
import { greeting } from '../Modules/Dashboard/dashboard.js';
import DeadlineTimeline from '../Modules/Dashboard/DeadlineTimeline.vue';
import ExpiringList from '../Modules/Dashboard/ExpiringList.vue';
import KpiCard from '../Modules/Dashboard/KpiCard.vue';
import OnboardingGuide from '../Modules/Dashboard/OnboardingGuide.vue';
import PipelineStages from '../Modules/Dashboard/PipelineStages.vue';
import TaskList from '../Modules/Dashboard/TaskList.vue';
import ImportCaptureModal from '../Modules/Tenders/ImportCaptureModal.vue';
import BarChart from './Finance/Partials/BarChart.vue';
import { monthLabel, useChartPalette } from './Finance/Partials/finance.js';

const props = defineProps({
    period: { type: Object, required: true },
    periods: { type: Array, default: () => [] },
    kpis: { type: Array, default: () => [] },
    attention: { type: Array, default: () => [] },
    charts: { type: Object, default: null },
    agenda: { type: Object, default: null },
    can: { type: Object, default: () => ({}) },
    maxImageMb: { type: Number, default: 10 },
});

const page = usePage();
const palette = useChartPalette();
const preset = ref(props.period.preset);
const switching = ref(false);
const importOpen = ref(false);
const moreOpen = ref(false);
let cancelToken = null;

const firstName = computed(() => String(page.props.auth?.user?.name ?? '').trim().split(/\s+/)[0] ?? '');
const todayText = computed(() => {
    const text = date(new Date(), 'long');
    return text.charAt(0).toUpperCase() + text.slice(1);
});
const companyName = computed(() => page.props.activeCompany?.name ?? '');

const monthly = computed(() => (props.charts?.monthly ?? []).map((month) => ({ month: month.month, won: Number(month.won) || 0, collected: Number(month.collected) || 0 })));
const monthlyEmpty = computed(() => monthly.value.every((month) => !month.won && !month.collected));
const monthSeries = computed(() => [
    { key: 'won', label: 'Adjudicado', values: monthly.value.map((month) => month.won) },
    { key: 'collected', label: 'Cobrado', values: monthly.value.map((month) => month.collected) },
]);
const monthCategories = computed(() => monthly.value.map((month) => monthLabel(month.month, true)));

function changePeriod(value) {
    if (value === props.period.preset && !switching.value) return;
    preset.value = value;
    cancelToken?.cancel();
    router.get('/', value === 'mes' ? {} : { periodo: value }, {
        only: ['period', 'kpis', 'charts'],
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onCancelToken: (token) => (cancelToken = token),
        onStart: () => (switching.value = true),
        onFinish: () => {
            switching.value = false;
            cancelToken = null;
        },
    });
}

onBeforeUnmount(() => cancelToken?.cancel());

const more = 'max-lg:hidden';
</script>

<template>
    <AppLayout title="Dashboard">
        <section class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between" aria-labelledby="dashboard-greeting">
            <div class="min-w-0">
                <div class="cap-module-home">
                    <small class="cap-module-home__eyebrow">{{ greeting() }}<template v-if="firstName">,</template></small>
                    <h2 v-if="firstName" id="dashboard-greeting" class="cap-module-home__name">{{ firstName }}</h2>
                    <h2 v-else id="dashboard-greeting" class="sr-only">Resumen</h2>
                    <p class="cap-module-home__hint">{{ todayText }}<template v-if="companyName"> · {{ companyName }}</template></p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <CapSegmented :model-value="preset" :items="periods" label="Período de las cifras" @update:model-value="changePeriod" />
                <AppButton v-if="can.create" variant="primary" icon="text-recognition" class="max-sm:w-full" @click="importOpen = true">Importar licitación</AppButton>
            </div>
        </section>

        <AttentionStrip :items="attention" />

        <Deferred data="agenda">
            <template #fallback><span class="hidden" /></template>
            <OnboardingGuide v-if="agenda?.onboarding?.show" :steps="agenda.onboarding.steps" @import="importOpen = true" />
        </Deferred>

        <div class="cap-stagger grid grid-cols-2 gap-3 transition-opacity duration-cap-fast ease-cap sm:gap-4 xl:grid-cols-4" :class="{ 'opacity-50': switching }" :aria-busy="switching ? 'true' : undefined" aria-label="Indicadores del período">
            <KpiCard v-for="kpi in kpis" :key="kpi.key" :kpi="kpi" :palette="palette" />
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2 xl:grid-cols-12">
            <BlockCard id="dashboard-deadlines" class="xl:col-span-7" title="Plazos de los próximos 14 días" description="Licitaciones, contratos, documentos y cobros." icon="calendar-clock-outline" href="/calendario" link-label="Calendario">
                <Deferred data="agenda">
                    <template #fallback><div class="flex flex-col gap-3" aria-busy="true"><span class="cap-skeleton h-16" /><span v-for="row in 3" :key="row" class="cap-skeleton h-14" /></div></template>
                    <DeadlineTimeline :items="agenda?.timeline ?? []" :palette="palette" :limit="5" />
                </Deferred>
            </BlockCard>

            <BlockCard id="dashboard-tasks" class="xl:col-span-5" title="Mis tareas" description="Plazos de los que eres responsable." icon="checkbox-marked-circle-outline" href="/notificaciones" link-label="Recordatorios">
                <Deferred data="agenda">
                    <template #fallback><div class="flex flex-col gap-2" aria-busy="true"><span v-for="row in 3" :key="row" class="cap-skeleton h-14" /></div></template>
                    <TaskList :items="agenda?.tasks ?? []" />
                </Deferred>
            </BlockCard>

            <BlockCard id="dashboard-pipeline" class="transition-opacity duration-cap-fast ease-cap xl:col-span-5" :class="{ 'opacity-50': switching }" title="Pipeline por etapa" :description="`Oportunidades creadas ${period.label.toLowerCase()}.`" icon="view-column-outline" href="/pipeline" link-label="Kanban">
                <Deferred data="charts">
                    <template #fallback><div class="flex flex-col gap-3" aria-busy="true"><span class="cap-skeleton h-12" /><span v-for="row in 5" :key="row" class="cap-skeleton h-8" /></div></template>
                    <PipelineStages :stages="charts?.stages ?? []" :palette="palette" :open-tenders="charts?.open_tenders ?? 0" />
                </Deferred>
            </BlockCard>

            <button
                type="button"
                class="flex min-h-11 items-center justify-center gap-1 rounded-cap-card border border-cap-divider bg-cap-card text-sm font-medium text-gray-900 transition-colors duration-cap-fast ease-cap hover:bg-cap-card-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome dark:text-gray-100 dark:focus-visible:ring-gray-100 lg:hidden"
                :aria-expanded="moreOpen ? 'true' : 'false'"
                aria-controls="dashboard-more"
                @click="moreOpen = !moreOpen"
            >
                {{ moreOpen ? 'Ver menos' : 'Ver más del tablero' }}
                <i class="mdi text-xl leading-none" :class="moreOpen ? 'mdi-chevron-up' : 'mdi-chevron-down'" aria-hidden="true" />
            </button>

            <BlockCard id="dashboard-cashflow" class="transition-opacity duration-cap-fast ease-cap xl:col-span-7" :class="[{ 'opacity-50': switching }, moreOpen ? '' : more]" title="Adjudicado frente a cobrado" description="Cotizaciones ganadas por fecha de cotización y facturas por fecha de pago." icon="chart-bar" :href="can.reports ? '/reportes' : ''" link-label="Reportes">
                <Deferred data="charts">
                    <template #fallback><span class="cap-skeleton block h-60" aria-busy="true" /></template>
                    <EmptyState v-if="monthlyEmpty" compact icon="chart-box-outline" title="Sin adjudicaciones ni cobros" description="Cuando ganes una cotización o cobres una factura verás aquí su evolución mes a mes." />
                    <BarChart v-else :categories="monthCategories" :series="monthSeries" :highlight="monthly.length - 1" title="Adjudicado frente a cobrado por mes" />
                </Deferred>
            </BlockCard>

            <div id="dashboard-more" class="contents">
                <BlockCard id="dashboard-expiring" class="xl:col-span-5" :class="moreOpen ? '' : more" title="Por vencer" description="Contratos y documentos de los próximos 30 días." icon="timer-sand" href="/post-adjudicacion" link-label="Contratos">
                    <Deferred data="agenda">
                        <template #fallback><div class="flex flex-col gap-2" aria-busy="true"><span class="cap-skeleton h-10" /><span v-for="row in 3" :key="row" class="cap-skeleton h-14" /></div></template>
                        <ExpiringList :expiring="agenda?.expiring" />
                    </Deferred>
                </BlockCard>

                <BlockCard id="dashboard-activity" class="xl:col-span-7" :class="moreOpen ? '' : more" title="Actividad reciente" description="Lo último que hizo el equipo." icon="history">
                    <Deferred data="agenda">
                        <template #fallback><div class="flex flex-col gap-4" aria-busy="true"><span v-for="row in 4" :key="row" class="cap-skeleton h-10" /></div></template>
                        <ActivityFeed :items="agenda?.activity ?? []" />
                    </Deferred>
                </BlockCard>
            </div>
        </div>

        <ImportCaptureModal v-if="can.create" v-model:open="importOpen" :max-size-mb="maxImageMb" />
    </AppLayout>
</template>
