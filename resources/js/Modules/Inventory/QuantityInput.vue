<script setup>
// Cantidad de una fila del inventario: número positivo, alineado a la derecha. Vacío = no elegido.
import { inputClass } from '../../lib/ui.js';

const props = defineProps({
    modelValue: { type: Number, default: null },
    label: { type: String, required: true },
    invalid: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

function onInput(event) {
    const raw = event.target.value.trim();
    if (raw === '') return emit('update:modelValue', null);
    const value = Number(raw);
    emit('update:modelValue', Number.isFinite(value) && value > 0 ? Math.round(value * 100) / 100 : null);
}
</script>

<template>
    <input
        type="number"
        inputmode="decimal"
        min="0"
        step="any"
        placeholder="0"
        :value="props.modelValue ?? ''"
        :aria-label="label"
        :aria-invalid="invalid ? 'true' : undefined"
        :class="[inputClass({ invalid }), 'h-9 max-w-24 text-right tabular-nums [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none']"
        @input="onInput"
        @click.stop
        @keydown.stop
    >
</template>
