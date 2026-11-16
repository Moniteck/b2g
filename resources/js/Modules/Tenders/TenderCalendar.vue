<script setup>
// Calendario de plazos de la lista de Licitaciones: rejilla del mes en escritorio y agenda por día en
// móvil. Cada evento es un plazo (aclaraciones, recepción de ofertas, apertura) de una licitación.
// Props: month ('AAAA-MM'), events (TenderListQuery::calendar), loading. Eventos: month (nuevo mes).
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import CapSkeleton from '@design/vue/CapSkeleton.vue';
import AppButton from '@/Components/AppButton.vue';
import EmptyState from '@/Components/EmptyState.vue';
import IconButton from '@/Components/IconButton.vue';
import { date, time } from '@/lib/format.js';
import { card } from '@/lib/ui.js';
import { countdown, DEADLINE_ICONS } from '@/Modules/Tenders/tenders.js';

const props = defineProps({
    month: { type: String, required: true },
    events: { type: Array, default: () => [] },
    loading: { type: Boolean, default: false },
});

const emit = defineEmits(['month']);

const WEEKDAYS = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
const KIND_SHORT = { clarification_deadline: 'Aclaraciones', presentation_deadline: 'Recepción', opening_at: 'Apertura' };

const [year, monthIndex] = [computed(() => Number(props.month.slice(0, 4))), computed(() => Number(props.month.slice(5, 7)) - 1)];
const title = computed(() => `${MONTHS[monthIndex.value].charAt(0).toUpperCase()}${MONTHS[monthIndex.value].slice(1)} de ${year.value}`);

const todayKey = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Tegucigalpa' }).format(new Date());

const byDay = computed(() => {
    const map = new Map();
    for (const event of props.events) {
        const key = event.at.slice(0, 10);
        if (!map.has(key)) map.set(key, []);
        map.get(key).push(event);
    }
    return map;
});

const weeks = computed(() => {
    const first = new Date(Date.UTC(year.value, monthIndex.value, 1));
    const offset = (first.getUTCDay() + 6) % 7;
    const days = new Date(Date.UTC(year.value, monthIndex.value + 1, 0)).getUTCDate();
    const cells = [];
    for (let i = 0; i < offset; i++) cells.push(null);
    for (let d = 1; d <= days; d++) {
        const key = `${props.month}-${String(d).padStart(2, '0')}`;
        cells.push({ day: d, key, events: byDay.value.get(key) ?? [], today: key === todayKey });
    }
    while (cells.length % 7) cells.push(null);
    return Array.from({ length: cells.length / 7 }, (_, i) => cells.slice(i * 7, i * 7 + 7));
});

const agenda = computed(() => [...byDay.value.entries()].map(([key, events]) => ({ key, events, today: key === todayKey })));

function shift(delta) {
    const next = new Date(Date.UTC(year.value, monthIndex.value + delta, 1));
    emit('month', `${next.getUTCFullYear()}-${String(next.getUTCMonth() + 1).padStart(2, '0')}`);
}

function goToday() {
    emit('month', todayKey.slice(0, 7));
}

function tone(event) {
    if (event.past) return 'neutral';
    if (!['por_analizar', 'en_preparacion', 'presentada'].includes(event.stage?.key)) return 'neutral';
    return countdown(event.at)?.status ?? 'neutral';
}

const CHIP = {
    error: 'bg-cap-status-error-tint text-cap-status-error-ink',
    warning: 'bg-cap-status-warning-tint text-cap-status-warning-ink',
    info: 'bg-cap-status-info-tint text-cap-status-info-ink',
    neutral: 'bg-cap-card-2 text-cap-text-soft',
};
</script>

<template>
    <section :class="card" class="overflow-hidden" aria-label="Calendario de plazos" data-tender-calendar>
        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-cap-divider px-4 py-3">
            <h3 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100" aria-live="polite">{{ title }}</h3>
            <div class="flex items-center gap-1">
                <IconButton icon="chevron-left" label="Mes anterior" @click="shift(-1)" />
                <AppButton @click="goToday">Hoy</AppButton>
                <IconButton icon="chevron-right" label="Mes siguiente" @click="shift(1)" />
            </div>
        </header>

        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 border-b border-cap-divider px-4 py-2 text-xs text-cap-text-soft">
            <span v-for="(icon, kind) in DEADLINE_ICONS" :key="kind" class="inline-flex items-center gap-1"><i class="mdi text-base leading-none" :class="`mdi-${icon}`" aria-hidden="true" />{{ KIND_SHORT[kind] }}</span>
            <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-cap-status-error dark:bg-cap-status-dark-error" aria-hidden="true" />3 días o menos</span>
            <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-cap-status-warning dark:bg-cap-status-dark-warning" aria-hidden="true" />esta semana</span>
        </div>

        <CapSkeleton v-if="loading && !events.length" variant="table" :rows="5" label="Cargando el calendario" class="m-4" />

        <template v-else>
            <!-- Escritorio: rejilla del mes. -->
            <div class="hidden lg:block" :class="loading ? 'opacity-60' : ''">
                <div class="grid grid-cols-7 border-b border-cap-divider bg-cap-table-head">
                    <span v-for="day in WEEKDAYS" :key="day" class="px-2 py-2 font-display text-xs font-semibold text-cap-table-head-ink">{{ day }}</span>
                </div>
                <div v-for="(week, w) in weeks" :key="w" class="grid grid-cols-7">
                    <div v-for="(cell, d) in week" :key="d" class="min-h-28 border-b border-r border-cap-divider p-1.5 last:border-r-0" :class="cell ? '' : 'bg-cap-card-2'" :data-day="cell?.key">
                        <template v-if="cell">
                            <span class="mb-1 inline-flex h-6 min-w-6 items-center justify-center rounded-full px-1 text-xs tabular-nums" :class="cell.today ? 'bg-cap-chrome font-semibold text-white dark:bg-gray-100 dark:text-gray-900' : 'text-cap-text-soft'">{{ cell.day }}</span>
                            <ul class="flex flex-col gap-1">
                                <li v-for="event in cell.events.slice(0, 3)" :key="event.id">
                                    <Link :href="`/licitaciones/${event.tender_id}`" class="flex items-center gap-1 rounded-md px-1.5 py-1 text-xs transition-opacity duration-cap-fast ease-cap hover:opacity-80 focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome dark:focus-visible:ring-gray-100" :class="CHIP[tone(event)]" :title="`${event.label} ${time(event.at)} · ${event.reference} · ${event.title}`">
                                        <i class="mdi shrink-0 text-sm leading-none" :class="`mdi-${DEADLINE_ICONS[event.kind]}`" aria-hidden="true" />
                                        <span class="truncate font-medium">{{ event.reference }}</span>
                                        <span class="sr-only">{{ event.label }}, {{ time(event.at) }}</span>
                                    </Link>
                                </li>
                                <li v-if="cell.events.length > 3" class="px-1.5 text-xs text-cap-text-soft">y {{ cell.events.length - 3 }} más</li>
                            </ul>
                        </template>
                    </div>
                </div>
            </div>

            <!-- Móvil: agenda de los días con plazos. -->
            <div class="lg:hidden">
                <EmptyState v-if="!agenda.length" compact icon="calendar-blank-outline" title="Sin plazos este mes" description="Cambia de mes con las flechas o ajusta los filtros." class="m-4" />
                <ol v-else class="divide-y divide-cap-divider">
                    <li v-for="day in agenda" :key="day.key" class="px-4 py-3">
                        <p class="mb-2 text-xs font-semibold" :class="day.today ? 'text-cap-brand dark:text-cap-brand-soft' : 'text-cap-text-soft'">{{ day.today ? 'Hoy · ' : '' }}{{ date(day.key, 'long') }}</p>
                        <ul class="flex flex-col gap-2">
                            <li v-for="event in day.events" :key="event.id">
                                <Link :href="`/licitaciones/${event.tender_id}`" class="flex min-h-12 items-center gap-3 rounded-cap-control bg-cap-card-2 p-3">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full" :class="CHIP[tone(event)]" aria-hidden="true"><i class="mdi text-lg leading-none" :class="`mdi-${DEADLINE_ICONS[event.kind]}`" /></span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-semibold text-gray-900 dark:text-gray-100">{{ event.reference }}</span>
                                        <span class="block truncate text-xs text-cap-text-soft">{{ event.label }} · {{ time(event.at) }}</span>
                                    </span>
                                </Link>
                            </li>
                        </ul>
                    </li>
                </ol>
            </div>
            <p v-if="!events.length" class="hidden px-4 py-3 text-sm text-cap-text-soft lg:block">No hay plazos en este mes con los filtros actuales.</p>
        </template>
    </section>
</template>
