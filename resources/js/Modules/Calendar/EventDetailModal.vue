<script setup>
// Detalle de un evento: fechas, origen con enlace, responsable, recordatorios programados y
// enviados, «Marcar como atendido» y, en los manuales, Editar y Eliminar.
// Props: open (v-model:open), eventId. Eventos: edit (detalle del evento), changed (tras atender,
// reabrir o eliminar: la página recarga el rango).
import { Link } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import CapSkeleton from '../../../design/vue/CapSkeleton.vue';
import AppButton from '../../Components/AppButton.vue';
import BaseModal from '../../Components/BaseModal.vue';
import DescriptionList from '../../Components/DescriptionList.vue';
import ErrorState from '../../Components/ErrorState.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import { useConfirm } from '../../composables/useConfirm.js';
import { date, dateTime, time } from '../../lib/format.js';
import { getJson, visit } from '../../lib/http.js';
import { textLink } from '../../lib/ui.js';
import { TYPE_ICONS, toneOf } from './calendar.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    eventId: { type: Number, default: null },
});

const emit = defineEmits(['update:open', 'edit', 'changed']);

const confirm = useConfirm();
const detail = ref(null);
const loading = ref(false);
const error = ref('');
const busy = ref(false);

async function load() {
    if (!props.eventId) return;
    loading.value = true;
    error.value = '';
    try {
        detail.value = await getJson(`/calendario/${props.eventId}`);
    } catch (exception) {
        error.value = exception.message;
        detail.value = null;
    } finally {
        loading.value = false;
    }
}

watch(
    () => [props.open, props.eventId],
    ([open]) => {
        if (open) load();
    },
    { immediate: true },
);

const event = computed(() => detail.value?.event ?? null);
const can = computed(() => detail.value?.can ?? {});

function when(value, allDay) {
    return allDay ? date(value, 'medium') : dateTime(value, 'medium');
}

const items = computed(() => {
    if (!event.value) return [];
    const current = event.value;
    const list = [
        { key: 'type', label: 'Tipo', value: current.type_label },
        { key: 'state', label: 'Estado', value: '' },
        { label: current.ends_at ? 'Inicio' : 'Fecha', value: when(current.starts_at, current.all_day) },
    ];
    if (current.ends_at) list.push({ label: 'Fin', value: when(current.ends_at, current.all_day) });
    list.push({ key: 'source', label: 'Origen', value: current.source_label });
    list.push({ label: 'Responsable', value: current.owner || current.creator || '' });
    if (current.description) list.push({ label: 'Descripción', value: current.description, wide: true });
    return list;
});

const state = computed(() => {
    const current = event.value;
    if (!current) return null;
    if (current.cancelled) return { status: 'neutral', label: 'Cancelado' };
    if (current.attended) return { status: 'ok', label: 'Atendido' };
    if (current.completed) return { status: 'ok', label: 'Completado' };
    return { status: 'warning', label: 'Pendiente' };
});

// El historial muestra lo enviado y lo fallido; los cancelados solo se cuentan.
const history = computed(() => (detail.value?.deliveries.history ?? []).filter((delivery) => delivery.status !== 'Cancelado'));
const cancelledCount = computed(() => (detail.value?.deliveries.history ?? []).length - history.value.length);

const DELIVERY_STATUS = { Programado: 'info', Encolado: 'info', Enviado: 'ok', Fallido: 'error', Cancelado: 'neutral' };

function close() {
    emit('update:open', false);
}

async function act(method, url, after) {
    busy.value = true;
    try {
        await visit(method, url, {}, { preserveState: true, only: ['flash', 'notifications'] });
        emit('changed');
        after?.();
    } catch {
        // El toast de error lo muestra el marco con `flash`.
    } finally {
        busy.value = false;
    }
}

const attend = () => act('post', `/calendario/${props.eventId}/atendido`, load);
const reopen = () => act('delete', `/calendario/${props.eventId}/atendido`, load);

function remove() {
    confirm({
        verb: 'Eliminar',
        object: `el evento «${event.value.title}»`,
        noun: 'evento',
        message: 'Se quitará del calendario y se cancelarán sus recordatorios pendientes.',
        action: () => act('delete', `/calendario/${props.eventId}`, close),
    });
}
</script>

<template>
    <BaseModal :open="open" size="lg" :title="event?.title ?? 'Detalle del evento'" @update:open="emit('update:open', $event)">
        <div v-if="loading && !event" class="flex flex-col gap-4" aria-busy="true">
            <CapSkeleton variant="line" :rows="6" label="Cargando el evento" />
        </div>
        <ErrorState v-else-if="error" compact title="No se pudo cargar el evento" :description="error" @retry="load" />
        <div v-else-if="event" class="flex flex-col gap-6">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-cap-control" :class="toneOf(event.type).chip" aria-hidden="true">
                    <i class="mdi text-xl leading-none" :class="`mdi-${TYPE_ICONS[event.type] ?? 'calendar-outline'}`" />
                </span>
                <p class="min-w-0 text-sm text-cap-text-soft">
                    <template v-if="event.attended">Atendido por {{ event.attended_by || 'un usuario' }} el {{ dateTime(event.attended_at, 'medium') }}; sus avisos se detuvieron para todos</template>
                    <template v-else-if="event.cancelled">El registro de origen se cerró o se eliminó: este plazo ya no avisa.</template>
                    <template v-else-if="event.completed">El plazo se completó en su registro de origen.</template>
                    <template v-else>Los avisos se envían al responsable, a quien lo creó y a los administradores.</template>
                </p>
            </div>

            <DescriptionList :items="items">
                <template #state><StatusBadge v-if="state" :status="state.status" :label="state.label" /></template>
                <template #source="{ item }">
                    <Link v-if="event.href" :href="event.href" class="text-sm text-gray-900 dark:text-gray-100" :class="textLink">{{ item.value }}: {{ event.label || event.title }}</Link>
                    <span v-else class="text-sm text-gray-900 dark:text-gray-100">{{ item.value }}</span>
                </template>
            </DescriptionList>

            <section aria-labelledby="event-scheduled">
                <h3 id="event-scheduled" class="mb-2 font-display text-sm font-semibold text-gray-900 dark:text-gray-100">Recordatorios programados</h3>
                <p v-if="!detail.deliveries.scheduled.length" class="text-sm text-cap-text-soft">No hay avisos pendientes para este plazo.</p>
                <ul v-stagger v-else class="divide-y divide-cap-divider rounded-cap-control border border-cap-divider">
                    <li v-for="delivery in detail.deliveries.scheduled" :key="delivery.id" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 text-sm">
                        <span class="min-w-0 flex-1 truncate text-gray-900 dark:text-gray-100">{{ delivery.offset }} · {{ delivery.channel }} · {{ delivery.recipient }}</span>
                        <span class="text-xs tabular-nums text-cap-text-soft">{{ dateTime(delivery.scheduled_for) }}</span>
                    </li>
                </ul>
            </section>

            <section aria-labelledby="event-history">
                <h3 id="event-history" class="mb-2 font-display text-sm font-semibold text-gray-900 dark:text-gray-100">Historial de envíos</h3>
                <p v-if="!history.length" class="text-sm text-cap-text-soft">Todavía no se ha enviado ningún aviso.</p>
                <ul v-stagger v-else class="divide-y divide-cap-divider rounded-cap-control border border-cap-divider">
                    <li v-for="delivery in history" :key="delivery.id" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 text-sm">
                        <span class="min-w-0 flex-1 truncate text-gray-900 dark:text-gray-100" :title="delivery.error || ''">{{ delivery.offset }} · {{ delivery.channel }} · {{ delivery.recipient }}</span>
                        <span class="text-xs tabular-nums text-cap-text-soft">{{ delivery.sent_at ? dateTime(delivery.sent_at) : time(delivery.scheduled_for) }}</span>
                        <StatusBadge :status="DELIVERY_STATUS[delivery.status] ?? 'neutral'" :label="delivery.status" />
                        <p v-if="delivery.error" class="w-full text-xs text-cap-status-error-ink">{{ delivery.error }}</p>
                    </li>
                </ul>
                <p v-if="cancelledCount" class="mt-2 text-xs text-cap-text-soft">{{ cancelledCount }} {{ cancelledCount === 1 ? 'aviso cancelado' : 'avisos cancelados' }} (plazo atendido, reprogramado o cerrado).</p>
            </section>
        </div>

        <template #footer>
            <AppButton v-if="can.delete" variant="danger" icon="trash-can-outline" :disabled="busy" @click="remove">Eliminar</AppButton>
            <AppButton v-if="can.update" icon="pencil-outline" :disabled="busy" @click="emit('edit', event)">Editar</AppButton>
            <AppButton v-if="can.reopen" icon="restore" :loading="busy" @click="reopen">Volver a pendiente</AppButton>
            <AppButton @click="close">Cerrar</AppButton>
            <AppButton v-if="can.attend" variant="primary" icon="check-circle-outline" :loading="busy" @click="attend">Marcar como atendido</AppButton>
        </template>
    </BaseModal>
</template>
