<script setup>
// Filtro de período de Reportes y KPI: atajo (este mes, trimestre, año…), desde, hasta y, si se pasan
// `modules`, el módulo. Aplica solo al cambiar; los errores del servidor salen bajo cada fecha.
// Props: from, to, module (v-model de cada uno), modules ([{ value, label }]), errors, defaultPreset.
// Eventos: apply.
import { computed, ref } from 'vue';
import DateInput from '../../../Components/DateInput.vue';
import FormField from '../../../Components/FormField.vue';
import SelectInput from '../../../Components/SelectInput.vue';
import { presetRange } from './finance.js';

const props = defineProps({
    from: { type: String, default: '' },
    to: { type: String, default: '' },
    module: { type: String, default: null },
    modules: { type: Array, default: () => [] },
    errors: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['update:from', 'update:to', 'update:module', 'apply']);

const PRESETS = [
    { value: 'month', label: 'Este mes' },
    { value: 'quarter', label: 'Este trimestre' },
    { value: 'ytd', label: 'Año a la fecha' },
    { value: 'year', label: 'Este año' },
    { value: 'last-year', label: 'Año anterior' },
    { value: 'custom', label: 'Personalizado' },
];

function matching() {
    const found = PRESETS.find((preset) => {
        const range = presetRange(preset.value);
        return range && range.from === props.from && range.to === props.to;
    });
    return found?.value ?? 'custom';
}

const preset = ref(matching());
const moduleOptions = computed(() => [{ value: 'all', label: 'Todos los módulos' }, ...props.modules]);

function choosePreset(value) {
    preset.value = value;
    const range = presetRange(value);
    if (!range) return;
    emit('update:from', range.from);
    emit('update:to', range.to);
    emit('apply', { from: range.from, to: range.to });
}

function changeDate(field, value) {
    emit(`update:${field}`, value);
    preset.value = 'custom';
    const next = { from: props.from, to: props.to, [field]: value };
    if (next.from && next.to) emit('apply', next);
}

function changeModule(value) {
    emit('update:module', value);
    emit('apply', { module: value });
}
</script>

<template>
    <div class="grid grid-cols-2 gap-x-4 gap-y-3 sm:flex sm:flex-wrap sm:items-start">
        <FormField label="Período" class="col-span-2 sm:w-44">
            <SelectInput :model-value="preset" :options="PRESETS" placeholder="" @update:model-value="choosePreset" />
        </FormField>
        <FormField label="Desde" :error="errors.from">
            <DateInput :model-value="from" :max="to || undefined" class="w-full sm:w-40" @update:model-value="changeDate('from', $event)" />
        </FormField>
        <FormField label="Hasta" :error="errors.to">
            <DateInput :model-value="to" :min="from || undefined" class="w-full sm:w-40" @update:model-value="changeDate('to', $event)" />
        </FormField>
        <FormField v-if="modules.length" label="Módulo" :error="errors.module" class="col-span-2 sm:w-52">
            <SelectInput :model-value="module ?? 'all'" :options="moduleOptions" placeholder="" @update:model-value="changeModule" />
        </FormField>
        <slot />
    </div>
</template>
