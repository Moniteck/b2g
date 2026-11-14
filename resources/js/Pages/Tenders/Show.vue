<script setup>
import { Deferred, Link, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import CapAlert from '../../../design/vue/CapAlert.vue';
import CapSkeleton from '../../../design/vue/CapSkeleton.vue';
import AppButton from '../../Components/AppButton.vue';
import BaseModal from '../../Components/BaseModal.vue';
import DescriptionList from '../../Components/DescriptionList.vue';
import DropdownMenu from '../../Components/DropdownMenu.vue';
import EmptyState from '../../Components/EmptyState.vue';
import IconButton from '../../Components/IconButton.vue';
import PdfViewer from '../../Components/PdfViewer.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import Tabs from '../../Components/Tabs.vue';
import Timeline from '../../Components/Timeline.vue';
import { useConfirm } from '../../composables/useConfirm.js';
import AppLayout from '../../Layouts/AppLayout.vue';
import { date, dateTime, daysUntil, fileSize, money } from '../../lib/format.js';
import { visit } from '../../lib/http.js';
import { card, tableLink } from '../../lib/ui.js';
import BidAnalysisPanel from '../../Modules/Tenders/BidAnalysisPanel.vue';
import TenderFormModal from '../../Modules/Tenders/TenderFormModal.vue';
import { CRITICAL_DATES, deadline, tenderTone } from '../../Modules/Tenders/tenders.js';

const props = defineProps({
    tender: { type: Object, required: true },
    opportunity: { type: Object, default: null },
    quotes: { type: Array, default: () => [] },
    documents: { type: Array, default: undefined },
    analyses: { type: Array, default: () => [] },
    capture: { type: Object, default: null },
    matrices: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    modalities: { type: Array, default: () => [] },
    ai: { type: Object, default: () => ({}) },
    maxPdfMb: { type: Number, default: 40 },
    can: { type: Object, default: () => ({}) },
});

const page = usePage();
const confirm = useConfirm();
const query = computed(() => new URLSearchParams(page.url.split('?')[1] ?? ''));
const TABS = ['datos', 'fechas', 'documentos', 'analisis', 'oportunidad'];
const tab = ref(TABS.includes(query.value.get('tab')) ? query.value.get('tab') : 'datos');
const editOpen = ref(props.can.update && query.value.get('editar') === '1');
const preview = ref(null);

watch(() => query.value.get('tab'), (value) => {
    if (TABS.includes(value)) tab.value = value;
});

const closed = computed(() => !['Activa', 'En preparación'].includes(props.tender.status));
const next = computed(() => {
    if (closed.value) return null;
    const upcoming = CRITICAL_DATES.filter((item) => item.key !== 'started_at' && props.tender[item.key])
        .map((item) => ({ ...item, value: props.tender[item.key], badge: deadline(props.tender[item.key]) }))
        .filter((item) => daysUntil(item.value) >= 0);
    return upcoming[0] ?? null;
});

const tabs = computed(() => [
    { value: 'datos', label: 'Datos', icon: 'card-text-outline' },
    { value: 'fechas', label: 'Fechas críticas', icon: 'calendar-clock-outline' },
    { value: 'documentos', label: 'Documentos', icon: 'paperclip', count: props.documents?.length },
    { value: 'analisis', label: 'Análisis del pliego', icon: 'file-search-outline', count: props.analyses.length || undefined },
    { value: 'oportunidad', label: 'Oportunidad y cotizaciones', icon: 'briefcase-outline', count: props.quotes.length || undefined },
]);

const general = computed(() => [
    { key: 'status', label: 'Estado', value: props.tender.status },
    { label: 'Expediente', value: props.tender.reference },
    { label: 'Objeto', value: props.tender.title, wide: true },
    { label: 'Entidad', value: props.tender.institution?.name },
    { label: 'Unidad de compra', value: props.tender.purchasing_unit },
    { label: 'Nombre del proceso', value: props.tender.process_name },
    { label: 'Modalidad', value: props.tender.purchase_type },
    { label: 'Etapa en HonduCompras', value: props.tender.honducompras_stage },
    { label: 'Tipo de adquisición', value: props.tender.acquisition_type },
    { label: 'Tipo de fuente', value: props.tender.funding_source_type },
    { label: 'Fuente', value: props.tender.funding_source },
    { label: 'Monto estimado', value: Number(props.tender.estimated_amount) > 0 ? money(props.tender.estimated_amount) : null },
    { label: 'Valor de pliegos', value: props.tender.bid_document_fee !== null ? money(props.tender.bid_document_fee) : null },
    { label: 'Vigencia de la oferta', value: props.tender.offer_validity_days ? `${props.tender.offer_validity_days} días` : null },
    { label: 'Lugar de recepción de ofertas', value: props.tender.offer_reception_place, wide: true },
    { label: 'Plazo de entrega', value: props.tender.delivery_terms },
    { label: 'Forma de pago', value: props.tender.payment_terms },
    { label: 'Contacto', value: [props.tender.contact_name, props.tender.contact_phone, props.tender.contact_email].filter(Boolean).join(' · ') || null, wide: true },
    { key: 'source_url', label: 'Enlace en HonduCompras', value: props.tender.source_url, wide: true },
    { label: 'Documentos exigidos', value: (props.tender.documents ?? []).join('; ') || null, wide: true },
    { label: 'Requisitos y criterios', value: props.tender.requirements, wide: true },
    { label: 'Observaciones', value: props.tender.observations, wide: true },
    { label: 'Responsable', value: props.tender.owner },
    { label: 'Última actualización', value: props.tender.updated_at ? dateTime(props.tender.updated_at) : null },
]);

const timeline = computed(() =>
    CRITICAL_DATES.map((item) => {
        const value = props.tender[item.key];
        const badge = value && item.key !== 'started_at' ? deadline(value, { closed: closed.value }) : null;
        return {
            id: item.key,
            title: item.label,
            icon: item.icon,
            value,
            badge,
            status: value ? (badge?.status === 'error' ? 'error' : badge?.status === 'warning' ? 'warning' : badge?.status === 'neutral' && badge.label.startsWith('Venció') ? undefined : 'info') : undefined,
            meta: value ? dateTime(value, 'medium') : 'Sin fecha: complétala con «Editar» o analizando el pliego.',
        };
    }),
);

const menu = computed(() => [
    ...(props.tender.source_url ? [{ label: 'Abrir en HonduCompras', icon: 'open-in-new', href: props.tender.source_url, external: true }] : []),
    ...(props.can.delete ? [{ label: 'Eliminar licitación', icon: 'trash-can-outline', danger: true, separator: Boolean(props.tender.source_url), onSelect: remove }] : []),
]);

function remove() {
    confirm({
        verb: 'Eliminar',
        object: `la licitación ${props.tender.reference}`,
        noun: 'licitación',
        message: 'Se borran sus análisis y su matriz documental. La oportunidad y las cotizaciones se conservan sin enlace. No se puede deshacer.',
        action: () => visit('delete', `/licitaciones/${props.tender.id}`),
    });
}

function setTab(value) {
    tab.value = value;
    // Deja la pestaña en la URL (recargar o compartir abre la misma) sin una visita al servidor.
    const url = `/licitaciones/${props.tender.id}?tab=${value}`;
    window.history.replaceState({ ...window.history.state, url }, '', url);
}

function isPreviewable(document) {
    return /pdf|image\//.test(document.mime ?? '');
}
</script>

<template>
    <AppLayout :title="tender.reference" back-href="/licitaciones" :breadcrumbs="[{ label: 'Licitaciones', href: '/licitaciones' }, { label: tender.reference }]">
        <section :class="card" class="p-5">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <StatusBadge :status="tenderTone(tender.status)" :label="tender.status" />
                        <StatusBadge v-if="tender.purchase_type" status="neutral" :label="tender.purchase_type" />
                        <span v-if="capture" class="text-xs text-cap-text-soft">Importada de una captura de HonduCompras</span>
                    </div>
                    <h2 class="mt-2 font-display text-lg font-semibold text-gray-900 dark:text-gray-100">{{ tender.title }}</h2>
                    <p class="mt-1 text-sm text-cap-text-soft">{{ tender.institution?.name ?? 'Entidad sin asignar' }}<span v-if="tender.purchasing_unit"> · {{ tender.purchasing_unit }}</span></p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <AppButton v-if="can.update" icon="pencil-outline" @click="editOpen = true">Editar</AppButton>
                    <DropdownMenu v-if="menu.length" label="Más acciones" :items="menu" />
                </div>
            </div>
            <div v-if="next" class="mt-4 flex flex-wrap items-center gap-2 rounded-cap-control bg-cap-card-2 p-3 text-sm">
                <i class="mdi text-xl leading-none text-cap-text-soft" :class="`mdi-${next.icon}`" aria-hidden="true" />
                <span class="text-gray-900 dark:text-gray-100">Próximo plazo: <strong>{{ next.label }}</strong>, {{ dateTime(next.value, 'medium') }}</span>
                <StatusBadge v-bind="next.badge" />
            </div>
        </section>

        <Tabs :model-value="tab" :tabs="tabs" label="Secciones de la licitación" @update:model-value="setTab">
            <template #datos>
                <section :class="card" class="p-5">
                    <DescriptionList :items="general">
                        <template #status="{ item }"><StatusBadge :status="tenderTone(item.value)" :label="item.value" /></template>
                        <template #source_url="{ item }">
                            <a v-if="item.value" :href="item.value" target="_blank" rel="noopener" class="inline-flex items-center gap-1 break-all underline decoration-gray-400 underline-offset-2 hover:decoration-current">{{ item.value }}<i class="mdi mdi-open-in-new text-base" aria-hidden="true" /></a>
                            <span v-else>—</span>
                        </template>
                    </DescriptionList>
                </section>
            </template>

            <template #fechas>
                <section :class="card" class="p-5">
                    <Timeline :items="timeline">
                        <template #item="{ item }">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ item.title }}</p>
                                <StatusBadge v-if="item.badge" v-bind="item.badge" />
                            </div>
                            <p class="text-xs text-cap-text-soft">{{ item.meta }}</p>
                        </template>
                    </Timeline>
                    <p class="mt-6 text-xs text-cap-text-soft">Los plazos se pasan al calendario con sus recordatorios al guardar la licitación.</p>
                </section>
            </template>

            <template #documentos>
                <section :class="card" class="p-5">
                    <Deferred data="documents">
                        <template #fallback><CapSkeleton variant="line" :rows="3" label="Cargando documentos" /></template>
                        <EmptyState v-if="!documents?.length" compact icon="paperclip" title="Sin documentos" description="La captura importada y los pliegos analizados aparecen aquí." />
                        <ul v-stagger v-else class="divide-y divide-cap-divider">
                            <li v-for="document in documents" :key="document.id" class="flex items-center gap-3 py-3">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-cap-control bg-cap-card-2 text-cap-text-soft" aria-hidden="true">
                                    <i class="mdi text-xl leading-none" :class="document.mime === 'application/pdf' ? 'mdi-file-pdf-box' : 'mdi-file-image-outline'" />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium text-gray-900 dark:text-gray-100" :title="document.name">{{ document.name }}</span>
                                    <span class="block truncate text-xs text-cap-text-soft">{{ document.category ?? 'Documento' }} · {{ fileSize(document.size) }} · {{ date(document.uploadedAt, 'medium') }}<span v-if="document.uploadedBy"> · {{ document.uploadedBy }}</span></span>
                                </span>
                                <IconButton v-if="isPreviewable(document)" icon="eye-outline" :label="`Ver ${document.name}`" @click="preview = document" />
                                <IconButton icon="download-outline" :label="`Descargar ${document.name}`" :href="document.downloadUrl" external />
                            </li>
                        </ul>
                    </Deferred>
                </section>
            </template>

            <template #analisis>
                <BidAnalysisPanel :tender="tender" :analyses="analyses" :ai="ai" :max-pdf-mb="maxPdfMb" :can="can" />
            </template>

            <template #oportunidad>
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <section :class="card" class="p-5">
                        <h3 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Oportunidad en el pipeline</h3>
                        <div v-if="opportunity" class="mt-3 flex items-center justify-between gap-3 rounded-cap-control bg-cap-card-2 p-3">
                            <span class="min-w-0">
                                <Link :href="`/pipeline/${opportunity.id}`" :class="tableLink" class="block truncate">{{ opportunity.title }}</Link>
                                <span class="text-xs text-cap-text-soft">{{ money(opportunity.amount) }} · {{ opportunity.probability }} %</span>
                            </span>
                            <StatusBadge :value="opportunity.stage_label" :label="opportunity.stage_label" />
                        </div>
                        <EmptyState v-else compact icon="briefcase-outline" title="Sin oportunidad" description="Se crea al importar la captura, o desde el Pipeline." />

                        <h3 class="mt-6 font-display text-base font-semibold text-gray-900 dark:text-gray-100">Matriz documental</h3>
                        <ul v-stagger v-if="matrices.length" class="mt-3 flex flex-col gap-2">
                            <li v-for="matrix in matrices" :key="matrix.id" class="flex items-center justify-between gap-3 rounded-cap-control bg-cap-card-2 p-3 text-sm">
                                <Link :href="`/matriz-cumplimiento/${matrix.id}`" :class="tableLink">{{ matrix.name }}</Link>
                                <span class="tabular-nums text-cap-text-soft">{{ matrix.completed_count }} de {{ matrix.items_count }} listos</span>
                            </li>
                        </ul>
                        <p v-else class="mt-2 text-sm text-cap-text-soft">Se crea desde la revisión del pliego.</p>
                    </section>

                    <section :class="card" class="p-5">
                        <h3 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Cotizaciones vinculadas</h3>
                        <ul v-stagger v-if="quotes.length" class="mt-3 divide-y divide-cap-divider">
                            <li v-for="quote in quotes" :key="quote.id" class="flex items-center justify-between gap-3 py-3 text-sm">
                                <span class="min-w-0">
                                    <Link :href="`/cotizaciones?search=${encodeURIComponent(quote.number)}`" :class="tableLink">{{ quote.number }}</Link>
                                    <span class="block text-xs text-cap-text-soft">{{ date(quote.quoted_at, 'medium') }}</span>
                                </span>
                                <span class="flex items-center gap-3">
                                    <span class="tabular-nums">{{ money(quote.total) }}</span>
                                    <StatusBadge :value="quote.status" :label="quote.status" />
                                </span>
                            </li>
                        </ul>
                        <EmptyState v-else compact icon="file-document-outline" title="Sin cotizaciones" description="Crea una cotización borrador desde la revisión del pliego o en Cotizaciones." />
                    </section>
                </div>
            </template>
        </Tabs>

        <CapAlert v-if="!can.update" status="info" title="Solo lectura" detail="Tu rol permite ver esta licitación, pero no modificarla." />

        <TenderFormModal v-model:open="editOpen" :tender="tender" :statuses="statuses" :modalities="modalities" />

        <BaseModal :open="Boolean(preview)" size="xl" flush :title="preview?.name ?? ''" @update:open="preview = $event ? preview : null">
            <PdfViewer v-if="preview" :src="preview.previewUrl" :name="preview.name" :download-url="preview.downloadUrl" height="h-[75vh]" />
        </BaseModal>
    </AppLayout>
</template>
