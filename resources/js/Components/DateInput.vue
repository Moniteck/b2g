<script setup>
// Fecha (calendario nativo). El valor es «AAAA-MM-DD», como lo espera Laravel.
// Props: modelValue (v-model), min, max, invalid, readonly, id, required.
// Ancho fijo de 160 px, como pide la guía.
import { computed } from 'vue';
import { useField } from '../composables/useField.js';
import { inputClass } from '../lib/ui.js';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    modelValue: { type: String, default: '' },
    min: { type: String, default: '' },
    max: { type: String, default: '' },
    invalid: { type: Boolean, default: false },
    readonly: { type: Boolean, default: false },
    id: { type: String, default: '' },
    required: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const { id: fieldId, invalid: isInvalid, aria } = useField(props);
// El servidor puede mandar «2026-03-05 00:00:00» o un ISO completo: el control solo entiende la fecha.
const value = computed(() => String(props.modelValue ?? '').slice(0, 10));
</script>

<template>
    <input
        :id="fieldId"
        v-bind="{ ...$attrs, ...aria }"
        type="date"
        :value="value"
        :min="min || undefined"
        :max="max || undefined"
        :readonly="readonly"
        :class="[inputClass({ invalid: isInvalid, readonly }), 'h-10 !w-40 tabular-nums dark:[color-scheme:dark]']"
        @input="emit('update:modelValue', $event.target.value || null)"
    >
</template>
