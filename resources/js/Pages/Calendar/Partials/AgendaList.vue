<script setup>
// Vista Agenda: los eventos del mes agrupados por día, en orden. Cada evento aparece en su fecha de
// inicio; las barras indican hasta cuándo corren. Expone `scrollTo(día)` para «+N más».
// Props: month ('AAAA-MM'), events. Eventos: open (evento).
import { computed, nextTick, ref } from 'vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { date, time } from '@/lib/format.js';
import { card } from '@/lib/ui.js';
import { TYPE_ICONS, isBar, todayKey, toneOf } from '@/Pages/Calendar/Partials/calendar.js';

const props = defineProps({
    month: { type: String, required: true },
    events: { type: Array, default: () => [] },
});

const emit = defineEmits(['open']);

const root = ref(null);
const today = todayKey();

const days = computed(() => {
    const groups = new Map();
    props.events
        .filter((event) => event.starts_at.slice(0, 7) === props.month)
        .forEach((event) => {
            const key = event.starts_at.slice(0, 10);
            if (!groups.has(key)) groups.set(key, []);
            groups.get(key).push(event);
        });
    const list = [...groups.entries()].sort(([a], [b]) => a.localeCompare(b)).map(([key, items]) => ({ key, items }));
    // Procesos que empezaron antes y siguen corriendo este mes.
    const ongoing = props.events.filter((event) => isBar(event) && event.starts_at.slice(0, 7) < props.month && event.ends_at.slice(0, 7) >= props.month);
    return ongoing.length ? [{ key: 'ongoing', items: ongoing }, ...list] : list;
});

function heading(key) {
    if (key === 'ongoing') return 'En curso este mes';
    const text = date(key, 'long');
    const label = text.charAt(0).toUpperCase() + text.slice(1);
    return key === today ? `Hoy · ${label}` : label;
}

function whenOf(event) {
    if (isBar(event)) return `Hasta el ${date(event.ends_at, 'medium')}`;
    return event.all_day ? 'Todo el día' : time(event.starts_at);
}

function stateOf(event) {
    if (event.attended) return { status: 'ok', label: 'Atendido' };
    if (event.completed) return { status: 'ok', label: 'Completado' };
    return null;
}

async function scrollTo(day) {
    await nextTick();
    const target = root.value?.querySelector(`[data-day="${day}"]`) ?? root.value?.querySelector('[data-day]');
    target?.scrollIntoView({ block: 'start', behavior: 'smooth' });
}

defineExpose({ scrollTo });
</script>

<template>
    <div v-stagger ref="root" class="flex flex-col gap-4">
        <section v-for="day in days" :key="day.key" :data-day="day.key" :class="[card, 'scroll-mt-28 overflow-hidden']" :aria-label="heading(day.key)">
            <h3 class="border-b border-cap-divider px-4 py-2.5 font-display text-sm font-semibold" :class="day.key === today ? 'text-cap-brand dark:text-cap-brand-soft' : 'text-gray-900 dark:text-gray-100'">{{ heading(day.key) }}</h3>
            <ul class="divide-y divide-cap-divider">
                <li v-for="event in day.items" :key="event.id">
                    <button
                        type="button"
                        class="flex min-h-14 w-full items-center gap-3 px-4 py-2.5 text-left transition-colors duration-cap-fast ease-cap hover:bg-cap-card-2 focus:outline-none focus-visible:bg-cap-card-2"
                        @click="emit('open', event)"
                    >
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-cap-control" :class="toneOf(event.type).chip" aria-hidden="true">
                            <i class="mdi text-lg leading-none" :class="`mdi-${TYPE_ICONS[event.type] ?? 'calendar-outline'}`" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-semibold text-gray-900 dark:text-gray-100" :class="event.attended || event.completed ? 'line-through' : ''" :title="event.title">{{ event.title }}</span>
                            <span class="block truncate text-xs text-cap-text-soft">{{ event.type_label }} · {{ whenOf(event) }}</span>
                        </span>
                        <StatusBadge v-if="stateOf(event)" :status="stateOf(event).status" :label="stateOf(event).label" class="hidden sm:inline-flex" />
                        <i class="mdi mdi-chevron-right shrink-0 text-xl leading-none text-cap-text-soft" aria-hidden="true" />
                    </button>
                </li>
            </ul>
        </section>
    </div>
</template>
