<script setup>
// Tarjeta de cifra del recetario. Una sola `featured` por fila, para lo principal.
// Props: label, value (texto ya formateado: «L 48,300.00»), count (número: la cifra sube de 0 al
//        montar y vuelve a subir cuando cambia; con `format` 'integer' | 'percent' | 'currency-compact'), hint (línea bajo la cifra),
//        chip (variación o dato corto en la esquina de la cifra), href (lleva a un detalle: muestra ↗),
//        variant ('normal' | 'featured' | 'alert'), icon (sin `mdi-`; por defecto en alerta, alert-circle-outline).
import { Link } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { bindCountUp, formatCount } from '../../design/shell/js/count-up.js';

const props = defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], default: '' },
    count: { type: Number, default: null },
    format: { type: String, default: 'integer' },
    hint: { type: String, default: '' },
    chip: { type: String, default: '' },
    href: { type: String, default: '' },
    variant: { type: String, default: 'normal' },
    icon: { type: String, default: '' },
});

const VARIANTS = {
    normal: {
        box: 'border border-cap-divider bg-cap-card',
        label: 'text-cap-text-soft',
        figure: 'text-gray-900 dark:text-gray-100',
        hint: 'text-cap-text-soft',
        arrow: 'bg-cap-card-2 text-cap-text-soft',
        chip: 'bg-cap-card-2 text-cap-text-soft',
    },
    featured: {
        box: 'bg-cap-gradient-featured text-white',
        label: 'text-cap-red-200',
        figure: 'text-white',
        hint: 'text-cap-red-200',
        arrow: 'bg-white text-cap-red-800',
        chip: 'bg-white/15 text-white',
    },
    alert: {
        box: 'border border-cap-status-error-tint-border bg-cap-status-error-tint',
        label: 'text-cap-status-error-ink',
        figure: 'text-cap-status-error-ink',
        hint: 'text-cap-status-error-ink',
        arrow: 'bg-cap-card text-cap-status-error-ink',
        chip: 'bg-cap-card text-cap-status-error-ink',
    },
};

const figure = ref(null);
let releaseCount = () => {};

const look = computed(() => VARIANTS[props.variant] ?? VARIANTS.normal);
const shownIcon = computed(() => props.icon || (props.variant === 'alert' ? 'alert-circle-outline' : ''));
const text = computed(() => (props.count !== null ? formatCount(props.count, props.format) : props.value));

function animate() {
    releaseCount();
    releaseCount = () => {};
    if (props.count === null || !figure.value) return;
    // La animación marca el elemento como hecho y escribe el texto directamente en el DOM:
    // se desmarca para volver a animar hasta la cifra nueva.
    delete figure.value.dataset.capCountDone;
    releaseCount = bindCountUp(figure.value);
}

onMounted(animate);
// La cifra cambia (filtros, recarga parcial, props diferidas): se anima otra vez hasta el valor nuevo.
// Si deja de haber `count`, se cancela la animación y Vue pinta `value`.
watch(
    () => [props.count, props.format],
    async () => {
        releaseCount();
        releaseCount = () => {};
        await nextTick();
        animate();
    },
);
onBeforeUnmount(() => releaseCount());
</script>

<template>
    <component
        :is="href ? Link : 'div'"
        :href="href || undefined"
        class="block min-w-0 rounded-cap-card p-5"
        :class="[look.box, href ? 'transition-transform duration-cap-fast ease-cap hover:-translate-y-0.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome focus-visible:ring-offset-2 focus-visible:ring-offset-cap-page dark:focus-visible:ring-gray-100' : '']"
    >
        <div class="flex items-start justify-between gap-3">
            <p class="flex min-w-0 items-center gap-1.5 text-xs font-medium" :class="look.label">
                <i v-if="shownIcon" class="mdi shrink-0 text-base leading-none" :class="`mdi-${shownIcon}`" aria-hidden="true" />
                <span class="truncate">{{ label }}</span>
            </p>
            <span v-if="href" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full" :class="look.arrow" aria-hidden="true">
                <i class="mdi mdi-arrow-top-right text-base leading-none" />
            </span>
        </div>
        <div class="mt-3 flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <p ref="figure" class="font-display text-3xl font-semibold tabular-nums" :class="look.figure" :data-cap-count="count ?? undefined" :data-cap-format="count !== null ? format : undefined">{{ text }}</p>
            <span v-if="chip" class="rounded-md px-2 py-0.5 text-xs font-medium" :class="look.chip">{{ chip }}</span>
        </div>
        <p v-if="hint" class="mt-1 truncate text-xs" :class="look.hint" :title="hint">{{ hint }}</p>
    </component>
</template>
