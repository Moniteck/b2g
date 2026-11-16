<script setup>
// Área de texto. Props: modelValue (v-model), rows (4), invalid, readonly, id, required,
// maxlength (muestra el contador «120 / 500»). El resto de atributos pasan al <textarea>.
import { computed, ref } from 'vue';
import { useField } from '@/composables/useField.js';
import { inputClass } from '@/lib/ui.js';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    modelValue: { type: String, default: '' },
    rows: { type: [Number, String], default: 4 },
    invalid: { type: Boolean, default: false },
    readonly: { type: Boolean, default: false },
    id: { type: String, default: '' },
    required: { type: Boolean, default: false },
    maxlength: { type: [Number, String], default: null },
});

const emit = defineEmits(['update:modelValue']);

const { id: fieldId, invalid: isInvalid, aria } = useField(props);
const textarea = ref(null);
const length = computed(() => (props.modelValue ?? '').length);

defineExpose({ focus: () => textarea.value?.focus() });
</script>

<template>
    <div>
        <textarea
            :id="fieldId"
            ref="textarea"
            v-bind="{ ...$attrs, ...aria }"
            :rows="rows"
            :value="modelValue ?? ''"
            :readonly="readonly"
            :maxlength="maxlength ?? undefined"
            :class="[inputClass({ invalid: isInvalid, readonly }), 'min-h-20 py-2']"
            @input="emit('update:modelValue', $event.target.value)"
        />
        <p v-if="maxlength" class="mt-1 text-right text-xs tabular-nums text-cap-text-soft">{{ length }} / {{ maxlength }}</p>
    </div>
</template>
