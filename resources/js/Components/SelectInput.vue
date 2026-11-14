<script setup>
// Selector nativo, para más de 5 opciones (de 2 a 5 va `CapSegmented`; listas largas o remotas, `SearchSelect`).
// Props: modelValue (v-model), options ([{ value, label, disabled? }] o lista de textos),
//        placeholder (opción vacía inicial; '' para no mostrarla), invalid, id, required.
// También acepta <option> en el slot. El resto de atributos pasan al <select>.
import { computed } from 'vue';
import { useField } from '../composables/useField.js';
import { inputClass } from '../lib/ui.js';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    modelValue: { type: [String, Number, Boolean], default: '' },
    options: { type: Array, default: () => [] },
    placeholder: { type: String, default: 'Seleccionar' },
    invalid: { type: Boolean, default: false },
    id: { type: String, default: '' },
    required: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const { id: fieldId, invalid: isInvalid, aria } = useField(props);

const items = computed(() => props.options.map((option) => (typeof option === 'object' && option !== null ? option : { value: option, label: String(option) })));

function onChange(event) {
    const chosen = items.value.find((item) => String(item.value) === event.target.value);
    emit('update:modelValue', chosen ? chosen.value : event.target.value === '' ? null : event.target.value);
}
</script>

<template>
    <select
        :id="fieldId"
        v-bind="{ ...$attrs, ...aria }"
        :value="modelValue ?? ''"
        :class="[inputClass({ invalid: isInvalid }), 'h-10 pr-8 dark:[color-scheme:dark]']"
        @change="onChange"
    >
        <option v-if="placeholder" value="">{{ placeholder }}</option>
        <option v-for="item in items" :key="String(item.value)" :value="item.value" :disabled="item.disabled">{{ item.label }}</option>
        <slot />
    </select>
</template>
