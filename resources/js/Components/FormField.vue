<script setup>
// Campo de formulario: etiqueta encima, control en el slot, ayuda y error debajo.
// Props: label, error (texto del servidor: `form.errors.campo`), help, required (asterisco),
//        optional (añade «(opcional)» cuando casi todos son obligatorios), for (id del control si no
//        es uno de la biblioteca), hideLabel (etiqueta solo para lectores de pantalla).
// El control de la biblioteca que vaya dentro toma solo su id, `aria-invalid` y `aria-describedby`.
import { computed, provide, useId } from 'vue';
import { FIELD_KEY } from '../composables/useField.js';

const props = defineProps({
    label: { type: String, default: '' },
    error: { type: [String, Array], default: '' },
    help: { type: String, default: '' },
    required: { type: Boolean, default: false },
    optional: { type: Boolean, default: false },
    for: { type: String, default: '' },
    hideLabel: { type: Boolean, default: false },
});

const generatedId = useId();
const id = computed(() => props.for || generatedId);
const message = computed(() => (Array.isArray(props.error) ? props.error[0] : props.error) || '');
const helpId = computed(() => `${id.value}-help`);
const errorId = computed(() => `${id.value}-error`);
const describedBy = computed(() => [message.value ? errorId.value : '', props.help ? helpId.value : ''].filter(Boolean).join(' '));

provide(FIELD_KEY, {
    id,
    describedBy,
    invalid: computed(() => Boolean(message.value)),
    required: computed(() => props.required),
});
</script>

<template>
    <div v-shake="message" class="m-field min-w-0">
        <label v-if="label" :for="id" class="mb-1 block text-xs font-semibold text-gray-700 dark:text-gray-300" :class="{ 'sr-only': hideLabel }">
            {{ label }}
            <span v-if="required" class="text-cap-brand dark:text-cap-brand-soft" aria-hidden="true">*</span>
            <span v-else-if="optional" class="font-normal text-cap-text-soft">(opcional)</span>
        </label>
        <slot :id="id" :invalid="Boolean(message)" :described-by="describedBy" />
        <p v-if="message" :id="errorId" :key="message" class="m-field-error mt-1 text-xs text-cap-status-error dark:text-cap-status-dark-error">{{ message }}</p>
        <p v-if="help" :id="helpId" class="mt-1 text-xs text-cap-text-soft">{{ help }}</p>
    </div>
</template>
