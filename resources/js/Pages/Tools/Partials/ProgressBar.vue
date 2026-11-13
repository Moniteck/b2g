<script setup>
// Barra de progreso de las herramientas (matriz documental, capacitación, participación de consorcios).
// Props: value (0–100), label (nombre accesible), status ('ok' | 'warning' | 'error' | 'info' | 'brand'),
//        showValue (pinta «45 %» a la derecha).
import { computed } from 'vue';
import { percent } from '../../../lib/format.js';

const props = defineProps({
    value: { type: Number, default: 0 },
    label: { type: String, default: 'Progreso' },
    status: { type: String, default: 'ok' },
    showValue: { type: Boolean, default: false },
});

const FILL = {
    ok: 'bg-cap-status-ok dark:bg-cap-status-dark-ok',
    warning: 'bg-cap-status-warning dark:bg-cap-status-dark-warning',
    error: 'bg-cap-status-error dark:bg-cap-status-dark-error',
    info: 'bg-cap-status-info dark:bg-cap-status-dark-info',
    brand: 'bg-cap-brand dark:bg-cap-brand-soft',
};

const clamped = computed(() => Math.min(100, Math.max(0, Number(props.value) || 0)));
</script>

<template>
    <div class="flex items-center gap-3">
        <div class="h-2 min-w-0 flex-1 overflow-hidden rounded-full bg-cap-card-2" role="progressbar" :aria-label="label" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="Math.round(clamped)">
            <div class="h-full rounded-full transition-[width] duration-cap-slow ease-cap" :class="FILL[status] ?? FILL.ok" :style="{ width: `${clamped}%` }" />
        </div>
        <span v-if="showValue" class="w-12 shrink-0 text-right text-xs font-medium tabular-nums text-cap-text-soft">{{ percent(clamped, 0) }}</span>
    </div>
</template>
