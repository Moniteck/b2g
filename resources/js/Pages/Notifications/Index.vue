<script setup>
// «Mis recordatorios»: historial de los avisos de la campana del usuario en la empresa activa,
// con filtros leído/no leído y tipo de plazo, y enlace al registro de origen.
// Props: reminders (paginador: [{ id, title, body, href, read_at, created_at, severity, type_label, due_at, all_day, offset }]),
//        filters ({ estado, tipo }), types, unread, can ({ manage_templates }).
import { Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import CapSegmented from '../../../design/vue/CapSegmented.vue';
import AppButton from '../../Components/AppButton.vue';
import EmptyState from '../../Components/EmptyState.vue';
import Pagination from '../../Components/Pagination.vue';
import SelectInput from '../../Components/SelectInput.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { date, dateTime, relativeTime } from '../../lib/format.js';
import { postJson } from '../../lib/http.js';
import { card } from '../../lib/ui.js';

const props = defineProps({
    reminders: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    types: { type: Array, default: () => [] },
    unread: { type: Number, default: 0 },
    can: { type: Object, default: () => ({}) },
});

const status = ref(props.filters.estado ?? 'todos');
const type = ref(props.filters.tipo ?? '');
const loading = ref(false);

const STATUSES = computed(() => [
    { value: 'todos', label: 'Todos' },
    { value: 'no-leidos', label: 'No leídos', count: props.unread },
    { value: 'leidos', label: 'Leídos' },
]);
const typeOptions = computed(() => [{ value: '', label: 'Todos los tipos' }, ...props.types]);
const filtered = computed(() => status.value !== 'todos' || Boolean(type.value));
const SEVERITY = { error: { status: 'error', label: 'Urgente' }, warning: { status: 'warning', label: 'Mañana' }, info: { status: 'info', label: 'Próximo' } };
const sections = computed(() => [
    { label: 'Mis recordatorios', href: '/notificaciones', active: true },
    { label: 'Calendario', href: '/calendario' },
    ...(props.can.manage_templates ? [{ label: 'Plantillas', href: '/notificaciones/plantillas' }] : []),
]);

watch([status, type], () => {
    router.get('/notificaciones', { estado: status.value === 'todos' ? undefined : status.value, tipo: type.value || undefined }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['reminders', 'filters', 'unread'],
        onStart: () => (loading.value = true),
        onFinish: () => (loading.value = false),
    });
});

async function openReminder(item) {
    if (!item.read_at) await postJson(`/notificaciones/${item.id}/leer`).catch(() => {});
    if (item.href) router.visit(item.href);
    else router.reload({ only: ['reminders', 'unread', 'notifications'] });
}

function markAll() {
    router.post('/notificaciones/leer-todas', {}, { preserveScroll: true, only: ['reminders', 'unread', 'notifications', 'flash'] });
}

function reset() {
    status.value = 'todos';
    type.value = '';
}
</script>

<template>
    <AppLayout title="Mis recordatorios" :sections="sections">
        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div class="flex flex-wrap items-center gap-2">
                <div class="max-w-full overflow-x-auto"><CapSegmented v-model="status" :items="STATUSES" label="Filtrar por lectura" /></div>
                <div class="w-full sm:w-56">
                    <SelectInput v-model="type" :options="typeOptions" placeholder="" aria-label="Filtrar por tipo de plazo" />
                </div>
            </div>
            <AppButton v-if="unread > 0" icon="check-all" @click="markAll">Marcar todo como leído</AppButton>
        </div>

        <EmptyState v-if="!reminders.data.length && filtered" variant="no-results" @clear="reset" />
        <EmptyState v-else-if="!reminders.data.length" icon="bell-check-outline" title="Aún no tienes recordatorios" description="Cuando se acerque un plazo del que seas responsable te avisaremos aquí y en la campana." />

        <ul v-else :class="[card, 'divide-y divide-cap-divider overflow-hidden', loading ? 'opacity-60' : '']" :aria-busy="loading ? 'true' : 'false'">
            <li v-for="item in reminders.data" :key="item.id">
                <component
                    :is="item.href ? 'button' : 'div'"
                    type="button"
                    class="flex w-full items-start gap-3 px-4 py-3 text-left transition-colors duration-cap-fast ease-cap hover:bg-cap-card-2 focus:outline-none focus-visible:bg-cap-card-2"
                    @click="openReminder(item)"
                >
                    <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="item.read_at ? 'bg-transparent' : 'bg-cap-brand dark:bg-cap-brand-soft'" :aria-label="item.read_at ? 'Leído' : 'No leído'" role="img" />
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm text-gray-900 dark:text-gray-100" :class="item.read_at ? '' : 'font-semibold'">{{ item.title }}</span>
                        <span class="block truncate text-sm text-cap-text-soft" :title="item.event_title">{{ item.event_title }}</span>
                        <span class="mt-0.5 block text-xs text-cap-text-soft">{{ item.type_label }} · {{ item.offset }} · Plazo: {{ item.all_day ? date(item.due_at, 'medium') : dateTime(item.due_at, 'medium') }}</span>
                    </span>
                    <span class="flex shrink-0 flex-col items-end gap-1">
                        <span class="text-xs text-cap-text-soft">{{ relativeTime(item.created_at) }}</span>
                        <StatusBadge v-if="!item.read_at && SEVERITY[item.severity]" :status="SEVERITY[item.severity].status" :label="SEVERITY[item.severity].label" />
                    </span>
                </component>
            </li>
        </ul>

        <Pagination :paginator="reminders" :per-page-options="[]" :only="['reminders']" />
        <p class="text-sm text-cap-text-soft">
            ¿Necesitas ver todos los plazos? Abre el <Link href="/calendario" class="text-gray-900 underline decoration-gray-400 underline-offset-2 hover:decoration-current dark:text-gray-100">calendario</Link>.
        </p>
    </AppLayout>
</template>
