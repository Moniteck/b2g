<script setup>
// Calendario de plazos: rejilla mensual y agenda (predeterminada en móvil), filtro por tipo, leyenda,
// detalle con «Marcar como atendido», eventos manuales y reglas de recordatorio.
// Props: types ([{ value, label }]), defaults ({ tipo: [días] }), members ([{ value, label }]),
//        rules (solo administradores), initial ({ month, event }), can ({ create, update, delete, manage_rules }).
// Los eventos se piden por rango visible a GET /calendario/eventos.
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import CapAlert from '../../../design/vue/CapAlert.vue';
import CapSegmented from '../../../design/vue/CapSegmented.vue';
import CapSkeleton from '../../../design/vue/CapSkeleton.vue';
import AppButton from '../../Components/AppButton.vue';
import EmptyState from '../../Components/EmptyState.vue';
import ErrorState from '../../Components/ErrorState.vue';
import IconButton from '../../Components/IconButton.vue';
import SelectInput from '../../Components/SelectInput.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { getJson } from '../../lib/http.js';
import AgendaList from '../../Modules/Calendar/AgendaList.vue';
import { LEGEND, monthLabel, monthWeeks, shiftMonth, todayKey } from '../../Modules/Calendar/calendar.js';
import EventDetailModal from '../../Modules/Calendar/EventDetailModal.vue';
import EventFormModal from '../../Modules/Calendar/EventFormModal.vue';
import MonthGrid from '../../Modules/Calendar/MonthGrid.vue';
import RulesModal from '../../Modules/Calendar/RulesModal.vue';

const props = defineProps({
    types: { type: Array, default: () => [] },
    defaults: { type: Object, default: () => ({}) },
    members: { type: Array, default: () => [] },
    rules: { type: Array, default: null },
    initial: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
});

const month = ref(props.initial.month || todayKey().slice(0, 7));
const type = ref('');
const view = ref(typeof window !== 'undefined' && window.matchMedia('(max-width: 767px)').matches ? 'agenda' : 'month');
const selected = ref('');

const events = ref([]);
const loading = ref(true);
const error = ref('');
let controller = null;

const detailOpen = ref(false);
const detailId = ref(null);
const formOpen = ref(false);
const formEvent = ref(null);
const formDate = ref('');
const rulesOpen = ref(false);
const agenda = ref(null);

const VIEWS = [
    { value: 'month', label: 'Mes' },
    { value: 'agenda', label: 'Agenda' },
];

const typeOptions = computed(() => [{ value: '', label: 'Todos los tipos' }, ...props.types]);
const range = computed(() => {
    const weeks = monthWeeks(month.value);
    return { from: weeks[0][0], to: weeks[weeks.length - 1][6] };
});
const monthEvents = computed(() => events.value.filter((event) => event.starts_at.slice(0, 7) === month.value || (event.ends_at && event.starts_at.slice(0, 7) < month.value && event.ends_at.slice(0, 7) >= month.value)));
// Con la agenda vacía, el primario es el del estado vacío (uno solo por pantalla).
const showHeaderPrimary = computed(() => props.can.create && !(view.value === 'agenda' && !loading.value && !error.value && !monthEvents.value.length && !type.value));
const selectedEvents = computed(() =>
    selected.value
        ? events.value.filter((event) => event.starts_at.slice(0, 10) === selected.value || (event.ends_at && event.starts_at.slice(0, 10) <= selected.value && event.ends_at.slice(0, 10) >= selected.value))
        : [],
);

async function load() {
    controller?.abort();
    controller = new AbortController();
    loading.value = true;
    error.value = '';
    try {
        const params = { from: range.value.from, to: range.value.to };
        if (type.value) params['types[]'] = type.value;
        const response = await getJson('/calendario/eventos', params, { signal: controller.signal });
        events.value = response.events ?? [];
        loading.value = false;
    } catch (exception) {
        if (exception.name === 'AbortError') return;
        error.value = exception.message;
        loading.value = false;
    }
}

watch([month, type], () => {
    selected.value = '';
    syncUrl();
    load();
});

function syncUrl() {
    const url = new URL(window.location.href);
    url.searchParams.set('mes', month.value);
    url.searchParams.delete('evento');
    window.history.replaceState(window.history.state, '', url);
}

function go(delta) {
    month.value = shiftMonth(month.value, delta);
}

function goToday() {
    month.value = todayKey().slice(0, 7);
    selected.value = todayKey();
}

function open(event) {
    detailId.value = event.id;
    detailOpen.value = true;
}

function create(day = '') {
    formEvent.value = null;
    formDate.value = day || selected.value || (month.value === todayKey().slice(0, 7) ? todayKey() : `${month.value}-01`);
    formOpen.value = true;
}

function edit(detail) {
    formEvent.value = detail;
    detailOpen.value = false;
    formOpen.value = true;
}

function select(day) {
    selected.value = selected.value === day ? '' : day;
}

function showMore(day) {
    view.value = 'agenda';
    agenda.value?.scrollTo(day);
    setTimeout(() => agenda.value?.scrollTo(day), 50);
}

function clearFilter() {
    type.value = '';
}

onMounted(() => {
    load();
    if (props.initial.event) open({ id: Number(props.initial.event) });
});

onBeforeUnmount(() => controller?.abort());
</script>

<template>
    <AppLayout title="Calendario" :fab-label="can.create ? 'Nuevo evento' : ''" @fab="create()">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex items-center gap-2">
                <IconButton icon="chevron-left" label="Mes anterior" @click="go(-1)" />
                <h2 class="min-w-40 text-center font-display text-lg font-semibold text-gray-900 dark:text-gray-100 sm:min-w-48" aria-live="polite">{{ monthLabel(month) }}</h2>
                <IconButton icon="chevron-right" label="Mes siguiente" @click="go(1)" />
                <AppButton class="ml-1" @click="goToday">Hoy</AppButton>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <CapSegmented v-model="view" :items="VIEWS" label="Vista del calendario" />
                <div class="w-full sm:w-56">
                    <SelectInput v-model="type" :options="typeOptions" placeholder="" aria-label="Filtrar por tipo de plazo" />
                </div>
                <AppButton v-if="can.manage_rules" icon="bell-cog-outline" @click="rulesOpen = true">Reglas de recordatorio</AppButton>
                <div v-if="showHeaderPrimary" class="hidden md:block"><AppButton variant="primary" icon="plus" @click="create()">Nuevo evento</AppButton></div>
            </div>
        </div>

        <ul class="flex flex-wrap gap-x-4 gap-y-1.5" aria-label="Leyenda de colores">
            <li v-for="item in LEGEND" :key="item.label" class="flex items-center gap-1.5 text-xs text-cap-text-soft">
                <span class="h-2.5 w-2.5 rounded-full" :class="item.dot" aria-hidden="true" />{{ item.label }}
            </li>
        </ul>

        <ErrorState v-if="error" title="No se pudo cargar el calendario" :description="error" @retry="load" />

        <template v-else-if="view === 'month'">
            <div v-if="loading" class="rounded-cap-card border border-cap-divider bg-cap-card p-4" aria-busy="true">
                <CapSkeleton variant="table" :rows="5" label="Cargando el calendario" />
            </div>
            <template v-else>
                <MonthGrid :month="month" :events="events" :selected="selected" @open="open" @select="select" @more="showMore" />
                <CapAlert v-if="!monthEvents.length && type" status="info" title="Ningún plazo de ese tipo este mes" detail="Quita el filtro para ver todos los plazos." />
                <CapAlert v-else-if="!monthEvents.length" status="info" title="Sin plazos este mes" detail="Los cierres de licitación, entregas, vencimientos y eventos manuales aparecerán aquí." />

                <!-- Día elegido (sobre todo en móvil, donde la rejilla solo muestra puntos) -->
                <section v-if="selected" class="md:hidden" aria-live="polite">
                    <AgendaList :month="selected.slice(0, 7)" :events="selectedEvents.map((event) => ({ ...event, starts_at: event.starts_at.slice(0, 10) < selected ? `${selected} 00:00:00` : event.starts_at }))" @open="open" />
                    <p v-if="!selectedEvents.length" class="rounded-cap-card border border-dashed border-cap-divider px-4 py-6 text-center text-sm text-cap-text-soft">Sin plazos el {{ selected.slice(8, 10) }}/{{ selected.slice(5, 7) }}.</p>
                </section>
            </template>
        </template>

        <template v-else>
            <div v-if="loading" class="rounded-cap-card border border-cap-divider bg-cap-card p-4" aria-busy="true">
                <CapSkeleton variant="table" :rows="6" label="Cargando la agenda" />
            </div>
            <EmptyState v-else-if="!monthEvents.length && type" variant="no-results" title="Ningún plazo de ese tipo este mes" @clear="clearFilter" />
            <EmptyState v-else-if="!monthEvents.length" icon="calendar-check-outline" title="Sin plazos este mes" description="Cuando registres licitaciones, contratos o documentos con fecha, sus plazos aparecerán aquí.">
                <template v-if="can.create" #action><AppButton variant="primary" icon="plus" @click="create()">Nuevo evento</AppButton></template>
            </EmptyState>
            <AgendaList v-else ref="agenda" :month="month" :events="events" @open="open" />
        </template>

        <EventDetailModal v-model:open="detailOpen" :event-id="detailId" @edit="edit" @changed="load" />
        <EventFormModal v-if="can.create || can.update" v-model:open="formOpen" :event="formEvent" :date="formDate" :types="types" :defaults="defaults" :members="members" @saved="load" />
        <RulesModal v-if="can.manage_rules && rules" v-model:open="rulesOpen" :rules="rules" @saved="load" />
    </AppLayout>
</template>
