<script setup>
// Ficha del contrato: resumen, documentos (con firmar y sellar), entregas, seguimientos e historial.
import { Deferred, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CapAlert from '@design/vue/CapAlert.vue';
import CapSkeleton from '@design/vue/CapSkeleton.vue';
import AppButton from '@/Components/AppButton.vue';
import DescriptionList from '@/Components/DescriptionList.vue';
import DropdownMenu from '@/Components/DropdownMenu.vue';
import EmptyState from '@/Components/EmptyState.vue';
import IconButton from '@/Components/IconButton.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Tabs from '@/Components/Tabs.vue';
import Timeline from '@/Components/Timeline.vue';
import { useConfirm } from '@/composables/useConfirm.js';
import AppLayout from '@/Layouts/AppLayout.vue';
import { date, dateTime, money, quantity } from '@/lib/format.js';
import { visit } from '@/lib/http.js';
import { card, tableLink } from '@/lib/ui.js';
import AttachmentPanel from '@/Modules/Documents/AttachmentPanel.vue';
import DocumentViewerModal from '@/Modules/Documents/DocumentViewerModal.vue';
import SignDocumentModal from '@/Modules/Signatures/SignDocumentModal.vue';
import ContractFormModal from '@/Modules/Contracts/ContractFormModal.vue';
import DeliverModal from '@/Modules/Contracts/DeliverModal.vue';
import DeliveryModal from '@/Modules/Contracts/DeliveryModal.vue';
import FollowUpModal from '@/Modules/Contracts/FollowUpModal.vue';
import StatusModal from '@/Modules/Contracts/StatusModal.vue';
import { CATEGORIES, DELIVERY_TONES, HISTORY_LABELS, historyDetail, percentOf, remaining, termLabel, tone } from '@/Modules/Contracts/contracts.js';

const props = defineProps({
    contract: { type: Object, required: true },
    deliveries: { type: Array, default: () => [] },
    followUps: { type: Array, default: () => [] },
    history: { type: Array, default: undefined },
    statuses: { type: Array, default: () => [] },
    tones: { type: Object, default: () => ({}) },
    transitions: { type: Array, default: () => [] },
    officials: { type: Array, default: () => [] },
    form: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
});

const page = usePage();
const confirm = useConfirm();
const query = new URLSearchParams(page.url.split('?')[1] ?? '');
const TABS = ['resumen', 'documentos', 'entregas', 'seguimientos', 'historial'];
const tab = ref(TABS.includes(query.get('tab')) ? query.get('tab') : 'resumen');

const editOpen = ref(false);
const statusOpen = ref(false);
const statusMode = ref('change');
const statusPreset = ref('');
const deliveryOpen = ref(false);
const editingDelivery = ref(null);
const deliverOpen = ref(false);
const delivering = ref(null);
const followUpOpen = ref(props.can.followUp && query.get('nuevo') === '1' && tab.value === 'seguimientos');
const signOpen = ref(false);
const signing = ref(null);
const evidence = ref(null);
const panel = ref(null);

const counted = computed(() => props.deliveries.filter((d) => d.status !== 'Cancelada'));
const done = computed(() => counted.value.filter((d) => d.status === 'Entregada').length);
const allDelivered = computed(() => counted.value.length > 0 && done.value === counted.value.length);
const badge = computed(() => remaining(props.contract.end_date, props.contract.is_open));
const canClose = computed(() => props.can.close && props.transitions.includes('Cerrado'));
const suggestDelivered = computed(() => allDelivered.value && props.can.update && props.transitions.includes('Producto entregado'));
const changeable = computed(() => props.transitions.filter((s) => s !== 'Cerrado'));
const primaryAction = computed(() => {
    if (props.contract.status === 'Pendiente de contrato' && props.can.update) return 'completar';
    if (canClose.value && (props.contract.product_delivered || props.contract.status === 'Producto entregado')) return 'cerrar';
    return null;
});

const tabs = computed(() => [
    { value: 'resumen', label: 'Resumen', icon: 'card-text-outline' },
    { value: 'documentos', label: 'Documentos', icon: 'paperclip' },
    { value: 'entregas', label: 'Entregas', icon: 'truck-outline', count: props.deliveries.length || undefined },
    { value: 'seguimientos', label: 'Seguimientos', icon: 'message-text-outline', count: props.followUps.length || undefined },
    { value: 'historial', label: 'Historial', icon: 'history' },
]);

const summary = computed(() => {
    const c = props.contract;
    return [
        { key: 'status', label: 'Estado', value: c.status },
        { key: 'product', label: 'Producto entregado', value: c.product_delivered },
        { label: 'Número', value: c.number },
        { label: 'Nombre u objeto', value: c.name, wide: true },
        { key: 'institution', label: 'Institución', value: c.institution },
        { key: 'client', label: 'Cliente', value: c.client },
        { key: 'origin', label: 'Origen', value: c.quote || c.tender || c.opportunity, wide: true },
        { label: 'Monto adjudicado', value: c.awarded_amount !== null ? money(c.awarded_amount) : null },
        { label: 'Monto del contrato', value: money(c.amount) },
        { label: 'Fecha de firma', value: c.signed_at ? date(c.signed_at, 'long') : null },
        { label: 'Plazo', value: termLabel(c) },
        { label: 'Inicio', value: c.start_date ? date(c.start_date, 'long') : null },
        { key: 'end', label: 'Fin', value: c.end_date },
        { key: 'contact', label: 'Persona responsable del contacto', value: c.contact },
        { key: 'manager', label: 'Oficial de seguimiento', value: c.manager },
        { label: 'Notas', value: c.notes, wide: true },
        { label: 'Registrado', value: [c.created_by, c.created_at ? dateTime(c.created_at, 'medium') : null].filter(Boolean).join(' · ') || null },
    ];
});

const followUpItems = computed(() =>
    props.followUps.map((item) => ({
        ...item,
        title: item.type,
        icon: { Llamada: 'phone-outline', Reunión: 'account-group-outline', Correo: 'email-outline', Visita: 'map-marker-outline', Oficio: 'file-document-outline' }[item.type] ?? 'message-text-outline',
        status: item.next_action_at ? 'info' : undefined,
    })),
);

const historyItems = computed(() =>
    (props.history ?? []).map((entry) => {
        const meta = HISTORY_LABELS[entry.action] ?? { title: entry.action, icon: 'circle-small' };
        return { id: entry.id, title: meta.title, icon: meta.icon, status: meta.status, description: historyDetail(entry), meta: [entry.actor, dateTime(entry.created_at, 'medium')].filter(Boolean).join(', ') };
    }),
);

const menu = computed(() => [
    ...(props.can.update ? [{ label: 'Editar datos', icon: 'pencil-outline', onSelect: () => (editOpen.value = true) }] : []),
    ...(props.can.update && changeable.value.length ? [{ label: 'Cambiar estado', icon: 'swap-horizontal', onSelect: () => openStatus('change') }] : []),
    ...(canClose.value && primaryAction.value !== 'cerrar' ? [{ label: 'Cerrar contrato', icon: 'lock-check-outline', onSelect: () => openStatus('close') }] : []),
    { label: 'Ver en el calendario', icon: 'calendar-month-outline', href: `/calendario${props.contract.end_date ? `?fecha=${props.contract.end_date}` : ''}` },
    ...(props.can.delete ? [{ label: 'Eliminar contrato', icon: 'trash-can-outline', danger: true, separator: true, onSelect: remove }] : []),
]);

function setTab(value) {
    tab.value = value;
    const url = `/post-adjudicacion/${props.contract.id}?tab=${value}`;
    window.history.replaceState({ ...window.history.state, url }, '', url);
}

function openStatus(mode, preset = '') {
    statusMode.value = mode;
    statusPreset.value = preset;
    statusOpen.value = true;
}

function newDelivery() {
    editingDelivery.value = null;
    deliveryOpen.value = true;
}

function editDelivery(delivery) {
    editingDelivery.value = delivery;
    deliveryOpen.value = true;
}

function markDelivered(delivery) {
    delivering.value = delivery;
    deliverOpen.value = true;
}

function deliveryActions(delivery) {
    if (!props.can.followUp) return [];
    return [
        ...(delivery.status !== 'Entregada' ? [{ label: 'Marcar entregada', icon: 'truck-check-outline', onSelect: () => markDelivered(delivery) }] : []),
        { label: 'Editar entrega', icon: 'pencil-outline', onSelect: () => editDelivery(delivery) },
        { label: 'Eliminar entrega', icon: 'trash-can-outline', danger: true, separator: true, onSelect: () => removeDelivery(delivery) },
    ];
}

function removeDelivery(delivery) {
    confirm({
        verb: 'Eliminar',
        object: `la entrega «${delivery.description}»`,
        noun: 'entrega',
        message: 'Se quita del contrato y del calendario. La evidencia se conserva en Documentos.',
        action: () => visit('delete', `/post-adjudicacion/${props.contract.id}/entregas/${delivery.id}`),
    });
}

function removeFollowUp(item) {
    confirm({
        verb: 'Eliminar',
        object: 'este seguimiento',
        noun: 'seguimiento',
        message: 'Su próxima acción deja de avisar en el calendario.',
        action: () => visit('delete', `/post-adjudicacion/${props.contract.id}/seguimientos/${item.id}`),
    });
}

function remove() {
    confirm({
        verb: 'Eliminar',
        object: `el contrato ${props.contract.number}`,
        noun: 'contrato',
        message: 'Se borran sus entregas y seguimientos, y sus eventos del calendario. Los documentos se conservan. Para un contrato adjudicado es mejor cancelarlo.',
        action: () => visit('delete', `/post-adjudicacion/${props.contract.id}`),
    });
}

function sign(item) {
    signing.value = { id: item.id, name: item.name, previewUrl: `/firmas/documentos/${item.id}/vista` };
    signOpen.value = true;
}

function onSigned() {
    panel.value?.reload();
}

function isPdf(item) {
    return (item.mime ?? '').includes('pdf') || (item.fileName ?? item.name ?? '').toLowerCase().endsWith('.pdf');
}
</script>

<template>
    <AppLayout :title="contract.number" back-href="/post-adjudicacion" :breadcrumbs="[{ label: 'Post-adjudicación', href: '/post-adjudicacion' }, { label: contract.number }]">
        <section :class="card" class="p-5">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <StatusBadge :status="tone(contract.status, tones)" :label="contract.status" />
                        <StatusBadge v-if="contract.product_delivered && contract.status !== 'Producto entregado'" status="ok" label="Producto entregado" />
                        <StatusBadge v-if="badge && contract.end_date" v-bind="badge" />
                    </div>
                    <h2 class="mt-2 break-words font-display text-lg font-semibold text-gray-900 dark:text-gray-100">{{ contract.name }}</h2>
                    <p class="mt-1 text-sm text-cap-text-soft">
                        {{ contract.institution?.name ?? 'Institución sin asignar' }}
                        <span v-if="contract.awarded_amount !== null"> · {{ money(contract.awarded_amount) }}</span>
                        <span v-if="contract.end_date"> · Fin {{ date(contract.end_date, 'medium') }}</span>
                    </p>
                </div>
                <div class="flex shrink-0 flex-wrap items-center gap-2">
                    <AppButton v-if="can.update && primaryAction !== 'completar'" icon="pencil-outline" @click="editOpen = true">Editar</AppButton>
                    <AppButton v-if="primaryAction === 'completar'" variant="primary" icon="pencil-outline" @click="editOpen = true">Completar datos</AppButton>
                    <AppButton v-else-if="primaryAction === 'cerrar'" variant="primary" icon="lock-check-outline" @click="openStatus('close')">Cerrar contrato</AppButton>
                    <DropdownMenu label="Más acciones" :items="menu" />
                </div>
            </div>
            <div v-if="counted.length" class="mt-4 flex flex-col gap-1.5">
                <div class="flex items-center justify-between text-xs text-cap-text-soft">
                    <span>Avance de entregas</span>
                    <span class="tabular-nums">{{ done }} de {{ counted.length }} · {{ percentOf(done, counted.length) }} %</span>
                </div>
                <div class="h-2 w-full overflow-hidden rounded-full bg-cap-card-2" role="progressbar" :aria-valuenow="percentOf(done, counted.length)" aria-valuemin="0" aria-valuemax="100" aria-label="Avance de entregas">
                    <div class="m-fill h-full rounded-full bg-cap-status-ok dark:bg-cap-status-dark-ok" :style="{ width: `${percentOf(done, counted.length)}%` }" />
                </div>
            </div>
        </section>

        <div v-if="suggestDelivered" class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <CapAlert class="flex-1" status="ok" title="Todas las entregas están completas" detail="Puedes pasar el contrato a «Producto entregado» para dejarlo listo para el cierre." />
            <AppButton icon="check-all" @click="openStatus('change', 'Producto entregado')">Pasar a Producto entregado</AppButton>
        </div>
        <CapAlert v-if="contract.status === 'Pendiente de contrato' && can.update" status="info" title="Contrato pendiente de completar" detail="Nació de una oferta ganada. Completa número, plazo, fechas, contacto y oficial, y sube el PDF del contrato en Documentos." />

        <Tabs :model-value="tab" :tabs="tabs" label="Secciones del contrato" @update:model-value="setTab">
            <template #resumen>
                <section :class="card" class="p-5">
                    <DescriptionList :items="summary">
                        <template #status="{ item }"><StatusBadge :status="tone(item.value, tones)" :label="item.value" /></template>
                        <template #product="{ item }"><StatusBadge :status="item.value ? 'ok' : 'neutral'" :label="item.value ? 'Sí' : 'No'" /></template>
                        <template #institution="{ item }">
                            <Link v-if="item.value" :href="`/instituciones/${item.value.id}`" :class="tableLink">{{ item.value.name }}</Link><span v-else>—</span>
                        </template>
                        <template #client="{ item }">
                            <Link v-if="item.value" :href="`/clientes/${item.value.id}`" :class="tableLink">{{ item.value.name }}</Link><span v-else>—</span>
                        </template>
                        <template #origin>
                            <span v-if="!contract.quote && !contract.tender && !contract.opportunity">Registrado a mano</span>
                            <span v-else class="flex flex-wrap gap-x-4 gap-y-1">
                                <Link v-if="contract.quote" :href="`/cotizaciones?search=${encodeURIComponent(contract.quote.number)}`" :class="tableLink">Cotización {{ contract.quote.number }}</Link>
                                <Link v-if="contract.tender" :href="`/licitaciones/${contract.tender.id}`" :class="tableLink">Licitación {{ contract.tender.reference }}</Link>
                                <Link v-if="contract.opportunity" :href="`/pipeline/${contract.opportunity.id}`" :class="tableLink">Oportunidad: {{ contract.opportunity.title }}</Link>
                            </span>
                        </template>
                        <template #end="{ item }">
                            <span v-if="item.value" class="flex flex-wrap items-center gap-2">
                                {{ date(item.value, 'long') }}
                                <StatusBadge v-if="contract.is_end_date_manual" status="neutral" label="Fijada a mano" />
                            </span>
                            <span v-else>—</span>
                        </template>
                        <template #contact="{ item }">
                            <span v-if="item.value" class="block">
                                {{ item.value.name }}<span v-if="item.value.position" class="text-cap-text-soft"> · {{ item.value.position }}</span>
                                <span v-if="item.value.email || item.value.phone" class="block text-xs text-cap-text-soft">{{ [item.value.phone, item.value.email].filter(Boolean).join(' · ') }}</span>
                            </span>
                            <span v-else>—</span>
                        </template>
                        <template #manager="{ item }">{{ item.value?.name ?? '—' }}</template>
                    </DescriptionList>
                </section>
            </template>

            <template #documentos>
                <section>
                    <AttachmentPanel ref="panel" entity-type="contract" :entity-id="contract.id" :can-edit="can.update" :categories="CATEGORIES" title="Documentos del contrato" add-label="Subir documento" empty-text="Sube el PDF del contrato, adendas, órdenes de compra, actas y facturas." compact>
                        <template #actions="{ item }">
                            <IconButton v-if="can.sign && isPdf(item)" icon="draw" :label="`Firmar y sellar ${item.name}`" @click="sign(item)" />
                        </template>
                    </AttachmentPanel>
                </section>
            </template>

            <template #entregas>
                <section :class="card" class="p-5">
                    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <h3 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Entregas</h3>
                        <AppButton v-if="can.followUp" icon="plus" @click="newDelivery">Nueva entrega</AppButton>
                    </div>
                    <EmptyState v-if="!deliveries.length" compact icon="truck-outline" title="Sin entregas" description="Registra cada entrega comprometida: su fecha va al calendario con recordatorios." />
                    <ul v-stagger v-else class="divide-y divide-cap-divider">
                        <li v-for="delivery in deliveries" :key="delivery.id" class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="break-words text-sm font-medium text-gray-900 dark:text-gray-100">{{ delivery.description }}</p>
                                    <StatusBadge :status="DELIVERY_TONES[delivery.status] ?? 'neutral'" :label="delivery.status" />
                                </div>
                                <p class="text-xs text-cap-text-soft">
                                    {{ quantity(delivery.quantity) }} {{ delivery.unit ?? '' }}
                                    · Comprometida {{ delivery.due_date ? date(delivery.due_date, 'medium') : 'sin fecha' }}
                                    <span v-if="delivery.delivered_at"> · Entregada {{ date(delivery.delivered_at, 'medium') }}</span>
                                </p>
                                <p v-if="delivery.notes" class="mt-0.5 break-words text-xs text-gray-700 dark:text-gray-300">{{ delivery.notes }}</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-1">
                                <AppButton v-if="delivery.evidence" icon="file-eye-outline" @click="evidence = delivery.evidence">Evidencia</AppButton>
                                <AppButton v-if="can.followUp && delivery.status !== 'Entregada'" icon="truck-check-outline" @click="markDelivered(delivery)">Marcar entregada</AppButton>
                                <DropdownMenu v-if="deliveryActions(delivery).length" :label="`Acciones de la entrega ${delivery.description}`" :items="deliveryActions(delivery)" />
                            </div>
                        </li>
                    </ul>
                </section>
            </template>

            <template #seguimientos>
                <section :class="card" class="p-5">
                    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <h3 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Seguimientos</h3>
                        <AppButton v-if="can.followUp" icon="plus" @click="followUpOpen = true">Nuevo seguimiento</AppButton>
                    </div>
                    <Timeline :items="followUpItems" empty-text="Aún no hay seguimientos.">
                        <template #item="{ item }">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ item.type }}</p>
                                    <p class="mt-0.5 whitespace-pre-line break-words text-sm text-gray-700 dark:text-gray-300">{{ item.description }}</p>
                                    <p v-if="item.next_action || item.next_action_at" class="mt-1 flex flex-wrap items-center gap-2 text-xs text-gray-700 dark:text-gray-300">
                                        <i class="mdi mdi-calendar-arrow-right text-base leading-none text-cap-text-soft" aria-hidden="true" />
                                        Próxima acción: {{ item.next_action || 'seguimiento' }}<span v-if="item.next_action_at">, {{ date(item.next_action_at, 'medium') }}</span>
                                    </p>
                                    <p class="text-xs text-cap-text-soft">{{ [item.user, dateTime(item.occurred_at, 'medium')].filter(Boolean).join(', ') }}</p>
                                </div>
                                <IconButton v-if="can.followUp && (item.user_id === page.props.auth?.user?.id || can.delete)" icon="trash-can-outline" :label="`Eliminar seguimiento ${item.type}`" @click="removeFollowUp(item)" />
                            </div>
                        </template>
                    </Timeline>
                </section>
            </template>

            <template #historial>
                <section :class="card" class="p-5">
                    <Deferred data="history">
                        <template #fallback><CapSkeleton variant="line" :rows="4" label="Cargando historial" /></template>
                        <Timeline :items="historyItems" empty-text="Aún no hay movimientos registrados." />
                    </Deferred>
                </section>
            </template>
        </Tabs>

        <CapAlert v-if="!can.update" status="info" title="Solo lectura" detail="Tu rol permite ver y descargar este contrato, pero no modificarlo." />

        <ContractFormModal v-model:open="editOpen" :contract="contract" :officials="officials" :options="form" />
        <StatusModal v-model:open="statusOpen" :mode="statusMode" :preset="statusPreset" :contract="contract" :transitions="transitions" :deliveries="deliveries" />
        <DeliveryModal v-model:open="deliveryOpen" :contract-id="contract.id" :delivery="editingDelivery" :statuses="form.deliveryStatuses" />
        <DeliverModal v-model:open="deliverOpen" :contract-id="contract.id" :delivery="delivering" />
        <FollowUpModal v-model:open="followUpOpen" :contract-id="contract.id" :types="form.followUpTypes" />
        <SignDocumentModal v-model:open="signOpen" :document="signing" @signed="onSigned" />
        <DocumentViewerModal :open="Boolean(evidence)" :document="evidence" title="Evidencia de entrega" @update:open="evidence = $event ? evidence : null" />
    </AppLayout>
</template>
