<script setup>
// Calendario de un mes para `DateInput` (no se usa suelto). Semana de lunes a domingo, nombres en
// español, hoy marcado, días fuera de [min, max] deshabilitados.
// Teclado (rejilla): ← → un día, ↑ ↓ una semana, Re Pág / Av Pág un mes (con Mayús, un año),
// Inicio / Fin inicio y fin de semana, Enter elige, Esc cierra.
// Props: modelValue ('AAAA-MM-DD' | null), min, max. Eventos: select (iso), close.
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { addDays, addMonths, fromIso, isoOf, todayIso } from '@/Components/dates.js';

const props = defineProps({
    modelValue: { type: String, default: null },
    min: { type: String, default: '' },
    max: { type: String, default: '' },
});

const emit = defineEmits(['select', 'close']);

const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
const WEEKDAYS = [
    ['lu', 'lunes'],
    ['ma', 'martes'],
    ['mi', 'miércoles'],
    ['ju', 'jueves'],
    ['vi', 'viernes'],
    ['sá', 'sábado'],
    ['do', 'domingo'],
];

const grid = ref(null);
const today = todayIso();
const focused = ref(props.modelValue || clamp(today));
const view = computed(() => fromIso(focused.value));
const title = computed(() => {
    const month = MONTHS[view.value.month - 1];
    return `${month[0].toUpperCase()}${month.slice(1)} de ${view.value.year}`;
});

function clamp(iso) {
    if (props.min && iso < props.min) return props.min;
    if (props.max && iso > props.max) return props.max;
    return iso;
}

const disabled = (iso) => Boolean((props.min && iso < props.min) || (props.max && iso > props.max));

const weeks = computed(() => {
    const { year, month } = view.value;
    const first = isoOf(year, month, 1);
    const weekday = (new Date(Date.UTC(year, month - 1, 1)).getUTCDay() + 6) % 7;
    let cursor = addDays(first, -weekday);
    const rows = [];
    for (let week = 0; week < 6; week++) {
        const row = [];
        for (let day = 0; day < 7; day++) {
            const parts = fromIso(cursor);
            row.push({ iso: cursor, day: parts.day, outside: parts.month !== month, label: `${parts.day} de ${MONTHS[parts.month - 1]} de ${parts.year}` });
            cursor = addDays(cursor, 1);
        }
        rows.push(row);
    }
    return rows;
});

function focusCell() {
    nextTick(() => grid.value?.querySelector(`[data-iso="${focused.value}"]`)?.focus());
}

function move(iso) {
    focused.value = clamp(iso);
    focusCell();
}

function choose(iso) {
    if (disabled(iso)) return;
    emit('select', iso);
}

function onKeydown(event) {
    const current = focused.value;
    const weekday = (new Date(`${current}T12:00:00Z`).getUTCDay() + 6) % 7;
    const moves = {
        ArrowLeft: () => addDays(current, -1),
        ArrowRight: () => addDays(current, 1),
        ArrowUp: () => addDays(current, -7),
        ArrowDown: () => addDays(current, 7),
        PageUp: () => addMonths(current, event.shiftKey ? -12 : -1),
        PageDown: () => addMonths(current, event.shiftKey ? 12 : 1),
        Home: () => addDays(current, -weekday),
        End: () => addDays(current, 6 - weekday),
    };
    if (moves[event.key]) {
        event.preventDefault();
        move(moves[event.key]());
    } else if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        choose(current);
    } else if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        emit('close');
    }
}

watch(
    () => props.modelValue,
    (value) => {
        if (value) focused.value = value;
    },
);

onMounted(focusCell);
</script>

<template>
    <div class="app-calendar" @keydown="onKeydown">
        <div class="app-calendar__head">
            <button type="button" class="app-calendar__nav" aria-label="Mes anterior" @click="move(addMonths(focused, -1))"><i class="mdi mdi-chevron-left" aria-hidden="true" /></button>
            <p class="app-calendar__title" aria-live="polite">{{ title }}</p>
            <button type="button" class="app-calendar__nav" aria-label="Mes siguiente" @click="move(addMonths(focused, 1))"><i class="mdi mdi-chevron-right" aria-hidden="true" /></button>
        </div>
        <table ref="grid" class="app-calendar__grid" role="grid" :aria-label="title">
            <thead>
                <tr>
                    <th v-for="[short, long] in WEEKDAYS" :key="short" scope="col" :abbr="long">{{ short }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(week, index) in weeks" :key="index">
                    <td v-for="cell in week" :key="cell.iso" role="gridcell" :aria-selected="cell.iso === modelValue ? 'true' : 'false'">
                        <button
                            type="button"
                            class="app-calendar__day"
                            :data-iso="cell.iso"
                            :data-outside="cell.outside ? 'true' : undefined"
                            :data-today="cell.iso === today ? 'true' : undefined"
                            :data-selected="cell.iso === modelValue ? 'true' : undefined"
                            :tabindex="cell.iso === focused ? 0 : -1"
                            :disabled="disabled(cell.iso)"
                            :aria-label="cell.label"
                            :aria-current="cell.iso === today ? 'date' : undefined"
                            @click="choose(cell.iso)"
                        >{{ cell.day }}</button>
                    </td>
                </tr>
            </tbody>
        </table>
        <div class="app-calendar__foot">
            <button type="button" class="app-calendar__link" :disabled="disabled(today)" @click="choose(today)">Hoy</button>
            <button type="button" class="app-calendar__link" @click="emit('close')">Cerrar</button>
        </div>
    </div>
</template>
