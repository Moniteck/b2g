<script setup>
// Detalle de una oportunidad (modal xl con pestañas): Datos, Participantes, Quién ganó (No Adjudicado),
// Resultado (Adjudicado), Documentos e Historial. Lee GET /pipeline/{id} (JSON).
// Props: open (v-model:open), opportunityId, tab (pestaña inicial), stages ([{ id, title, tone }]).
// Eventos: edit (detalle), revert (detalle), remove (detalle), changed (participantes o adjuntos),
//          loaded (detalle). Expone reload().
import { Link } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import CapAlert from '../../../design/vue/CapAlert.vue';
import CapSkeleton from '../../../design/vue/CapSkeleton.vue';
import AppButton from '../../Components/AppButton.vue';
import BaseModal from '../../Components/BaseModal.vue';
import DescriptionList from '../../Components/DescriptionList.vue';
import EmptyState from '../../Components/EmptyState.vue';
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
});

const emit = defineEmits(['update:open', 'edit', 'revert', 'remove', 'changed', 'loaded']);

const detail = ref(null);
const loading = ref(false);
const loadError = ref('');
const current = ref('datos');

const data = computed(() => detail.value?.data ?? {});
const outcome = computed(() => detail.value?.outcome ?? {});
const can = computed(() => detail.value?.can ?? {});
const stageTone = computed(() => props.stages.find((stage) => stage.id === data.value.stage)?.tone ?? 'neutral');

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
    { key: 'stage', label: 'Etapa', value: data.value.stageLabel },
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

const historyItems = computed(() =>
    (detail.value?.history ?? []).map((entry) => {
        const meta = historyEntry(entry);
        return { id: entry.id, title: meta.title, icon: meta.icon, status: meta.status, description: meta.description, meta: [entry.actor, dateTime(entry.createdAt, 'medium')].filter(Boolean).join(', ') };
    }),
);

async function reload() {
    if (!props.opportunityId) return;
    loading.value = true;
    loadError.value = '';
    try {
        detail.value = await getJson(`/pipeline/${props.opportunityId}`);
        if (!tabs.value.some((tab) => tab.value === current.value)) current.value = 'datos';
        emit('loaded', detail.value);
    } catch (error) {
        loadError.value = error.status === 404 ? 'La oportunidad no existe o es de otra empresa.' : error.message || 'No se pudo cargar la oportunidad.';
    } finally {
        loading.value = false;
    }
}

watch(
    () => [props.open, props.opportunityId],
    ([open]) => {
        if (!open) return;
        detail.value = null;
        current.value = props.tab;
        reload();
    },
    { immediate: true },
);

function onParticipantsSaved() {
    emit('changed');
    reload();
}

defineExpose({ reload });
</script>

<template>
    <BaseModal :open="open" size="xl" :title="detail ? data.title : 'Oportunidad'" :description="detail ? [data.code, data.institution?.name].filter(Boolean).join(' · ') : ''" @update:open="emit('update:open', $event)">
        <div v-if="loading && !detail" class="flex flex-col gap-3" aria-label="Cargando oportunidad">
            <CapSkeleton variant="stats" />
            <CapSkeleton variant="line" />
            <CapSkeleton variant="line" />
        </div>

        <ErrorState v-else-if="loadError" title="No se pudo abrir la oportunidad" :description="loadError" @retry="reload" />

        <Tabs v-else-if="detail" v-model="current" :tabs="tabs" label="Secciones de la oportunidad">
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
                            <ul v-if="detail.quotes.length" class="flex flex-col gap-1">
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
                <ParticipantsEditor :subject="{ type: 'opportunity', id: data.id }" @saved="onParticipantsSaved" />
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
                <AttachmentPanel entity-type="opportunity" :entity-id="data.id" :can-edit="Boolean(can.update)" compact title="Documentos de la oportunidad" empty-text="Aún no hay documentos de esta oportunidad." @changed="emit('changed')" />
            </template>

            <template #historial>
                <Timeline :items="historyItems" empty-text="Aún no hay movimientos registrados." />
            </template>
        </Tabs>

        <EmptyState v-else compact icon="briefcase-outline" title="Sin datos" />

        <template #footer>
            <AppButton v-if="detail && can.delete" variant="danger" icon="trash-can-outline" class="sm:mr-auto" @click="emit('remove', detail)">Eliminar</AppButton>
            <AppButton v-if="detail && can.revert && outcome.result" icon="undo-variant" :disabled="Boolean(outcome.revertBlocker)" @click="emit('revert', detail)">Revertir resultado</AppButton>
            <AppButton @click="emit('update:open', false)">Cerrar</AppButton>
            <AppButton v-if="detail && can.update && !outcome.result" variant="primary" icon="pencil-outline" @click="emit('edit', detail)">Editar datos</AppButton>
        </template>
    </BaseModal>
</template>
