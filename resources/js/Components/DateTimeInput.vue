<script setup>
// Fecha y hora (B-07): fecha «dd/mm/aaaa» con calendario propio (`DateInput`) y hora de 24 h
// («14:30»; también acepta «2:30 p. m.»), con la equivalencia «a. m./p. m.» como ayuda.
// El valor sigue siendo «AAAA-MM-DDTHH:mm», hora de Honduras; acepta «2026-03-05 14:05:00» del servidor.
// Props: modelValue (v-model), min, max, step (segundos; 300 = ↑ ↓ en saltos de 5 min), invalid,
//        readonly, id, required.
// Atributos: `class` y `style` van al contenedor; el resto al campo de fecha.
import { computed, ref, useAttrs, watch } from 'vue';
import { useField } from '@/composables/useField.js';
import { inputClass } from '@/lib/ui.js';
import DateInput from '@/Components/DateInput.vue';
import { maskTime, meridiemLabel, parseTime } from '@/Components/dates.js';

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

const attrs = useAttrs();
const { id: fieldId, invalid: isInvalid, aria } = useField(props);

const value = computed(() => String(props.modelValue ?? '').replace(' ', 'T').slice(0, 16));
const datePart = computed(() => value.value.slice(0, 10) || null);
const timePart = computed(() => (/T\d{2}:\d{2}$/.test(value.value) ? value.value.slice(11, 16) : ''));

const timeText = ref(timePart.value);
const timeWrong = ref(false);
watch(timePart, (time) => {
    if (parseTime(timeText.value) !== time) timeText.value = time;
});

const rootAttrs = computed(() => ({ class: attrs.class, style: attrs.style }));
const dateAttrs = computed(() => {
    const { class: _class, style: _style, ...rest } = attrs;
    return rest;
});
const stepMinutes = computed(() => Math.max(1, Math.round(Number(props.step ?? 60) / 60)));
const hint = computed(() => meridiemLabel(parseTime(timeText.value) ?? ''));

function emitValue(date, time) {
    if (!date) return emit('update:modelValue', null);
    emit('update:modelValue', `${date}T${time || '00:00'}`);
}

function onDate(date) {
    if (date && !timeText.value) timeText.value = '00:00';
    emitValue(date, parseTime(timeText.value));
}

function onTimeInput(event) {
    const raw = event.target.value;
    const masked = /[a-z]/i.test(raw) ? raw : maskTime(raw);
    timeText.value = masked;
    if (masked !== raw) event.target.value = masked;
    timeWrong.value = false;
    const parsed = parseTime(masked);
    if (parsed && masked.replace(/\D/g, '').length === 4 && datePart.value) emitValue(datePart.value, parsed);
}

function commitTime() {
    if (!timeText.value.trim()) {
        timeWrong.value = false;
        if (datePart.value) emitValue(datePart.value, '00:00');
        return;
    }
    const parsed = parseTime(timeText.value);
    if (!parsed) {
        timeWrong.value = true;
        return;
    }
    timeText.value = parsed;
    if (datePart.value && parsed !== timePart.value) emitValue(datePart.value, parsed);
}

function onTimeKeydown(event) {
    if (event.key !== 'ArrowUp' && event.key !== 'ArrowDown') {
        if (event.key === 'Enter') commitTime();
        return;
    }
    event.preventDefault();
    const [hours, minutes] = (parseTime(timeText.value) ?? '00:00').split(':').map(Number);
    const delta = (event.key === 'ArrowUp' ? 1 : -1) * stepMinutes.value;
    const total = (((hours * 60 + minutes + delta) % 1440) + 1440) % 1440;
    timeText.value = `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
    if (datePart.value) emitValue(datePart.value, timeText.value);
}
</script>

<template>
    <span class="app-datetime-input inline-flex max-w-full flex-wrap items-start gap-2" v-bind="rootAttrs">
        <DateInput
            :id="fieldId"
            v-bind="{ ...dateAttrs, ...aria }"
            :model-value="datePart"
            :min="min ? min.slice(0, 10) : ''"
            :max="max ? max.slice(0, 10) : ''"
            :invalid="isInvalid"
            :readonly="readonly"
            @update:model-value="onDate"
        />
        <span class="inline-flex flex-col">
            <input
                :id="`${fieldId}-hora`"
                type="text"
                inputmode="numeric"
                autocomplete="off"
                maxlength="10"
                placeholder="hh:mm"
                aria-label="Hora (24 h)"
                :aria-describedby="hint ? `${fieldId}-hora-ayuda` : undefined"
                :aria-invalid="isInvalid || timeWrong ? 'true' : undefined"
                :value="timeText"
                :readonly="readonly"
                :class="[inputClass({ invalid: isInvalid || timeWrong, readonly }), 'h-10 !w-24 text-center tabular-nums']"
                @input="onTimeInput"
                @blur="commitTime"
                @keydown="onTimeKeydown"
            />
            <span v-if="hint" :id="`${fieldId}-hora-ayuda`" class="mt-0.5 text-center text-xs text-cap-text-soft">{{ hint }}</span>
        </span>
    </span>
</template>
