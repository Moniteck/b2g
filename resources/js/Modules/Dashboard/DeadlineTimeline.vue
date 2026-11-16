<script setup>
// Línea de tiempo de los próximos 14 días: una tira de días con un punto de color por tipo de plazo
// (licitaciones, contratos y entregas, documentos, cobros) y la lista de lo próximo. Tocar un día filtra
// la lista; volver a tocarlo la limpia. El expediente siempre queda visible (el título no se trunca a mitad).
// Props: items ([{ id, title, type, category, at, all_day, href }]), palette, limit (filas de la lista).
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EmptyState from '../../Components/EmptyState.vue';
import { date, time } from '../../lib/format.js';
import { addDays, TIMELINE_CATEGORIES, todayKey } from './dashboard.js';

const props = defineProps({
    items: { type: Array, default: () => [] },
    palette: { type: Object, required: true },
    limit: { type: Number, default: 6 },
});

const DAYS = 14;
const WEEKDAYS = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
const selected = ref(null);
const today = todayKey();

const days = computed(() =>
    Array.from({ length: DAYS }, (_, index) => {
        const key = addDays(today, index);
        const [y, m, d] = key.split('-').map(Number);
        const items = props.items.filter((item) => String(item.at).slice(0, 10) === key);
        const categories = [...new Set(items.map((item) => item.category))];
        return { key, day: d, weekday: WEEKDAYS[new Date(Date.UTC(y, m - 1, d)).getUTCDay()], count: items.length, categories, today: index === 0, weekend: [0, 6].includes(new Date(Date.UTC(y, m - 1, d)).getUTCDay()) };
    }),
);

const listed = computed(() => (selected.value ? props.items.filter((item) => String(item.at).slice(0, 10) === selected.value) : props.items));
const shown = computed(() => listed.value.slice(0, props.limit));
const legend = computed(() => Object.entries(TIMELINE_CATEGORIES).filter(([key]) => props.items.some((item) => item.category === key)));

function colorOf(category) {
    const index = TIMELINE_CATEGORIES[category]?.series;
    return index === null || index === undefined ? props.palette.status.neutral ?? props.palette.text : props.palette.series[index];
}

function toggle(key) {
    selected.value = selected.value === key ? null : key;
}

function when(item) {
    const key = String(item.at).slice(0, 10);
    const label = key === today ? 'Hoy' : key === addDays(today, 1) ? 'Mañana' : date(item.at, 'medium').replace(/ \d{4}$/, '');
    return item.all_day ? label : `${label}, ${time(item.at)}`;
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="-mx-1 overflow-x-auto px-1 pb-1">
            <ol class="grid min-w-[36rem] gap-1 sm:min-w-0" style="grid-template-columns: repeat(14, minmax(0, 1fr))" aria-label="Plazos por día">
                <li v-for="day in days" :key="day.key">
                    <button
                        type="button"
                        class="flex h-full min-h-16 w-full flex-col items-center gap-1 rounded-cap-control px-0.5 py-2 transition-colors duration-cap-fast ease-cap focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome dark:focus-visible:ring-gray-100"
                        :class="[selected === day.key ? 'bg-cap-card-2 ring-2 ring-cap-brand dark:ring-cap-brand-soft' : day.today ? 'bg-cap-red-50 dark:bg-cap-red-600/15' : 'hover:bg-cap-card-2', day.count ? '' : 'cursor-default']"
                        :aria-pressed="selected === day.key ? 'true' : 'false'"
                        :aria-label="`${day.today ? 'Hoy, ' : ''}${day.weekday} ${day.day}: ${day.count} ${day.count === 1 ? 'plazo' : 'plazos'}`"
                        :disabled="!day.count"
                        @click="toggle(day.key)"
                    >
                        <span class="text-xs" :class="day.today ? 'font-semibold text-cap-brand dark:text-cap-brand-soft' : day.weekend ? 'text-cap-text-soft opacity-70' : 'text-cap-text-soft'">{{ day.today ? 'hoy' : day.weekday }}</span>
                        <span class="font-display text-sm font-semibold tabular-nums" :class="day.count ? 'text-gray-900 dark:text-gray-100' : 'text-cap-text-soft'">{{ day.day }}</span>
                        <span class="flex h-2 items-center gap-0.5" aria-hidden="true">
                            <span v-for="category in day.categories.slice(0, 3)" :key="category" class="h-1.5 w-1.5 rounded-full" :style="{ backgroundColor: colorOf(category) }" />
                        </span>
                    </button>
                </li>
            </ol>
        </div>

        <ul v-if="legend.length" class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-cap-text-soft" aria-label="Tipos de plazo">
            <li v-for="[key, category] in legend" :key="key" class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full" :style="{ backgroundColor: colorOf(key) }" aria-hidden="true" />{{ category.label }}</li>
        </ul>

        <EmptyState v-if="!items.length" compact icon="calendar-check-outline" title="Sin plazos en los próximos 14 días" description="Los cierres de licitación, entregas y vencimientos aparecerán aquí." />
        <ul v-else :key="selected ?? 'all'" v-stagger class="flex flex-col gap-2">
            <li v-for="(item, index) in shown" :key="item.id" :class="{ 'max-sm:hidden': index >= 3 }">
                <Link :href="item.href" class="group flex min-h-14 items-center gap-3 rounded-cap-control bg-cap-card-2 py-2 pl-2 pr-3 transition-colors duration-cap-fast ease-cap hover:bg-cap-divider focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome dark:focus-visible:ring-gray-100">
                    <span class="w-1 self-stretch rounded-full" :style="{ backgroundColor: colorOf(item.category) }" aria-hidden="true" />
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-cap-control bg-cap-card text-cap-text-soft" aria-hidden="true">
                        <i class="mdi text-xl leading-none" :class="`mdi-${TIMELINE_CATEGORIES[item.category]?.icon ?? 'calendar-clock-outline'}`" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold text-gray-900 [overflow-wrap:anywhere] line-clamp-2 dark:text-gray-100" :title="item.title">{{ item.title }}</span>
                        <span class="block truncate text-xs text-cap-text-soft"><span class="font-medium sm:hidden" :class="String(item.at).slice(0, 10) === today ? 'text-cap-status-error-ink' : 'text-gray-900 dark:text-gray-100'">{{ when(item) }} · </span>{{ item.type }}</span>
                    </span>
                    <span class="hidden shrink-0 text-right text-xs font-medium tabular-nums sm:block" :class="String(item.at).slice(0, 10) === today ? 'text-cap-status-error-ink' : 'text-gray-900 dark:text-gray-100'">{{ when(item) }}</span>
                </Link>
            </li>
        </ul>
        <p v-if="listed.length > 3" class="text-xs text-cap-text-soft" :class="{ 'sm:hidden': listed.length <= shown.length }">{{ listed.length }} plazos en total; todos en el <Link href="/calendario" class="text-gray-900 underline decoration-gray-400 underline-offset-2 hover:decoration-current dark:text-gray-100">calendario</Link>.</p>
    </div>
</template>
