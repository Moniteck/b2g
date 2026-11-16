<script setup>
// Tarjeta KPI del Dashboard: cifra que cuenta, variación frente al período anterior y minigráfica.
// Variantes como `StatCard`: normal, destacada (degradado de marca, una por fila) y alerta.
// Props: kpi { key, label, value, format: 'currency'|'percent', previous, hint, spark[], href, featured?, alert? },
//        palette (de useChartPalette).
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useCountUp } from '../../lib/motion/index.js';
import { kpiFull, kpiText, variation } from './dashboard.js';
import Sparkline from './Sparkline.vue';

const props = defineProps({
    kpi: { type: Object, required: true },
    palette: { type: Object, required: true },
});

const variant = computed(() => (props.kpi.featured ? 'featured' : props.kpi.alert ? 'alert' : 'normal'));
const empty = computed(() => props.kpi.value === null || props.kpi.value === undefined);
const shown = useCountUp(() => (empty.value ? 0 : Number(props.kpi.value)), { format: (value) => kpiText(value, props.kpi.format) });
const delta = computed(() => variation(props.kpi.value, props.kpi.previous, props.kpi.format, props.kpi.key === 'receivable'));

const LOOKS = {
    normal: { box: 'border border-cap-divider bg-cap-card', label: 'text-cap-text-soft', figure: 'text-gray-900 dark:text-gray-100', hint: 'text-cap-text-soft', arrow: 'bg-cap-card-2 text-cap-text-soft' },
    featured: { box: 'bg-cap-gradient-featured text-white', label: 'text-cap-red-200', figure: 'text-white', hint: 'text-cap-red-200', arrow: 'bg-white text-cap-red-800' },
    alert: { box: 'border border-cap-status-error-tint-border bg-cap-status-error-tint', label: 'text-cap-status-error-ink', figure: 'text-cap-status-error-ink', hint: 'text-cap-status-error-ink', arrow: 'bg-cap-card text-cap-status-error-ink' },
};
const look = computed(() => LOOKS[variant.value]);

const chipClass = computed(() => {
    if (variant.value === 'featured') return 'bg-white/15 text-white';
    if (variant.value === 'alert') return 'bg-cap-card text-cap-status-error-ink';
    if (!delta.value || delta.value.tone === 'neutral') return 'bg-cap-card-2 text-cap-text-soft';
    return delta.value.tone === 'good' ? 'bg-cap-status-ok-tint text-cap-status-ok-ink' : 'bg-cap-status-warning-tint text-cap-status-warning-ink';
});
const sparkColor = computed(() => (variant.value === 'featured' ? 'currentColor' : variant.value === 'alert' ? props.palette.status.error : props.palette.series[0]));
const deltaIcon = computed(() => ({ up: 'arrow-top-right', down: 'arrow-bottom-right', flat: 'arrow-right' })[delta.value?.direction ?? 'flat']);
const accessible = computed(() => [props.kpi.label, kpiFull(props.kpi.value, props.kpi.format), delta.value ? `${delta.value.label} frente al período anterior` : '', props.kpi.hint].filter(Boolean).join('. '));
</script>

<template>
    <component
        :is="kpi.href ? Link : 'div'"
        :href="kpi.href || undefined"
        :aria-label="accessible"
        class="group flex min-w-0 flex-col rounded-cap-card p-4 sm:p-5"
        :class="[look.box, kpi.href ? 'transition-transform duration-cap-fast ease-cap hover:-translate-y-0.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome focus-visible:ring-offset-2 focus-visible:ring-offset-cap-page dark:focus-visible:ring-gray-100' : '']"
    >
        <div class="flex items-start justify-between gap-2">
            <p class="min-w-0 text-xs font-medium" :class="look.label">
                <i v-if="variant === 'alert'" class="mdi mdi-alert-circle-outline mr-1 text-base leading-none" aria-hidden="true" />{{ kpi.label }}
            </p>
            <span v-if="kpi.href" class="hidden h-8 w-8 shrink-0 items-center justify-center rounded-full sm:flex" :class="look.arrow" aria-hidden="true">
                <i class="mdi mdi-arrow-top-right text-base leading-none transition-transform duration-cap-fast ease-cap group-hover:-translate-y-0.5 group-hover:translate-x-0.5" />
            </span>
        </div>
        <p class="mt-2 truncate font-display text-3xl font-semibold tabular-nums" :class="look.figure" aria-hidden="true">{{ empty ? '—' : shown }}</p>
        <div class="mt-1 flex min-h-6 flex-wrap items-center gap-x-2 gap-y-1" aria-hidden="true">
            <span v-if="delta" class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium tabular-nums" :class="chipClass">
                <i class="mdi text-base leading-none" :class="`mdi-${deltaIcon}`" />{{ delta.label }}
            </span>
            <span class="hidden truncate text-xs sm:inline" :class="look.hint">vs. período anterior</span>
        </div>
        <div class="mt-2 sm:mt-3" :class="variant === 'featured' ? 'text-white' : ''">
            <Sparkline :values="kpi.spark" :color="sparkColor" />
        </div>
        <p class="mt-2 truncate text-xs" :class="look.hint" :title="kpi.hint" aria-hidden="true">{{ kpi.hint }}</p>
    </component>
</template>
