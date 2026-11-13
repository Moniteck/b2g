<script setup>
// Tablero de inicio: cifras del negocio, plazos de la semana, alertas y accesos.
// Props: dashboard (cifras agregadas), alerts ([{ type, message, severity }]),
//        deadlines ([{ id, title, detail?, due_at, href?, kind? }], opcional: plazos de los próximos 7 días).
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import CapAlert from '../../design/vue/CapAlert.vue';
import EmptyState from '../Components/EmptyState.vue';
import PageHeader from '../Components/PageHeader.vue';
import StatCard from '../Components/StatCard.vue';
import StatusBadge from '../Components/StatusBadge.vue';
import AppLayout from '../Layouts/AppLayout.vue';
import { date, daysUntil, money, relativeDays } from '../lib/format.js';
import { card, textLink } from '../lib/ui.js';

const props = defineProps({
    dashboard: { type: Object, default: () => ({}) },
    alerts: { type: Array, default: () => [] },
    deadlines: { type: Array, default: () => [] },
});

const AWARD_RATE_GOAL = 25;

const today = computed(() => {
    const text = date(new Date(), 'long');
    return text.charAt(0).toUpperCase() + text.slice(1);
});

const suggestion = computed(() => {
    const data = props.dashboard;
    if (!data.submittedTenders) return { status: 'info', title: 'Aún no hay ofertas presentadas', detail: 'La tasa de adjudicación se calcula cuando presentes la primera oferta.' };
    if (data.awardRate < AWARD_RATE_GOAL) return { status: 'warning', title: `Tu tasa de adjudicación es ${data.awardRate} %, bajo la meta de ${AWARD_RATE_GOAL} %`, detail: 'Revisa la calidad documental de tus ofertas con la matriz documental.' };
    if (data.overdueReceivables) return { status: 'warning', title: `Tienes ${money(data.overdueReceivables)} en cartera vencida`, detail: 'Revisa los cobros pendientes de pago.' };
    if (data.expiredDocuments) return { status: 'warning', title: `Tienes ${data.expiredDocuments} ${data.expiredDocuments === 1 ? 'documento vencido' : 'documentos vencidos'}`, detail: 'Renuévalos en el Directorio Institucional antes de presentar otra oferta.' };
    return { status: 'ok', title: 'Gestión en orden', detail: 'Revisa los procesos vigentes y las próximas fechas de cierre.' };
});

const tenderCounters = computed(() => [
    { label: 'Cierran hoy', value: props.dashboard.tenders?.closingToday ?? 0, dot: 'bg-cap-status-error dark:bg-cap-status-dark-error' },
    { label: 'Cierran en 30 días', value: props.dashboard.tenders?.closingSoon ?? 0, dot: 'bg-cap-status-warning dark:bg-cap-status-dark-warning' },
    { label: 'Procesos vigentes', value: props.dashboard.tenders?.active ?? 0, dot: 'bg-cap-status-info dark:bg-cap-status-dark-info' },
]);

const institutionCounters = computed(() => [
    { label: 'Potencial alto', value: props.dashboard.institutions?.high ?? 0, dot: 'bg-cap-status-ok dark:bg-cap-status-dark-ok' },
    { label: 'Potencial medio', value: props.dashboard.institutions?.medium ?? 0, dot: 'bg-cap-status-warning dark:bg-cap-status-dark-warning' },
    { label: 'Potencial bajo', value: props.dashboard.institutions?.low ?? 0, dot: 'bg-cap-status-neutral dark:bg-cap-status-dark-neutral' },
]);

const sortedDeadlines = computed(() => [...props.deadlines].sort((a, b) => String(a.due_at).localeCompare(String(b.due_at))));

function deadlineStatus(deadline) {
    const days = daysUntil(deadline.due_at);
    if (days === null) return 'neutral';
    if (days <= 0) return 'error';
    return days <= 2 ? 'warning' : 'info';
}

const DEADLINE_ICONS = { tender: 'gavel', quote: 'file-document-outline', document: 'folder-account-outline', contract: 'file-sign', collection: 'cash-multiple', event: 'calendar-month-outline' };
const ALERT_ICONS = { danger: 'alert-circle-outline', error: 'alert-circle-outline', warning: 'alert-outline', info: 'information-outline' };
const ALERT_TONES = {
    danger: 'bg-cap-status-error-tint text-cap-status-error-ink',
    error: 'bg-cap-status-error-tint text-cap-status-error-ink',
    warning: 'bg-cap-status-warning-tint text-cap-status-warning-ink',
    info: 'bg-cap-status-info-tint text-cap-status-info-ink',
};

const shortcuts = [
    { label: 'Licitaciones', hint: 'Captura y seguimiento', icon: 'gavel', href: '/licitaciones' },
    { label: 'Cotizaciones', hint: 'Crear, firmar y enviar', icon: 'file-document-outline', href: '/cotizaciones' },
    { label: 'Pipeline', hint: 'Oportunidades por etapa', icon: 'view-column-outline', href: '/pipeline' },
    { label: 'Calendario', hint: 'Plazos y recordatorios', icon: 'calendar-month-outline', href: '/calendario' },
    { label: 'Directorio Institucional', hint: 'Documentos vigentes', icon: 'folder-account-outline', href: '/directorio' },
    { label: 'Cobros', hint: 'Cartera y pagos', icon: 'cash-multiple', href: '/cobros' },
];

const tile = 'rounded-cap-card border border-cap-divider bg-cap-card p-4 transition-transform duration-cap-fast ease-cap hover:-translate-y-0.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome focus-visible:ring-offset-2 focus-visible:ring-offset-cap-page dark:focus-visible:ring-gray-100';
</script>

<template>
    <AppLayout title="Dashboard">
        <PageHeader :description="`Resumen ejecutivo · ${today}`" />

        <CapAlert v-bind="suggestion" />

        <div class="cap-stagger grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard variant="featured" label="Pipeline estimado" :count="Number(dashboard.pipeline ?? 0)" format="currency-compact" :hint="`${dashboard.opportunities ?? 0} oportunidades activas`" href="/pipeline" />
            <StatCard label="Tasa de adjudicación" :count="Number(dashboard.awardRate ?? 0)" format="percent" :hint="`${dashboard.wonTenders ?? 0} ganadas de ${dashboard.submittedTenders ?? 0} presentadas`" href="/licitaciones" />
            <StatCard :variant="Number(dashboard.overdueReceivables) > 0 ? 'alert' : 'normal'" label="Cartera vencida" :count="Number(dashboard.overdueReceivables ?? 0)" format="currency-compact" hint="Cobros pendientes de pago" href="/cobros" />
            <StatCard label="Documentos por vencer" :count="Number(dashboard.expiringDocuments ?? 0)" :hint="`${dashboard.expiredDocuments ?? 0} ya vencidos`" href="/directorio" />
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
            <section :class="[card, 'p-4 md:p-5']" aria-labelledby="dashboard-deadlines">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <h2 id="dashboard-deadlines" class="font-display text-lg font-semibold text-gray-900 dark:text-gray-100">Plazos de la semana</h2>
                    <Link href="/calendario" class="text-sm text-gray-900 dark:text-gray-100" :class="textLink">Ver calendario</Link>
                </div>
                <EmptyState v-if="sortedDeadlines.length === 0" compact icon="calendar-check-outline" title="Sin plazos en los próximos 7 días" description="Los cierres de licitación, vencimientos y entregas aparecerán aquí." />
                <ul v-else class="flex flex-col gap-2">
                    <li v-for="deadline in sortedDeadlines" :key="deadline.id ?? `${deadline.title}-${deadline.due_at}`">
                        <component :is="deadline.href ? Link : 'div'" :href="deadline.href || undefined" class="flex min-h-16 items-center gap-3 rounded-cap-control bg-cap-card-2 p-3" :class="deadline.href ? 'transition-colors duration-cap-fast ease-cap hover:bg-cap-divider focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome dark:focus-visible:ring-gray-100' : ''">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-cap-control bg-cap-card text-cap-text-soft" aria-hidden="true">
                                <i class="mdi text-xl leading-none" :class="`mdi-${DEADLINE_ICONS[deadline.kind] ?? 'calendar-clock-outline'}`" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-semibold text-gray-900 dark:text-gray-100" :title="deadline.title">{{ deadline.title }}</span>
                                <span class="block truncate text-xs text-cap-text-soft">{{ [date(deadline.due_at, 'medium'), deadline.detail].filter(Boolean).join(' · ') }}</span>
                            </span>
                            <StatusBadge :status="deadlineStatus(deadline)" :label="relativeDays(deadline.due_at)" />
                        </component>
                    </li>
                </ul>
            </section>

            <section :class="[card, 'p-4 md:p-5']" aria-labelledby="dashboard-alerts">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <h2 id="dashboard-alerts" class="font-display text-lg font-semibold text-gray-900 dark:text-gray-100">Alertas activas</h2>
                    <StatusBadge v-if="alerts.length" status="error" :label="String(alerts.length)" />
                </div>
                <EmptyState v-if="alerts.length === 0" compact icon="check-circle-outline" title="No hay alertas activas" description="Cuando algo requiera tu atención lo verás aquí." />
                <ul v-else class="flex flex-col gap-2">
                    <li v-for="alert in alerts" :key="`${alert.type}-${alert.message}`">
                        <Link :href="`/${alert.type}`" class="flex min-h-12 items-center gap-3 rounded-cap-control bg-cap-card-2 p-3 transition-colors duration-cap-fast ease-cap hover:bg-cap-divider focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome dark:focus-visible:ring-gray-100">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full" :class="ALERT_TONES[alert.severity] ?? ALERT_TONES.warning" aria-hidden="true">
                                <i class="mdi text-base leading-none" :class="`mdi-${ALERT_ICONS[alert.severity] ?? ALERT_ICONS.warning}`" />
                            </span>
                            <span class="min-w-0 flex-1 text-sm text-gray-900 dark:text-gray-100">{{ alert.message }}</span>
                            <i class="mdi mdi-chevron-right shrink-0 text-xl leading-none text-cap-text-soft" aria-hidden="true" />
                        </Link>
                    </li>
                </ul>
            </section>
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
            <section aria-labelledby="dashboard-tenders">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <h2 id="dashboard-tenders" class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Licitaciones</h2>
                    <Link href="/licitaciones" class="text-sm text-gray-900 dark:text-gray-100" :class="textLink">Ver procesos</Link>
                </div>
                <div class="grid grid-cols-3 gap-3">
                    <Link v-for="counter in tenderCounters" :key="counter.label" href="/licitaciones" :class="tile">
                        <p class="font-display text-3xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ counter.value }}</p>
                        <p class="mt-1 flex items-center gap-1.5 text-xs text-cap-text-soft"><span class="h-1.5 w-1.5 shrink-0 rounded-full" :class="counter.dot" aria-hidden="true" />{{ counter.label }}</p>
                    </Link>
                </div>
            </section>

            <section aria-labelledby="dashboard-institutions">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <h2 id="dashboard-institutions" class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Instituciones por potencial</h2>
                    <Link href="/instituciones" class="text-sm text-gray-900 dark:text-gray-100" :class="textLink">Ver instituciones</Link>
                </div>
                <div class="grid grid-cols-3 gap-3">
                    <Link v-for="counter in institutionCounters" :key="counter.label" href="/instituciones" :class="tile">
                        <p class="font-display text-3xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ counter.value }}</p>
                        <p class="mt-1 flex items-center gap-1.5 text-xs text-cap-text-soft"><span class="h-1.5 w-1.5 shrink-0 rounded-full" :class="counter.dot" aria-hidden="true" />{{ counter.label }}</p>
                    </Link>
                </div>
            </section>
        </div>

        <section aria-labelledby="dashboard-shortcuts">
            <h2 id="dashboard-shortcuts" class="mb-3 font-display text-base font-semibold text-gray-900 dark:text-gray-100">Accesos</h2>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <Link v-for="shortcut in shortcuts" :key="shortcut.href" :href="shortcut.href" class="flex min-h-16 items-center gap-3" :class="tile">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-cap-control bg-cap-red-200 text-cap-red-800 dark:bg-cap-red-600/20 dark:text-cap-brand-soft" aria-hidden="true">
                        <i class="mdi text-xl leading-none" :class="`mdi-${shortcut.icon}`" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold text-gray-900 dark:text-gray-100">{{ shortcut.label }}</span>
                        <span class="block truncate text-xs text-cap-text-soft">{{ shortcut.hint }}</span>
                    </span>
                    <i class="mdi mdi-arrow-right shrink-0 text-xl leading-none text-cap-text-soft" aria-hidden="true" />
                </Link>
            </div>
        </section>
    </AppLayout>
</template>
