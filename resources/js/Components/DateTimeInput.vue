<script setup>
// Fecha y hora (control nativo). El valor es «AAAA-MM-DDTHH:mm», hora de Honduras.
// Props: modelValue (v-model), min, max, step (segundos; 300 = saltos de 5 min), invalid, readonly, id, required.
import { computed } from 'vue';
import { useField } from '../composables/useField.js';
import { inputClass } from '../lib/ui.js';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    modelValue: { type: String, default: '' },
    min: { type: String, default: '' },
    max: { type: String, default: '' },
    step: { type: [Number, String], default: null },
    invalid: { type: Boolean, default: false },
    readonly: { type: Boolean, default: false },
    id: { type: String, default: '' },
    required: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const { id: fieldId, invalid: isInvalid, aria } = useField(props);
// Acepta «2026-03-05 14:05:00» del servidor y lo lleva al formato del control.
const value = computed(() => String(props.modelValue ?? '').replace(' ', 'T').slice(0, 16));
</script>

<template>
    <input
        :id="fieldId"
        v-bind="{ ...$attrs, ...aria }"
        type="datetime-local"
        :value="value"
        :min="min || undefined"
        :max="max || undefined"
        :step="step ?? undefined"
        :readonly="readonly"
        :class="[inputClass({ invalid: isInvalid, readonly }), 'h-10 !w-56 max-w-full tabular-nums dark:[color-scheme:dark]']"
        @input="emit('update:modelValue', $event.target.value || null)"
    >
</template>
