<script setup>
// Campo de texto de 40 px. Dentro de `FormField` toma solo su id y su estado de error.
// Props: modelValue (v-model), type ('text' | 'email' | 'tel' | 'number' | 'password' | 'search' | 'url'),
//        icon (ícono `-outline` a la izquierda, sin `mdi-`; solo en pantallas de pocos campos),
//        invalid, readonly (sin borde, fondo secundario), id, required.
// `type="password"` trae el botón para ver la contraseña. El resto de atributos (placeholder,
// autocomplete, maxlength, disabled…) pasan al <input>. Expone `focus()`.
import { computed, ref } from 'vue';
import { useField } from '@/composables/useField.js';
import { iconButton, inputClass } from '@/lib/ui.js';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    modelValue: { type: [String, Number], default: '' },
    type: { type: String, default: 'text' },
    icon: { type: String, default: '' },
    invalid: { type: Boolean, default: false },
    readonly: { type: Boolean, default: false },
    id: { type: String, default: '' },
    required: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const { id: fieldId, invalid: isInvalid, aria } = useField(props);
const input = ref(null);
const revealed = ref(false);

const isPassword = computed(() => props.type === 'password');
const inputType = computed(() => (isPassword.value && revealed.value ? 'text' : props.type));

function onInput(event) {
    const raw = event.target.value;
    emit('update:modelValue', props.type === 'number' ? (raw === '' ? null : Number(raw)) : raw);
}

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <div class="relative">
        <i v-if="icon" class="mdi pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xl leading-none text-cap-text-soft" :class="`mdi-${icon}`" aria-hidden="true" />
        <input
            :id="fieldId"
            ref="input"
            v-bind="{ ...$attrs, ...aria }"
            :type="inputType"
            :value="modelValue ?? ''"
            :readonly="readonly"
            :class="[inputClass({ invalid: isInvalid, readonly }), 'h-10', icon ? 'pl-10' : '', isPassword ? 'pr-10' : '', type === 'number' ? 'tabular-nums' : '']"
            @input="onInput"
        >
        <button
            v-if="isPassword"
            type="button"
            :aria-label="revealed ? 'Ocultar contraseña' : 'Mostrar contraseña'"
            :title="revealed ? 'Ocultar contraseña' : 'Mostrar contraseña'"
            :aria-controls="fieldId"
            :class="[iconButton, 'absolute right-0.5 top-1/2 -translate-y-1/2']"
            @click="revealed = !revealed"
        >
            <i class="mdi text-xl leading-none" :class="revealed ? 'mdi-eye-off-outline' : 'mdi-eye-outline'" aria-hidden="true" />
        </button>
    </div>
</template>
