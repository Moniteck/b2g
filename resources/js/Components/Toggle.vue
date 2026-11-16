<script setup>
// Interruptor para activar o desactivar algo que se aplica al momento (una casilla espera a «Guardar»).
// Props: modelValue (v-model), label, description, disabled, id. Sin `label` visible, pasar `aria-label`.
import { useField } from '@/composables/useField.js';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    modelValue: { type: [Boolean, Number], default: false },
    label: { type: String, default: '' },
    description: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    id: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

const { id: fieldId } = useField(props);
</script>

<template>
    <div class="flex min-h-10 items-center gap-3" :class="{ 'opacity-50': disabled }">
        <button
            :id="fieldId"
            v-bind="$attrs"
            type="button"
            role="switch"
            :aria-checked="modelValue ? 'true' : 'false'"
            :aria-labelledby="label ? `${fieldId}-label` : undefined"
            :disabled="disabled"
            class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full border transition-colors duration-cap-fast ease-cap focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome focus-visible:ring-offset-2 focus-visible:ring-offset-cap-card disabled:cursor-not-allowed dark:focus-visible:ring-gray-100"
            :class="modelValue ? 'border-cap-brand bg-cap-brand' : 'border-cap-input-line bg-cap-card-2'"
            @click="emit('update:modelValue', !modelValue)"
        >
            <span
                class="inline-block h-4 w-4 rounded-full transition-transform duration-cap-fast ease-cap"
                :class="modelValue ? 'translate-x-6 bg-white' : 'translate-x-1 bg-gray-500 dark:bg-gray-300'"
                aria-hidden="true"
            />
        </button>
        <span v-if="label || $slots.default" :id="`${fieldId}-label`" class="min-w-0 text-sm text-gray-900 dark:text-gray-100" @click="!disabled && emit('update:modelValue', !modelValue)">
            <slot>{{ label }}</slot>
            <span v-if="description" class="mt-0.5 block text-xs text-cap-text-soft">{{ description }}</span>
        </span>
    </div>
</template>
