<script setup>
// Minigráfica de una serie mensual: línea que se dibuja al montar (v-draw) y relleno plano al 12 %.
// Los valores nulos (meses sin dato) cortan la línea. Decorativa: la cifra y su variación van en texto.
// Props: values (números o null), color (CSS; por defecto `currentColor`), height (px).
import { computed } from 'vue';

const props = defineProps({
    values: { type: Array, default: () => [] },
    color: { type: String, default: 'currentColor' },
    height: { type: Number, default: 36 },
});

const W = 120;
const PAD = 3;

const points = computed(() => {
    const numbers = props.values.map((value) => (value === null || value === undefined || !Number.isFinite(Number(value)) ? null : Number(value)));
    const present = numbers.filter((value) => value !== null);
    if (present.length === 0) return [];
    const max = Math.max(...present);
    const min = Math.min(0, ...present);
    const span = max - min || 1;
    const step = numbers.length > 1 ? W / (numbers.length - 1) : 0;
    return numbers.map((value, index) => (value === null ? null : { x: numbers.length > 1 ? index * step : W / 2, y: PAD + (props.height - PAD * 2) * (1 - (value - min) / span) }));
});

// Tramos continuos (sin nulos) de la línea.
const segments = computed(() => {
    const result = [];
    let current = [];
    points.value.forEach((point) => {
        if (point === null) {
            if (current.length) result.push(current);
            current = [];
        } else current.push(point);
    });
    if (current.length) result.push(current);
    return result;
});

const line = (segment) => segment.map((point, index) => `${index ? 'L' : 'M'}${point.x.toFixed(1)},${point.y.toFixed(1)}`).join(' ');
const area = (segment) => `${line(segment)} L${segment.at(-1).x.toFixed(1)},${props.height} L${segment[0].x.toFixed(1)},${props.height} Z`;
const last = computed(() => [...points.value].reverse().find((point) => point !== null) ?? null);
const flat = computed(() => props.values.every((value) => !Number(value)));
</script>

<template>
    <svg :viewBox="`0 0 ${W} ${height}`" preserveAspectRatio="none" class="block w-full overflow-visible" :style="{ height: `${height}px`, color }" aria-hidden="true" focusable="false">
        <line v-if="flat || !segments.length" x1="0" :y1="height - PAD" :x2="W" :y2="height - PAD" stroke="currentColor" stroke-opacity="0.35" stroke-width="1.5" stroke-dasharray="3 3" vector-effect="non-scaling-stroke" />
        <template v-else>
            <template v-for="(segment, index) in segments" :key="index">
                <path v-if="segment.length > 1" :d="area(segment)" fill="currentColor" fill-opacity="0.12" />
                <path v-if="segment.length > 1" v-draw :d="line(segment)" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
            </template>
        </template>
    </svg>
</template>
