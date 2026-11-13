<script setup>
// Casilla con su etiqueta (la casilla del sistema, `.cap-check`).
// Props: modelValue (v-model: booleano, o lista si se pasa `value`), value, label, description,
//        disabled, invalid, id. La etiqueta también puede ir en el slot.
import { computed } from 'vue';
import { useField } from '../composables/useField.js';

const props = defineProps({
    modelValue: { type: [Boolean, Array, Number], default: false },
    value: { type: [String, Number, Boolean, Object], default: null },
    label: { type: String, default: '' },
    description: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    invalid: { type: Boolean, default: false },
    id: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

const { aria } = useField(props);
const isList = computed(() => Array.isArray(props.modelValue));
const checked = computed(() => (isList.value ? props.modelValue.includes(props.value) : Boolean(props.modelValue)));

function onChange(event) {
    if (!isList.value) return emit('update:modelValue', event.target.checked);
    const rest = props.modelValue.filter((item) => item !== props.value);
    emit('update:modelValue', event.target.checked ? [...rest, props.value] : rest);
}
</script>

<template>
    <label class="cap-check min-h-10 items-start py-2 text-sm text-gray-900 dark:text-gray-100" :class="{ 'cursor-not-allowed opacity-50': disabled }">
        <input :id="id || undefined" class="cap-check__box mt-0.5" type="checkbox" :checked="checked" :disabled="disabled" v-bind="aria" @change="onChange">
        <span class="min-w-0">
            <slot>{{ label }}</slot>
            <span v-if="description" class="mt-0.5 block text-xs text-cap-text-soft">{{ description }}</span>
        </span>
    </label>
</template>
