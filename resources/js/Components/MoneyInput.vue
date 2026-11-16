<script setup>
// Monto con el símbolo dentro del campo, cifra a la derecha y `tabular-nums`.
// Props: modelValue (v-model: número o null), currency ('HNL' | 'USD'; con `v-model:currency` y
//        `currencies` de dos o más monedas el símbolo es un selector), currencies, min, max,
//        allowNegative, invalid, readonly, id, required.
// Al salir del campo muestra «12,345.67»; al entrar, la cifra sin separadores para editarla.
// Ancho fijo de 160 px (176 con selector de moneda); `class="w-full"` lo estira.
import { computed, ref, watch } from 'vue';
import { useField } from '@/composables/useField.js';
import { currencySymbol, number } from '@/lib/format.js';
import { inputClass } from '@/lib/ui.js';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    modelValue: { type: [Number, String], default: null },
    currency: { type: String, default: 'HNL' },
    currencies: { type: Array, default: () => [] },
    min: { type: Number, default: null },
    max: { type: Number, default: null },
    allowNegative: { type: Boolean, default: false },
    invalid: { type: Boolean, default: false },
    readonly: { type: Boolean, default: false },
    id: { type: String, default: '' },
    required: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'update:currency']);

const { id: fieldId, invalid: isInvalid, aria } = useField(props);
const focused = ref(false);
const draft = ref('');

const selectable = computed(() => props.currencies.length > 1);
const symbol = computed(() => currencySymbol(props.currency));

function toNumber(value) {
    if (value === null || value === undefined || value === '') return null;
    const parsed = Number(String(value).replace(/,/g, '').trim());
    return Number.isFinite(parsed) ? parsed : null;
}

function clamp(value) {
    if (value === null) return null;
    let result = Math.round(value * 100) / 100;
    if (!props.allowNegative && result < 0) result = Math.abs(result);
    if (props.min !== null) result = Math.max(props.min, result);
    if (props.max !== null) result = Math.min(props.max, result);
    return result;
}

const display = computed(() => {
    if (focused.value) return draft.value;
    const value = toNumber(props.modelValue);
    return value === null ? '' : number(value, 2);
});

watch(
    () => props.modelValue,
    (value) => {
        if (focused.value && toNumber(draft.value) !== toNumber(value)) draft.value = value === null || value === '' ? '' : String(value);
    },
);

function onFocus(event) {
    const value = toNumber(props.modelValue);
    draft.value = value === null ? '' : String(value);
    focused.value = true;
    requestAnimationFrame(() => event.target.select());
}

function onInput(event) {
    // Solo dígitos, un punto decimal y, si se permite, el signo menos al inicio.
    let cleaned = event.target.value.replace(/,/g, '').replace(props.allowNegative ? /[^\d.-]/g : /[^\d.]/g, '');
    const [whole, ...decimals] = cleaned.split('.');
    cleaned = decimals.length ? `${whole}.${decimals.join('').slice(0, 2)}` : whole;
    draft.value = cleaned;
    if (event.target.value !== cleaned) event.target.value = cleaned;
    emit('update:modelValue', toNumber(cleaned));
}

function onBlur() {
    focused.value = false;
    emit('update:modelValue', clamp(toNumber(draft.value)));
}
</script>

<template>
    <div class="relative" :class="[$attrs.class ?? (selectable ? 'w-44' : 'w-40')]">
        <select
            v-if="selectable"
            :value="currency"
            aria-label="Moneda"
            :disabled="readonly || $attrs.disabled"
            class="absolute left-px top-px h-[calc(100%-2px)] w-16 rounded-l-cap-control border-0 border-r border-cap-divider bg-cap-card-2 pl-2 text-sm font-medium text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-cap-chrome dark:text-gray-300 dark:[color-scheme:dark] dark:focus-visible:ring-gray-100"
            @change="emit('update:currency', $event.target.value)"
        >
            <option v-for="code in currencies" :key="code" :value="code">{{ currencySymbol(code) }}</option>
        </select>
        <span v-else class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-cap-text-soft" aria-hidden="true">{{ symbol }}</span>
        <input
            :id="fieldId"
            v-bind="{ ...$attrs, ...aria, class: undefined }"
            type="text"
            inputmode="decimal"
            autocomplete="off"
            :value="display"
            :readonly="readonly"
            placeholder="0.00"
            :class="[inputClass({ invalid: isInvalid, readonly }), 'h-10 text-right tabular-nums', selectable ? 'pl-20' : symbol.length > 1 ? 'pl-12' : 'pl-8']"
            @focus="onFocus"
            @input="onInput"
            @blur="onBlur"
        >
    </div>
</template>
