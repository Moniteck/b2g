<script setup>
// Línea con área (relleno plano al 12 %) en SVG propio, para una sola serie (saldo en el tiempo).
// Props: points ([{ label, value }]), title, height, format (tooltip), axisFormat (eje).
import { computed, ref } from 'vue';
import { money } from '../../../lib/format.js';
import { compactMoney, niceMax, useChartPalette, useWidth } from './finance.js';

const props = defineProps({
    points: { type: Array, required: true },
    title: { type: String, default: 'Gráfica' },
    height: { type: Number, default: 220 },
    format: { type: Function, default: (value) => money(value) },
    axisFormat: { type: Function, default: compactMoney },
});

const box = ref(null);
const width = useWidth(box);
const palette = useChartPalette();
const active = ref(-1);

const PAD = { top: 12, right: 12, bottom: 28, left: 64 };
const GRID_LINES = 4;

const values = computed(() => props.points.map((point) => Number(point.value) || 0));
const minValue = computed(() => Math.min(0, ...values.value));
const maxValue = computed(() => niceMax(Math.max(...values.value, 0) - minValue.value) + minValue.value);
const plotWidth = computed(() => Math.max(10, width.value - PAD.left - PAD.right));
const plotHeight = computed(() => props.height - PAD.top - PAD.bottom);
const step = computed(() => (props.points.length > 1 ? plotWidth.value / (props.points.length - 1) : 0));
const ticks = computed(() => Array.from({ length: GRID_LINES + 1 }, (_, index) => minValue.value + ((maxValue.value - minValue.value) / GRID_LINES) * index));
const labelEvery = computed(() => Math.max(1, Math.ceil(80 / Math.max(step.value, 1))));

const x = (index) => PAD.left + (props.points.length > 1 ? step.value * index : plotWidth.value / 2);
const y = (value) => PAD.top + plotHeight.value - ((value - minValue.value) / (maxValue.value - minValue.value || 1)) * plotHeight.value;

const line = computed(() => values.value.map((value, index) => `${index ? 'L' : 'M'}${x(index)},${y(value)}`).join(' '));
const area = computed(() => (values.value.length ? `${line.value} L${x(values.value.length - 1)},${y(Math.max(0, minValue.value))} L${x(0)},${y(Math.max(0, minValue.value))} Z` : ''));
const color = computed(() => palette.value.series[0]);

function hover(event) {
    const rect = event.currentTarget.getBoundingClientRect();
    const offset = event.clientX - rect.left - PAD.left;
    active.value = Math.min(props.points.length - 1, Math.max(0, Math.round(offset / (step.value || 1))));
}

function move(event) {
    const keys = { ArrowRight: 1, ArrowLeft: -1 };
    if (!(event.key in keys)) return;
    event.preventDefault();
    active.value = (active.value < 0 ? props.points.length - 1 : active.value + keys[event.key] + props.points.length) % props.points.length;
}

const tooltipStyle = computed(() => (active.value < 0 ? {} : { left: `${Math.min(Math.max(x(active.value), 90), width.value - 90)}px` }));
</script>

<template>
    <div ref="box" class="relative" @mouseleave="active = -1">
        <svg
            :width="width"
            :height="height"
            :viewBox="`0 0 ${width} ${height}`"
            role="img"
            :aria-label="title"
            tabindex="0"
            class="block rounded-cap-control focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome dark:focus-visible:ring-gray-100"
            @mousemove="hover"
            @keydown="move"
            @blur="active = -1"
        >
            <template v-for="tick in ticks" :key="tick">
                <line :x1="PAD.left" :x2="width - PAD.right" :y1="y(tick)" :y2="y(tick)" :stroke="palette.grid" stroke-width="1" />
                <text :x="PAD.left - 8" :y="y(tick) + 4" text-anchor="end" font-size="12" class="tabular-nums" :fill="palette.text">{{ axisFormat(tick) }}</text>
            </template>
            <path :d="area" :fill="color" fill-opacity="0.12" />
            <path :d="line" fill="none" :stroke="color" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" pathLength="1" class="cap-draw" />
            <template v-for="(point, index) in points" :key="point.label + index">
                <text v-if="index % labelEvery === 0" :x="x(index)" :y="height - 8" text-anchor="middle" font-size="12" :fill="palette.text">{{ point.label }}</text>
            </template>
            <g v-if="active >= 0">
                <line :x1="x(active)" :x2="x(active)" :y1="PAD.top" :y2="PAD.top + plotHeight" :stroke="palette.grid" stroke-width="1" />
                <circle :cx="x(active)" :cy="y(values[active])" r="4" :fill="color" />
            </g>
        </svg>
        <div
            v-if="active >= 0"
            class="pointer-events-none absolute top-2 z-cap-dropdown w-44 -translate-x-1/2 rounded-cap-control border border-cap-divider bg-cap-card p-3 text-xs shadow-md dark:bg-cap-card-2"
            :style="tooltipStyle"
            role="status"
        >
            <p class="text-cap-text-soft">{{ points[active].label }}</p>
            <p class="mt-1 font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ format(points[active].value) }}</p>
        </div>
        <table class="sr-only">
            <caption>{{ title }}</caption>
            <tbody><tr v-for="(point, index) in points" :key="index"><th scope="row">{{ point.label }}</th><td>{{ format(point.value) }}</td></tr></tbody>
        </table>
    </div>
</template>
