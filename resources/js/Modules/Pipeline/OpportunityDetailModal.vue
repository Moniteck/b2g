<script setup>
// Detalle de una oportunidad (modal xl con pestañas): Datos, Participantes, Quién ganó (No Adjudicado),
// Resultado (Adjudicado), Documentos e Historial.
// Props: open (v-model:open), opportunityId, tab (pestaña inicial), stages ([{ id, title, tone }]),
//        preview (la tarjeta del tablero: título, institución, monto, etapa… se pintan al instante
//        mientras llega el detalle), initialDetail (detalle ya conocido, p. ej. `focusDetail` del enlace
//        directo /pipeline/{id}: no se vuelve a pedir).
// Eventos: edit (detalle), revert (detalle), remove (detalle), participants ({ id, count }) al guardar
//          participantes, changed ('documents') al cambiar adjuntos, loaded (detalle). Expone reload().
//
// Peticiones (rendimiento): al abrir, GET /pipeline/{id} (JSON) trae también los participantes, así
// que su pestaña no pide nada. El historial se pide solo al abrir su pestaña (GET /pipeline/{id}/historial).
// Mientras la modal está abierta, cada respuesta se guarda por id y no se vuelve a pedir al cambiar
// de pestaña; guardar participantes usa las filas que devuelve el PUT. Al cerrar se descarta todo.
import { Link } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import CapAlert from '../../../design/vue/CapAlert.vue';
import CapSkeleton from '../../../design/vue/CapSkeleton.vue';
import AppButton from '../../Components/AppButton.vue';
import BaseModal from '../../Components/BaseModal.vue';
import DescriptionList from '../../Components/DescriptionList.vue';
import ErrorState from '../../Components/ErrorState.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import Tabs from '../../Components/Tabs.vue';
import Timeline from '../../Components/Timeline.vue';
import { date, dateTime, money, relativeDays } from '../../lib/format.js';
import { getJson } from '../../lib/http.js';
import { tableLink, textLink } from '../../lib/ui.js';
import AttachmentPanel from '../Documents/AttachmentPanel.vue';
import ParticipantsEditor from '../Quotes/ParticipantsEditor.vue';
import { differenceText, historyEntry } from './pipeline.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    opportunityId: { type: [Number, String], default: null },
    tab: { type: String, default: 'datos' },
    stages: { type: Array, default: () => [] },
    preview: { type: Object, default: null },
    initialDetail: { type: Object, default: null },
});

const emit = defineEmits(['update:open', 'edit', 'revert', 'remove', 'participants', 'changed', 'loaded']);

const detail = ref(null);
const loading = ref(false);
const loadError = ref('');
const current = ref('datos');
const history = ref(null);
const historyLoading = ref(false);
const historyError = ref('');

/** Respuestas por id durante la vida de la modal: { detail, history }. */
const cache = new Map();
let requestSeq = 0;

const idKey = computed(() => (props.opportunityId === null || props.opportunityId === undefined ? null : String(props.opportunityId)));
const previewData = computed(() => (props.preview && String(props.preview.id) === idKey.value ? props.preview : null));
const data = computed(() => detail.value?.data ?? previewData.value ?? {});
const outcome = computed(() => detail.value?.outcome ?? {});
const can = computed(() => detail.value?.can ?? {});
const stage = computed(() => props.stages.find((item) => item.id === data.value.stage));
const stageTone = computed(() => stage.value?.tone ?? 'neutral');
// Sujeto estable: solo cambia si cambia el id (el editor no vuelve a pedir en cada render).
const participantsSubject = computed(() => (data.value.id ? { type: 'opportunity', id: data.value.id } : null));

const tabs = computed(() => {
    if (!detail.value) return [{ value: 'datos', label: 'Datos' }];
    return [
        { value: 'datos', label: 'Datos', icon: 'card-text-outline' },
        { value: 'participantes', label: 'Participantes', icon: 'account-group-outline', count: data.value.participantsCount || undefined },
        ...(data.value.stage === 'no_adjudicado' ? [{ value: 'ganador', label: 'Quién ganó', icon: 'trophy-variant-outline' }] : []),
        ...(data.value.stage === 'adjudicado' ? [{ value: 'resultado', label: 'Resultado', icon: 'trophy-outline' }] : []),
        { value: 'documentos', label: 'Documentos', icon: 'paperclip' },
        { value: 'historial', label: 'Historial', icon: 'history' },
    ];
});

const facts = computed(() => [
    { key: 'stage', label: 'Etapa', value: data.value.stageLabel ?? stage.value?.title },
    { key: 'institution', label: 'Institución', value: data.value.institution?.name },
    { label: 'Monto estimado', value: money(data.value.amount) },
    { label: 'Probabilidad', value: data.value.probability === null || data.value.probability === undefined ? null : `${data.value.probability} %` },
    { key: 'keyDate', label: data.value.keyDate?.label ?? 'Fecha clave', value: data.value.keyDate?.date },
    { label: 'Cierre esperado', value: data.value.expectedCloseDate ? date(data.value.expectedCloseDate, 'medium') : null },
    { label: 'Responsable', value: data.value.owner?.name ?? 'Sin asignar' },
    { key: 'tender', label: 'Licitación', value: data.value.tender?.reference },
    { key: 'quotes', label: 'Cotizaciones', value: detail.value?.quotes?.length ? detail.value.quotes.length : null },
    { label: 'Contacto', value: data.value.contact ? [data.value.contact.name, data.value.contact.position].filter(Boolean).join(' · ') : null },
    { label: 'Próxima acción', value: data.value.nextAction },
    { label: 'Creada', value: data.value.createdAt ? dateTime(data.value.createdAt, 'medium') : null },
]);

/** Lo que ya trae la tarjeta del tablero: se ve al instante mientras llega el detalle. */
const previewFacts = computed(() => facts.value.filter((fact) => ['stage', 'institution', 'keyDate'].includes(fact.key) || ['Monto estimado', 'Probabilidad', 'Responsable'].includes(fact.label)));

const historyItems = computed(() =>
    (history.value ?? []).map((entry) => {
        const meta = historyEntry(entry);
        return { id: entry.id, title: meta.title, icon: meta.icon, status: meta.status, description: meta.description, meta: [entry.actor, dateTime(entry.createdAt, 'medium')].filter(Boolean).join(', ') };
    }),
);

function entry(id) {
    if (!cache.has(id)) cache.set(id, { detail: null, history: null });
    return cache.get(id);
}

function show(response) {
    detail.value = response;
    if (!tabs.value.some((item) => item.value === current.value)) current.value = 'datos';
    emit('loaded', response);
}

/** Pide el detalle (siempre a la red). `reload()` lo usa tras guardar fuera de la modal. */
async function reload() {
    const id = idKey.value;
    if (!id || !props.open) return;
    const seq = ++requestSeq;
    loading.value = true;
    loadError.value = '';
    try {
        const response = await getJson(`/pipeline/${id}`);
        if (seq !== requestSeq || id !== idKey.value) return;
        Object.assign(entry(id), { detail: response, history: null });
        history.value = null;
        show(response);
        if (current.value === 'historial') loadHistory();
    } catch (error) {
        if (seq !== requestSeq) return;
        loadError.value = error.status === 404 ? 'La oportunidad no existe o es de otra empresa.' : error.message || 'No se pudo cargar la oportunidad.';
    } finally {
        if (seq === requestSeq) loading.value = false;
    }
}

async function loadHistory() {
    const id = idKey.value;
    if (!id || historyLoading.value) return;
    const cached = entry(id).history;
    if (cached) {
        history.value = cached;
        return;
    }
    historyLoading.value = true;
    historyError.value = '';
    try {
        const response = await getJson(`/pipeline/${id}/historial`);
        if (id !== idKey.value) return;
        entry(id).history = response.data ?? [];
        history.value = entry(id).history;
    } catch (error) {
        historyError.value = error.message || 'No se pudo cargar el historial.';
    } finally {
        historyLoading.value = false;
    }
}

watch(
    () => [props.open, idKey.value],
    ([open, id]) => {
        if (!open) {
            cache.clear();
            requestSeq++;
            return;
        }
        if (!id) return;
        detail.value = null;
        history.value = null;
        historyError.value = '';
        loadError.value = '';
        current.value = props.tab;
        const known = entry(id).detail ?? (props.initialDetail && String(props.initialDetail.data?.id) === id ? props.initialDetail : null);
        if (known) {
            entry(id).detail = known;
            show(known);
            if (current.value === 'historial') loadHistory();
        } else {
            reload();
        }
    },
    { immediate: true },
);

// Historial perezoso: solo al abrir su pestaña (y una vez por id mientras la modal siga abierta).
watch(current, (value) => {
    if (value === 'historial' && detail.value && !history.value) loadHistory();
});

/** El historial cambió (participantes o adjuntos): se vuelve a pedir la próxima vez que se abra. */
function invalidateHistory() {
    if (idKey.value) entry(idKey.value).history = null;
    history.value = null;
}

/** Guardado de participantes: se actualiza con las filas del PUT, sin volver a pedir el detalle. */
function onParticipantsSaved(response) {
    if (!detail.value) return;
    const summary = response.summary ?? detail.value.participants;
    const ours = summary?.ours_amount ?? null;
    const winner = detail.value.outcome?.winnerAmount ?? null;
    detail.value = {
        ...detail.value,
        data: { ...detail.value.data, participantsCount: response.data.length },
        participants: summary,
        participantsPayload: { ...detail.value.participantsPayload, data: response.data, summary },
        outcome: {
            ...detail.value.outcome,
            ourAmount: ours,
            difference: winner !== null && ours !== null ? Math.round((ours - winner) * 100) / 100 : null,
            differencePercent: winner !== null && ours !== null && winner > 0 ? Math.round(((ours - winner) / winner) * 10000) / 100 : null,
        },
    };
    entry(idKey.value).detail = detail.value;
    invalidateHistory();
    emit('participants', { id: detail.value.data.id, count: response.data.length });
}

function onAttachmentsChanged() {
    invalidateHistory();
    emit('changed', 'documents');
}

defineExpose({ reload });
</script>

<template>
    <BaseModal :open="open" size="xl" :title="data.title || 'Oportunidad'" :description="[data.code, data.institution?.name].filter(Boolean).join(' · ')" @update:open="emit('update:open', $event)">
        <ErrorState v-if="loadError && !detail" title="No se pudo abrir la oportunidad" :description="loadError" @retry="reload" />

        <!-- Apertura inmediata: lo que ya trae la tarjeta mientras llega el resto del detalle. -->
        <div v-else-if="!detail" class="flex flex-col gap-4" aria-busy="true" aria-label="Cargando oportunidad">
            <template v-if="previewData">
                <div class="flex gap-1 border-b border-cap-divider pb-3" aria-hidden="true">
                    <CapSkeleton variant="line" class="w-24" />
                    <CapSkeleton variant="line" class="w-28" />
                    <CapSkeleton variant="line" class="w-24" />
                </div>
                <DescriptionList :items="previewFacts">
                    <template #stage="{ item }"><StatusBadge :status="stageTone" :label="item.value" /></template>
                    <template #institution="{ item }">{{ item.value ?? '—' }}</template>
                    <template #keyDate="{ item }">
                        <span v-if="item.value">{{ date(item.value, 'medium') }} <span class="text-cap-text-soft">({{ relativeDays(item.value) }})</span></span>
                        <span v-else class="text-cap-text-soft">—</span>
                    </template>
                </DescriptionList>
            </template>
            <CapSkeleton v-else variant="stats" />
            <CapSkeleton variant="line" />
            <CapSkeleton variant="line" />
        </div>

        <Tabs v-else v-model="current" :tabs="tabs" label="Secciones de la oportunidad">
            <template #datos>
                <div class="flex flex-col gap-4">
                    <DescriptionList :items="facts">
                        <template #stage="{ item }"><StatusBadge :status="stageTone" :label="item.value" /></template>
                        <template #institution="{ item }">{{ item.value ?? '—' }}</template>
                        <template #keyDate="{ item }">
                            <span v-if="item.value">{{ date(item.value, 'medium') }} <span class="text-cap-text-soft">({{ relativeDays(item.value) }})</span></span>
                            <span v-else class="text-cap-text-soft">—</span>
                        </template>
                        <template #tender="{ item }">
                            <Link v-if="data.tender" :href="`/licitaciones/${data.tender.id}`" :class="tableLink">{{ item.value }}</Link>
                            <span v-else class="text-cap-text-soft">—</span>
                        </template>
                        <template #quotes>
                            <ul v-stagger v-if="detail.quotes.length" class="flex flex-col gap-1">
                                <li v-for="quote in detail.quotes" :key="quote.id" class="flex flex-wrap items-center gap-2">
                                    <Link :href="`/cotizaciones?search=${encodeURIComponent(quote.number)}`" :class="tableLink" class="tabular-nums">{{ quote.number }}</Link>
                                    <span class="tabular-nums text-cap-text-soft">{{ money(quote.total) }}</span>
                                    <StatusBadge :value="quote.status" :label="quote.status" />
                                    <StatusBadge v-if="quote.outcome !== 'Pendiente'" :value="quote.outcome" :label="quote.outcome" />
                                </li>
                            </ul>
                            <span v-else class="text-cap-text-soft">Sin cotización</span>
                        </template>
                    </DescriptionList>
                    <div v-if="data.notes" class="rounded-cap-control bg-cap-card-2 p-3">
                        <p class="text-xs text-cap-text-soft">Notas</p>
                        <p class="mt-1 whitespace-pre-line text-sm text-gray-900 dark:text-gray-100">{{ data.notes }}</p>
                    </div>
                </div>
            </template>

            <template #participantes>
                <ParticipantsEditor v-if="participantsSubject" :subject="participantsSubject" :initial="detail.participantsPayload" @saved="onParticipantsSaved" />
            </template>

            <template #ganador>
                <div class="flex flex-col gap-4" data-tab-winner>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div class="rounded-cap-control bg-cap-card-2 p-3">
                            <p class="text-xs text-cap-text-soft">Ganador</p>
                            <p class="truncate font-display text-xl font-semibold text-gray-900 dark:text-gray-100" :title="outcome.winnerName">{{ outcome.winnerName || '—' }}</p>
                        </div>
                        <div class="rounded-cap-control bg-cap-card-2 p-3">
                            <p class="text-xs text-cap-text-soft">Valor de su oferta</p>
                            <p class="font-display text-xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ outcome.winnerAmount !== null ? money(outcome.winnerAmount) : '—' }}</p>
                        </div>
                        <div class="rounded-cap-control bg-cap-card-2 p-3">
                            <p class="text-xs text-cap-text-soft">Nuestra oferta contra la ganadora</p>
                            <p class="font-display text-xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ differenceText(outcome.difference, outcome.differencePercent) }}</p>
                            <p class="text-xs text-cap-text-soft">{{ outcome.ourAmount !== null ? `Nuestra oferta: ${money(outcome.ourAmount)}` : 'Sin nuestra oferta registrada' }}</p>
                        </div>
                    </div>
                    <DescriptionList :items="[{ label: 'Fecha del resultado', value: outcome.awardedAt ? date(outcome.awardedAt, 'medium') : null }, { label: 'Motivo', value: outcome.lostReason }]" />
                </div>
            </template>

            <template #resultado>
                <div class="flex flex-col gap-4">
                    <CapAlert status="ok" title="Oferta ganada" :detail="`Adjudicada por ${money(outcome.awardedAmount)}${outcome.awardedAt ? ' el ' + date(outcome.awardedAt, 'medium') : ''}.`" />
                    <ul class="flex flex-col gap-3 text-sm">
                        <li class="flex items-center gap-2">
                            <i class="mdi mdi-domain text-xl leading-none text-cap-text-soft" aria-hidden="true" />
                            <span>Cliente: <Link v-if="outcome.client" :href="outcome.client.url" :class="textLink">{{ outcome.client.name }}</Link><span v-else class="text-cap-text-soft">no registrado</span></span>
                        </li>
                        <li class="flex items-center gap-2">
                            <i class="mdi mdi-file-sign text-xl leading-none text-cap-text-soft" aria-hidden="true" />
                            <span>Contrato: <Link v-if="outcome.contract" :href="outcome.contract.url" :class="textLink">{{ outcome.contract.number }}</Link><span v-else class="text-cap-text-soft">no registrado</span></span>
                            <StatusBadge v-if="outcome.contract" :value="outcome.contract.status" :label="outcome.contract.status" />
                        </li>
                    </ul>
                    <CapAlert v-if="can.revert && outcome.revertBlocker" status="warning" title="No se puede revertir" :detail="outcome.revertBlocker" />
                </div>
            </template>

            <template #documentos>
                <AttachmentPanel entity-type="opportunity" :entity-id="data.id" :can-edit="Boolean(can.update)" compact title="Documentos de la oportunidad" empty-text="Aún no hay documentos de esta oportunidad." @changed="onAttachmentsChanged" />
            </template>

            <template #historial>
                <ErrorState v-if="historyError" compact title="No se pudo cargar el historial" :description="historyError" @retry="loadHistory" />
                <div v-else-if="!history" class="flex flex-col gap-3" aria-busy="true" aria-label="Cargando historial">
                    <CapSkeleton variant="line" />
                    <CapSkeleton variant="line" />
                    <CapSkeleton variant="line" />
                </div>
                <Timeline v-else :items="historyItems" empty-text="Aún no hay movimientos registrados." />
            </template>
        </Tabs>

        <template #footer>
            <AppButton v-if="detail && can.delete" variant="danger" icon="trash-can-outline" class="sm:mr-auto" @click="emit('remove', detail)">Eliminar</AppButton>
            <AppButton v-if="detail && can.revert && outcome.result" icon="undo-variant" :disabled="Boolean(outcome.revertBlocker)" @click="emit('revert', detail)">Revertir resultado</AppButton>
            <AppButton @click="emit('update:open', false)">Cerrar</AppButton>
            <AppButton v-if="detail && can.update && !outcome.result" variant="primary" icon="pencil-outline" @click="emit('edit', detail)">Editar datos</AppButton>
        </template>
    </BaseModal>
</template>
