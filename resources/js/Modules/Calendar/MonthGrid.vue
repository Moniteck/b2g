<script setup>
// Rejilla mensual: semanas de lunes a domingo, barras de varios días (licitaciones y contratos con
// sus marcas) y chips por día. En móvil cada día muestra puntos y al tocarlo se lista debajo.
// Props: month ('AAAA-MM'), events (resumen del servidor), selected ('AAAA-MM-DD').
// Eventos: open (evento), select (día), more (día: ver todos en la agenda).
import { computed } from 'vue';
import { time } from '../../lib/format.js';
import { WEEKDAYS, isBar, monthWeeks, todayKey, toneOf, weekBars } from './calendar.js';

const props = defineProps({
    month: { type: String, required: true },
    events: { type: Array, default: () => [] },
    selected: { type: String, default: '' },
});

const emit = defineEmits(['open', 'select', 'more']);

const MAX_CHIPS = 3;
const LANE_PX = 24;
const today = todayKey();

const weeks = computed(() => monthWeeks(props.month));
const bars = computed(() => props.events.filter(isBar));
const singles = computed(() => {
    const byDay = new Map();
    props.events.filter((event) => !isBar(event)).forEach((event) => {
        const key = event.starts_at.slice(0, 10);
        if (!byDay.has(key)) byDay.set(key, []);
        byDay.get(key).push(event);
    });
    return byDay;
});

const rows = computed(() =>
    weeks.value.map((week) => {
        const segments = weekBars(week, bars.value, props.events);
        const lanes = segments.reduce((max, segment) => Math.max(max, segment.lane + 1), 0);
        return { week, segments, lanes };
    }),
);

/** Todos los eventos que tocan un día (para los puntos del móvil). */
function dotsOf(day) {
    const list = [...(singles.value.get(day) ?? [])];
    bars.value.forEach((event) => {
        if (event.starts_at.slice(0, 10) <= day && event.ends_at.slice(0, 10) >= day && event.starts_at.slice(0, 10) === day) list.push(event);
    });
    return list.slice(0, 4);
}

function inMonth(day) {
    return day.startsWith(props.month);
}

function chipLabel(event) {
    return event.all_day ? event.title : `${time(event.starts_at)} ${event.title}`;
}

function dayLabel(day) {
    return Number(day.slice(8, 10));
}
</script>

<template>
    <div class="overflow-hidden rounded-cap-card border border-cap-divider bg-cap-card">
        <div class="grid grid-cols-7 border-b border-cap-table-head-line bg-cap-table-head text-cap-table-head-ink">
            <div v-for="weekday in WEEKDAYS" :key="weekday" class="px-2 py-2 text-center font-display text-xs font-semibold md:text-left">{{ weekday }}</div>
        </div>

        <div v-for="row in rows" :key="row.week[0]" class="relative grid grid-cols-7 border-b border-cap-divider last:border-b-0">
            <!-- Días -->
            <div
                v-for="day in row.week"
                :key="day"
                class="min-h-16 min-w-0 border-r border-cap-divider p-1 last:border-r-0 md:min-h-32"
                :class="[inMonth(day) ? '' : 'bg-cap-card-2',selected === day ? 'ring-2 ring-inset ring-cap-chrome dark:ring-gray-100' : '']"
            >
                <button
                    type="button"
                    class="flex w-full items-center justify-center rounded-cap-control px-1 py-0.5 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome dark:focus-visible:ring-gray-100 md:justify-start"
                    :aria-label="`Ver el ${day}`"
                    :aria-pressed="selected === day ? 'true' : 'false'"
                    @click="emit('select', day)"
                >
                    <span
                        class="inline-flex h-7 min-w-7 items-center justify-center rounded-full px-1 tabular-nums"
                        :class="day === today ? 'bg-cap-chrome font-semibold text-white dark:bg-gray-100 dark:text-gray-900' : inMonth(day) ? 'text-gray-900 dark:text-gray-100' : 'text-cap-text-soft'"
                    >{{ dayLabel(day) }}</span>
                </button>

                <!-- Móvil: puntos -->
                <div class="mt-1 flex justify-center gap-1 md:hidden" aria-hidden="true">
                    <span v-for="event in dotsOf(day)" :key="event.id" class="h-1.5 w-1.5 rounded-full" :class="toneOf(event.type).dot" />
                </div>

                <!-- Escritorio: chips debajo de las barras -->
                <div class="hidden flex-col gap-1 md:flex" :style="{ marginTop: `${row.lanes * LANE_PX}px` }">
                    <button
                        v-for="event in (singles.get(day) ?? []).slice(0, MAX_CHIPS)"
                        :key="event.id"
                        type="button"
                        class="flex w-full min-w-0 items-center gap-1 rounded-md px-1.5 py-0.5 text-left text-xs transition-colors duration-cap-fast ease-cap hover:opacity-80 focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome dark:focus-visible:ring-gray-100"
                        :class="[toneOf(event.type).chip, event.attended || event.completed ? 'line-through opacity-70' : '']"
                        :title="`${event.type_label}: ${event.title}`"
                        @click="emit('open', event)"
                    >
                        <span class="h-1.5 w-1.5 shrink-0 rounded-full" :class="toneOf(event.type).dot" aria-hidden="true" />
                        <span class="truncate">{{ chipLabel(event) }}</span>
                    </button>
                    <button
                        v-if="(singles.get(day)?.length ?? 0) > MAX_CHIPS"
                        type="button"
                        class="rounded-md px-1.5 text-left text-xs font-medium text-cap-text-soft hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome dark:focus-visible:ring-gray-100"
                        @click="emit('more', day)"
                    >+{{ singles.get(day).length - MAX_CHIPS }} más</button>
                </div>
            </div>

            <!-- Barras de varios días (solo escritorio) -->
            <div class="pointer-events-none absolute inset-x-0 top-9 hidden grid-cols-7 gap-y-1 px-0.5 md:grid" :style="{ gridAutoRows: `${LANE_PX - 4}px` }">
                <button
                    v-for="segment in row.segments"
                    :key="`${segment.event.id}-${row.week[0]}`"
                    type="button"
                    class="pointer-events-auto relative mx-0.5 flex min-w-0 items-center overflow-hidden px-2 text-left text-xs font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome dark:focus-visible:ring-gray-100"
                    :class="[toneOf(segment.event.type).bar ?? toneOf(segment.event.type).chip, segment.continuesBefore ? 'rounded-l-none' : 'rounded-l-full', segment.continuesAfter ? 'rounded-r-none' : 'rounded-r-full']"
                    :style="{ gridColumn: `${segment.start + 1} / span ${segment.span}`, gridRow: segment.lane + 1 }"
                    :title="segment.event.title"
                    @click="emit('open', segment.event)"
                >
                    <span class="relative z-10 truncate">{{ segment.event.label || segment.event.title }}</span>
                    <!-- Marcas: aclaraciones y apertura en licitaciones, entregas en contratos -->
                    <span class="absolute inset-0 grid" :style="{ gridTemplateColumns: `repeat(${segment.span}, minmax(0, 1fr))` }" aria-hidden="true">
                        <span v-for="column in segment.span" :key="column" class="flex items-center justify-end pr-1.5">
                            <span v-if="segment.marks.includes(segment.start + column - 1)" class="h-2.5 w-2.5 rounded-full border-2 border-cap-card bg-current" />
                        </span>
                    </span>
                </button>
            </div>
        </div>
    </div>
</template>
