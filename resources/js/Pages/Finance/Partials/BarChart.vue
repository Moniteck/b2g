<script setup>
// Barras verticales agrupadas en SVG propio, con la paleta del sistema (`chartColors()`).
// Props: categories (etiquetas del eje x), series ([{ key, label, values }], máximo cuatro),
//        format (cifra del tooltip y la tabla; por defecto moneda), axisFormat (cifra del eje),
//        title (nombre accesible), height, highlight (índice de categoría resaltada: «hoy»).
// Lleva leyenda, tooltip sólido al pasar el cursor o con el teclado y una tabla oculta para lectores.
import { computed, ref } from 'vue';
import { money } from '../../../lib/format.js';
import { compactMoney, niceMax, useChartPalette, useWidth } from './finance.js';

const props = defineProps({
    categories: { type: Array, required: true },
    series: { type: Array, required: true },
    format: { type: Function, default: (value) => money(value) },
    axisFormat: { type: Function, default: compactMoney },
    title: { type: String, default: 'Gráfica' },
    height: { type: Number, default: 240 },
    highlight: { type: Number, default: -1 },
});

const box = ref(null);
const width = useWidth(box);
const palette = useChartPalette();
const active = ref(-1);

const PAD = { top: 12, right: 8, bottom: 28, left: 64 };
const GRID_LINES = 4;

const plotWidth = computed(() => Math.max(10, width.value - PAD.left - PAD.right));
const plotHeight = computed(() => props.height - PAD.top - PAD.bottom);
const max = computed(() => niceMax(Math.max(0, ...props.series.flatMap((serie) => serie.values.map(Number)))));
const slot = computed(() => plotWidth.value / Math.max(1, props.categories.length));
const barWidth = computed(() => Math.max(3, Math.min(28, (slot.value * 0.72) / Math.max(1, props.series.length))));
const ticks = computed(() => Array.from({ length: GRID_LINES + 1 }, (_, index) => (max.value / GRID_LINES) * index));
// Con poco ancho se rotula una de cada N categorías.
const labelEvery = computed(() => Math.max(1, Math.ceil(42 / slot.value)));

const y = (value) => PAD.top + plotHeight.value - (Math.max(0, Number(value)) / max.value) * plotHeight.value;
const barX = (category, serie) => PAD.left + slot.value * category + (slot.value - barWidth.value * props.series.length) / 2 + barWidth.value * serie;
const colorOf = (index) => palette.value.series[index % palette.value.series.length];

const tooltipStyle = computed(() => {
    if (active.value < 0) return {};
    const center = PAD.left + slot.value * (active.value + 0.5);
    const left = Math.min(Math.max(center, 90), width.value - 90);
    return { left: `${left}px` };
});

function move(event) {
    const keys = { ArrowRight: 1, ArrowLeft: -1 };
    if (!(event.key in keys)) return;
    event.preventDefault();
    const next = (active.value < 0 ? 0 : active.value + keys[event.key] + props.categories.length) % props.categories.length;
    active.value = next;
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <ul v-if="series.length > 1" class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-cap-text-soft" aria-hidden="true">
            <li v-for="(serie, index) in series" :key="serie.key" class="flex items-center gap-1.5">
                <span class="h-2.5 w-2.5 rounded-sm" :style="{ backgroundColor: colorOf(index) }"></span>{{ serie.label }}
            </li>
        </ul>
        <div ref="box" class="relative w-full min-w-0 overflow-hidden" @mouseleave="active = -1">
            <svg
                :height="height"
                preserveAspectRatio="none"
                :viewBox="`0 0 ${width} ${height}`"
                role="img"
                :aria-label="title"
                tabindex="0"
                class="block w-full rounded-cap-control focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome dark:focus-visible:ring-gray-100"
                @keydown="move"
                @blur="active = -1"
            >
                <g>
                    <template v-for="tick in ticks" :key="tick">
                        <line :x1="PAD.left" :x2="width - PAD.right" :y1="y(tick)" :y2="y(tick)" :stroke="palette.grid" stroke-width="1" />
                        <text :x="PAD.left - 8" :y="y(tick) + 4" text-anchor="end" font-size="12" class="tabular-nums" :fill="palette.text">{{ axisFormat(tick) }}</text>
                    </template>
                </g>
                <rect
                    v-if="highlight >= 0"
                    :x="PAD.left + slot * highlight"
                    :y="PAD.top"
                    :width="slot"
                    :height="plotHeight"
                    :fill="palette.grid"
                    opacity="0.45"
                />
                <g v-for="(category, cIndex) in categories" :key="category">
                    <rect
                        v-for="(serie, sIndex) in series"
                        :key="serie.key"
                        :x="barX(cIndex, sIndex)"
                        :y="y(serie.values[cIndex])"
                        :width="barWidth"
                        :height="Math.max(0, PAD.top + plotHeight - y(serie.values[cIndex]))"
                        :fill="colorOf(sIndex)"
                        :opacity="active < 0 || active === cIndex ? 1 : 0.45"
                        rx="3"
                    />
                    <text
                        v-if="cIndex % labelEvery === 0"
                        :x="PAD.left + slot * (cIndex + 0.5)"
                        :y="height - 8"
                        text-anchor="middle"
                        font-size="12"
                        :fill="palette.text"
                    >{{ category }}</text>
                    <rect :x="PAD.left + slot * cIndex" :y="PAD.top" :width="slot" :height="plotHeight" fill="transparent" @mouseenter="active = cIndex" />
                </g>
            </svg>
            <div
                v-if="active >= 0"
                class="pointer-events-none absolute top-2 z-cap-dropdown w-44 -translate-x-1/2 rounded-cap-control border border-cap-divider bg-cap-card p-3 text-xs shadow-md dark:bg-cap-card-2"
                :style="tooltipStyle"
                role="status"
            >
                <p class="font-semibold text-gray-900 dark:text-gray-100">{{ categories[active] }}</p>
                <p v-for="(serie, index) in series" :key="serie.key" class="mt-1 flex items-center justify-between gap-2 text-cap-text-soft">
                    <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-sm" :style="{ backgroundColor: colorOf(index) }"></span>{{ serie.label }}</span>
                    <span class="tabular-nums text-gray-900 dark:text-gray-100">{{ format(serie.values[active]) }}</span>
                </p>
            </div>
        </div>
        <div class="sr-only">
        <table>
            <caption>{{ title }}</caption>
            <thead><tr><th scope="col">Categoría</th><th v-for="serie in series" :key="serie.key" scope="col">{{ serie.label }}</th></tr></thead>
            <tbody>
                <tr v-for="(category, index) in categories" :key="category"><th scope="row">{{ category }}</th><td v-for="serie in series" :key="serie.key">{{ format(serie.values[index]) }}</td></tr>
            </tbody>
        </table>
        </div>
    </div>
</template>
