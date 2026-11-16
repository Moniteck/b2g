<script setup>
// Badge de estado sobre `statusBadgeClass()`: texto del color del estado sobre su tinte, con punto.
// Props: status ('ok' | 'error' | 'warning' | 'info' | 'neutral'), o value (estado del dominio:
//        «Vigente», «Borrador»…; el color sale de `config/statuses.js`), label (texto; por defecto `value`).
import { computed } from 'vue';
import { statusBadgeClass } from '@design/statuses.js';
import { statusOf } from '@/config/statuses.js';

const props = defineProps({
    status: { type: String, default: '' },
    value: { type: [String, Number], default: '' },
    label: { type: String, default: '' },
});

const resolved = computed(() => props.status || statusOf(props.value));
</script>

<template>
    <span :class="[statusBadgeClass(resolved), 'whitespace-nowrap']">
        <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-current" aria-hidden="true" />
        <slot>{{ label || value }}</slot>
    </span>
</template>
