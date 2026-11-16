<script setup>
// Ficha de la licitación: cabecera con el objeto (normalizado), la institución, el expediente
// copiable, el CONTADOR del plazo crítico y el botón del SIGUIENTE PASO; el stepper del proceso y las
// secciones en pestañas desplazables (B-25). Documentos, análisis, matriz e historial llegan diferidos.
import { Deferred, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import CapAlert from '../../../design/vue/CapAlert.vue';
import CapSkeleton from '../../../design/vue/CapSkeleton.vue';
import { useToasts } from '../../../design/vue/composables/useToasts.js';
import AppButton from '../../Components/AppButton.vue';
import Avatar from '../../Components/Avatar.vue';
import BaseModal from '../../Components/BaseModal.vue';
import DropdownMenu from '../../Components/DropdownMenu.vue';
import EmptyState from '../../Components/EmptyState.vue';
import IconButton from '../../Components/IconButton.vue';
import PdfViewer from '../../Components/PdfViewer.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import Tabs from '../../Components/Tabs.vue';
import { useConfirm } from '../../composables/useConfirm.js';
import AppLayout from '../../Layouts/AppLayout.vue';
import { date, dateTime, fileSize, money } from '../../lib/format.js';
import { visit } from '../../lib/http.js';
import { card, tableLink, textLink } from '../../lib/ui.js';
import BidAnalysisPanel from '../../Modules/Tenders/BidAnalysisPanel.vue';
import TenderFormModal from '../../Modules/Tenders/TenderFormModal.vue';
import TenderStepper from '../../Modules/Tenders/TenderStepper.vue';
import { countdown, CRITICAL_DATES, DEADLINE_ICONS, instantOf, stageBadge } from '../../Modules/Tenders/tenders.js';

const props = defineProps({
    tender: { type: Object, required: true },
    flow: { type: Object, default: () => ({ steps: [], next: null }) },
    opportunity: { type: Object, default: null },
    quotes: { type: Array, default: () => [] },
    analysisState: { type: Object, default: null },
    capture: { type: Boolean, default: false },
    documents: { type: Array, default: undefined },
    analyses: { type: Array, default: undefined },
    matrices: { type: Array, default: undefined },
    history: { type: Array, default: undefined },
    statuses: { type: Array, default: () => [] },
    modalities: { type: Array, default: () => [] },
    ai: { type: Object, default: () => ({}) },
    maxPdfMb: { type: Number, default: 40 },
    maxPdfPages: { type: Number, default: 300 },
    can: { type: Object, default: () => ({}) },
});

const page = usePage();
const confirm = useConfirm();
const toasts = useToasts();
const query = computed(() => new URLSearchParams(page.url.split('?')[1] ?? ''));
const TABS = ['resumen', 'documentos', 'analisis', 'oportunidad', 'historial'];
const LEGACY = { datos: 'resumen', fechas: 'resumen' };
const initialTab = (value) => (TABS.includes(value) ? value : LEGACY[value] ?? 'resumen');
const tab = ref(initialTab(query.value.get('tab')));
const editOpen = ref(props.can.update && query.value.get('editar') === '1');
const preview = ref(null);
const uploadSignal = ref(0);
const expanded = ref(false);
const showEmpty = ref(false);
const posting = ref(false);
const now = ref(Date.now());

watch(() => query.value.get('tab'), (value) => {
    if (value) tab.value = initialTab(value);
});

let clock = null;
onMounted(() => (clock = setInterval(() => (now.value = Date.now()), 30_000)));
onBeforeUnmount(() => clearInterval(clock));

// ── Cabecera ────────────────────────────────────────────────────────────────────────────────────
const stage = computed(() => stageBadge(props.tender.stage));
const next = computed(() => props.tender.next_deadline);
const left = computed(() => (next.value ? countdown(next.value.at, now.value) : null));
const titleLong = computed(() => (props.tender.display_title ?? '').length > 140);

const TILE = {
    error: 'border-cap-status-error-tint-border bg-cap-status-error-tint text-cap-status-error-ink',
    warning: 'border-cap-status-warning/30 bg-cap-status-warning-tint dark:border-cap-status-dark-warning/30 text-cap-status-warning-ink',
    neutral: 'border-cap-divider bg-cap-card-2 text-gray-900 dark:text-gray-100',
};

/** Días, horas y minutos que faltan (para el contador grande). */
const remaining = computed(() => {
    if (!next.value) return null;
    const diff = instantOf(next.value.at) - now.value;
    if (diff <= 0) return null;
    const minutes = Math.floor(diff / 60_000);
    return { days: Math.floor(minutes / 1440), hours: Math.floor((minutes % 1440) / 60), minutes: minutes % 60 };
});

const closedText = computed(() => {
    const key = props.tender.stage?.key;
    if (props.tender.stage?.awaiting_result) return { title: 'Esperando resultado', detail: 'La apertura ya pasó. Registra si se adjudicó o no.' };
    if (key === 'vencida') return { title: 'Vencida sin oferta', detail: props.tender.presentation_deadline ? `La recepción de ofertas cerró el ${dateTime(props.tender.presentation_deadline, 'medium')}.` : 'La recepción de ofertas ya pasó.' };
    if (key === 'adjudicada') return { title: 'Adjudicada', detail: props.tender.result || 'Proceso ganado.' };
    if (key === 'no_adjudicada') return { title: 'No adjudicada', detail: props.tender.result || 'El proceso se adjudicó a otro oferente.' };
    if (key === 'desierta') return { title: props.tender.status, detail: 'El proceso se cerró sin adjudicar.' };
    return { title: 'Sin plazos por delante', detail: 'Completa las fechas con «Editar» o analizando el pliego.' };
});

async function copyReference() {
    try {
        await navigator.clipboard.writeText(props.tender.reference);
        toasts.show({ status: 'ok', title: 'Expediente copiado', detail: props.tender.reference });
    } catch {
        toasts.show({ status: 'warning', title: 'No se pudo copiar', detail: 'Selecciona el expediente y cópialo a mano.' });
    }
}

function runNext() {
    const action = props.flow.next;
    if (!action || action.busy) {
        if (action?.tab) setTab(action.tab);
        return;
    }
    if (action.action === 'upload') {
        setTab('analisis');
        uploadSignal.value++;
    } else if (action.action === 'post') {
        posting.value = true;
        router.post(action.href, {}, { preserveScroll: true, onFinish: () => (posting.value = false) });
    } else if (action.action === 'tab') {
        setTab(action.tab);
    } else {
        router.visit(action.href);
    }
}

const menu = computed(() => [
    ...(props.tender.source_url ? [{ label: 'Abrir en HonduCompras', icon: 'open-in-new', href: props.tender.source_url, external: true }] : []),
    { label: 'Copiar expediente', icon: 'content-copy', onSelect: copyReference },
    ...(props.can.delete ? [{ label: 'Eliminar licitación', icon: 'trash-can-outline', danger: true, separator: true, onSelect: remove }] : []),
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

const tabs = computed(() => [
    { value: 'resumen', label: 'Resumen', icon: 'card-text-outline' },
    { value: 'documentos', label: 'Documentos', icon: 'paperclip', count: props.documents?.length || undefined },
    { value: 'analisis', label: 'Análisis del pliego', icon: 'file-search-outline', count: props.analyses?.length || undefined },
    { value: 'oportunidad', label: 'Oportunidad y cotizaciones', icon: 'briefcase-outline', count: props.quotes.length || undefined },
    { value: 'historial', label: 'Historial', icon: 'history' },
]);

// ── Resumen ─────────────────────────────────────────────────────────────────────────────────────
const t = computed(() => props.tender);
const blank = (value) => value === null || value === undefined || value === '' || (Array.isArray(value) && !value.length);

const processFields = computed(() => [
    { label: 'Nombre del proceso', value: t.value.process_name },
    { label: 'Modalidad', value: t.value.purchase_type },
    { label: 'Tipo de adquisición', value: t.value.acquisition_type },
    { label: 'Etapa en HonduCompras', value: t.value.honducompras_stage },
    { label: 'Unidad de compra', value: t.value.display_unit },
    { label: 'Tipo de fuente', value: t.value.funding_source_type },
    { label: 'Fuente', value: t.value.funding_source },
    { label: 'Estado guardado', value: t.value.status },
]);
const conditionFields = computed(() => [
    { label: 'Monto estimado', value: Number(t.value.estimated_amount) > 0 ? money(t.value.estimated_amount) : null },
    { label: 'Valor de pliegos', value: t.value.bid_document_fee !== null && t.value.bid_document_fee !== undefined ? money(t.value.bid_document_fee) : null },
    { label: 'Vigencia de la oferta', value: t.value.offer_validity_days ? `${t.value.offer_validity_days} días` : null },
    { label: 'Plazo de entrega', value: t.value.delivery_terms },
    { label: 'Forma de pago', value: t.value.payment_terms },
    { label: 'Lugar de recepción de ofertas', value: t.value.offer_reception_place, wide: true },
    { label: 'Documentos exigidos', value: (t.value.documents ?? []).join('; ') || null, wide: true },
    { label: 'Requisitos y criterios', value: t.value.requirements, wide: true },
    { label: 'Observaciones', value: t.value.observations, wide: true },
]);
const visible = (list) => (showEmpty.value ? list : list.filter((field) => !blank(field.value)));
const hiddenCount = computed(() => [...processFields.value, ...conditionFields.value].filter((field) => blank(field.value)).length);

/** Fechas en una línea de tiempo con la marca de «hoy» entre lo pasado y lo que viene. */
const timeline = computed(() => {
    const live = ['por_analizar', 'en_preparacion', 'presentada'].includes(t.value.stage?.key);
    const items = CRITICAL_DATES.map((item) => ({ ...item, value: t.value[item.key] }));
    const withDates = items.filter((item) => item.value).sort((a, b) => instantOf(a.value) - instantOf(b.value));
    const without = items.filter((item) => !item.value);
    const rows = [];
    let marked = false;
    for (const item of withDates) {
        const past = instantOf(item.value) < now.value;
        if (!past && !marked) {
            rows.push({ key: 'hoy', today: true });
            marked = true;
        }
        rows.push({ ...item, past, badge: item.key !== 'started_at' && live ? countdown(item.value, now.value) : null, next: next.value?.key === item.key });
    }
    if (!marked && withDates.length) rows.push({ key: 'hoy', today: true });
    return [...rows, ...without.map((item) => ({ ...item, missing: true }))];
});

// Contacto: tel: sin la extensión en el número (va como `;ext=`), mailto: si el correo es válido.
const phoneHref = computed(() => {
    const raw = t.value.contact_phone ?? '';
    const [main, ext] = raw.split(/\s*(?:ext\.?|extensi[oó]n)\s*/i);
    const digits = (main ?? '').replace(/[^\d+]/g, '');
    if (digits.length < 7) return null;
    const number = digits.startsWith('+') ? digits : digits.length === 8 ? `+504${digits}` : digits;
    return `tel:${number}${ext ? `;ext=${ext.replace(/\D/g, '')}` : ''}`;
});
const emailValid = computed(() => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(t.value.contact_email ?? ''));

function isPreviewable(document) {
    return /pdf|image\//.test(document.mime ?? '');
}

const HISTORY_ICONS = {
    'licitacion.creada': 'plus-circle-outline',
    'licitacion.importada': 'text-recognition',
    'licitacion.actualizada': 'pencil-outline',
    'licitacion.actualizada_por_captura': 'text-recognition',
    'licitacion.etapa': 'swap-horizontal',
    'licitacion.vencida': 'timer-off-outline',
    'licitacion.pliego_subido': 'file-upload-outline',
    'licitacion.pliego_confirmado': 'clipboard-check-outline',
    'licitacion.recomendacion_ia': 'creation-outline',
};
</script>

<template>
    <AppLayout :title="tender.reference" back-href="/licitaciones" :breadcrumbs="[{ label: 'Licitaciones', href: '/licitaciones' }, { label: tender.reference }]">
        <!-- Cabecera -->
        <section :class="card" class="overflow-hidden" data-tender-header>
            <div class="grid grid-cols-1 gap-5 p-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <StatusBadge v-bind="stage" data-stage-badge />
                        <StatusBadge v-if="tender.purchase_type" status="neutral" :label="tender.purchase_type" />
                        <span v-if="capture" class="inline-flex items-center gap-1 text-xs text-cap-text-soft"><i class="mdi mdi-text-recognition text-base leading-none" aria-hidden="true" />Importada de HonduCompras</span>
                        <span class="ml-auto flex items-center gap-1">
                            <AppButton v-if="can.update" icon="pencil-outline" @click="editOpen = true">Editar</AppButton>
                            <DropdownMenu label="Más acciones" :items="menu" />
                        </span>
                    </div>

                    <button type="button" class="group mt-3 inline-flex max-w-full items-center gap-1.5 rounded-md text-sm font-semibold tabular-nums text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome dark:text-gray-100 dark:focus-visible:ring-gray-100" :aria-label="`Copiar el expediente ${tender.reference}`" data-copy-reference @click="copyReference">
                        <span class="truncate">{{ tender.reference }}</span>
                        <i class="mdi mdi-content-copy text-base leading-none text-cap-text-soft group-hover:text-gray-900 dark:group-hover:text-gray-100" aria-hidden="true" />
                    </button>
                    <h2 class="mt-1 font-display text-lg font-semibold leading-snug text-gray-900 dark:text-gray-100" :class="titleLong && !expanded ? 'line-clamp-3' : ''" :title="tender.title" data-tender-title>{{ tender.display_title }}</h2>
                    <button v-if="titleLong" type="button" :class="textLink" class="mt-1 text-xs text-cap-text-soft" @click="expanded = !expanded">{{ expanded ? 'Ver menos' : 'Ver todo el objeto' }}</button>
                    <p class="mt-2 text-sm text-cap-text-soft">
                        <i class="mdi mdi-bank-outline mr-1 align-[-2px] text-base leading-none" aria-hidden="true" />{{ tender.institution?.name ?? 'Institución sin asignar' }}<span v-if="tender.display_unit"> · {{ tender.display_unit }}</span>
                    </p>

                    <dl class="mt-4 flex flex-wrap gap-x-8 gap-y-3">
                        <div>
                            <dt class="text-xs font-medium text-cap-text-soft">Monto estimado</dt>
                            <dd class="mt-0.5 text-sm font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ Number(tender.estimated_amount) > 0 ? money(tender.estimated_amount) : '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-cap-text-soft">Responsable</dt>
                            <dd class="mt-0.5 flex items-center gap-2 text-sm text-gray-900 dark:text-gray-100"><Avatar v-if="tender.owner" :name="tender.owner" size="sm" />{{ tender.owner ?? '—' }}</dd>
                        </div>
                        <div v-if="tender.presentation_deadline">
                            <dt class="text-xs font-medium text-cap-text-soft">Recepción de ofertas</dt>
                            <dd class="mt-0.5 text-sm tabular-nums text-gray-900 dark:text-gray-100">{{ dateTime(tender.presentation_deadline, 'medium') }}</dd>
                        </div>
                    </dl>
                </div>

                <!-- Plazo crítico y siguiente paso -->
                <div class="flex flex-col gap-3">
                    <div v-if="left" class="rounded-cap-control border p-4" :class="TILE[left.status] ?? TILE.neutral" role="timer" :aria-label="`${next.label}: ${left.label}`" data-critical-deadline :data-urgency="left.status">
                        <p class="flex items-center gap-1.5 text-xs font-semibold"><i class="mdi text-base leading-none" :class="`mdi-${DEADLINE_ICONS[next.key]}`" aria-hidden="true" />{{ next.label }}</p>
                        <p v-if="remaining" class="mt-1 flex items-baseline gap-3 font-display tabular-nums">
                            <span v-if="remaining.days"><span class="text-3xl font-semibold">{{ remaining.days }}</span><span class="ml-0.5 text-sm"> {{ remaining.days === 1 ? 'día' : 'días' }}</span></span>
                            <span><span class="text-3xl font-semibold">{{ remaining.hours }}</span><span class="ml-0.5 text-sm"> h</span></span>
                            <span v-if="!remaining.days"><span class="text-3xl font-semibold">{{ remaining.minutes }}</span><span class="ml-0.5 text-sm"> min</span></span>
                        </p>
                        <p class="mt-1 text-xs opacity-90">{{ dateTime(next.at, 'medium') }} · {{ left.label }}</p>
                    </div>
                    <div v-else class="rounded-cap-control border border-cap-divider bg-cap-card-2 p-4" data-critical-deadline data-urgency="none">
                        <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ closedText.title }}</p>
                        <p class="mt-1 text-xs text-cap-text-soft">{{ closedText.detail }}</p>
                    </div>

                    <div v-if="flow.next" class="flex flex-col gap-1.5" data-next-step>
                        <AppButton variant="primary" :icon="flow.next.icon" :loading="posting || flow.next.busy" class="w-full justify-center" @click="runNext">{{ flow.next.label }}</AppButton>
                        <p class="text-xs text-cap-text-soft">{{ flow.next.hint }}</p>
                    </div>
                </div>
            </div>

            <div class="border-t border-cap-divider px-3 py-4 md:px-5">
                <TenderStepper :steps="flow.steps" />
            </div>
        </section>

        <CapAlert v-if="!can.update" status="info" title="Solo lectura" detail="Tu rol permite ver esta licitación, pero no modificarla." />

        <Tabs :model-value="tab" :tabs="tabs" label="Secciones de la licitación" @update:model-value="setTab">
            <template #resumen>
                <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
                    <section :class="card" class="p-5" aria-labelledby="sec-fechas" data-card="fechas">
                        <h3 id="sec-fechas" class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Fechas críticas</h3>
                        <ol class="mt-4 flex flex-col">
                            <li v-for="item in timeline" :key="item.key" class="relative flex gap-3 pb-4 last:pb-0">
                                <template v-if="item.today">
                                    <span class="flex w-8 shrink-0 justify-center" aria-hidden="true"><span class="mt-1.5 h-2 w-2 rounded-full bg-cap-brand dark:bg-cap-brand-soft" /></span>
                                    <p class="flex-1 border-t border-dashed border-cap-brand pt-0.5 text-xs font-semibold text-cap-brand dark:border-cap-brand-soft dark:text-cap-brand-soft">Hoy</p>
                                </template>
                                <template v-else>
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full" :class="item.missing ? 'border border-dashed border-cap-divider text-cap-text-soft' : item.past ? 'bg-cap-card-2 text-cap-text-soft' : item.next ? 'bg-cap-chrome text-white dark:bg-gray-100 dark:text-gray-900' : 'bg-cap-red-50 text-cap-red-800 dark:bg-cap-red-600/15 dark:text-cap-brand-soft'" aria-hidden="true"><i class="mdi text-base leading-none" :class="`mdi-${item.icon}`" /></span>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <p class="text-sm font-medium" :class="item.past || item.missing ? 'text-cap-text-soft' : 'text-gray-900 dark:text-gray-100'">{{ item.label }}</p>
                                            <StatusBadge v-if="item.badge && !item.past" :status="item.badge.status" :label="item.badge.label" />
                                            <span v-else-if="item.past" class="text-xs text-cap-text-soft">pasó</span>
                                        </div>
                                        <p class="text-xs tabular-nums text-cap-text-soft">{{ item.missing ? 'Sin fecha: complétala con «Editar» o analizando el pliego.' : dateTime(item.value, 'medium') }}</p>
                                    </div>
                                </template>
                            </li>
                        </ol>
                        <p class="mt-4 text-xs text-cap-text-soft">Los plazos se pasan al calendario con sus recordatorios al guardar la licitación.</p>
                    </section>

                    <section :class="card" class="p-5" aria-labelledby="sec-contacto" data-card="contacto">
                        <h3 id="sec-contacto" class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Contacto</h3>
                        <div v-if="tender.contact_name || tender.contact_phone || tender.contact_email" class="mt-4 flex flex-col gap-3">
                            <p v-if="tender.contact_name" class="flex items-center gap-3 text-sm text-gray-900 dark:text-gray-100"><Avatar :name="tender.display_contact ?? tender.contact_name" size="sm" />{{ tender.display_contact }}</p>
                            <a v-if="tender.contact_phone && phoneHref" :href="phoneHref" class="flex min-h-11 items-center gap-3 rounded-cap-control bg-cap-card-2 px-3 text-sm text-gray-900 transition-colors duration-cap-fast ease-cap hover:bg-cap-divider dark:text-gray-100" data-contact-phone>
                                <i class="mdi mdi-phone-outline text-xl leading-none text-cap-text-soft" aria-hidden="true" /><span class="tabular-nums">{{ tender.contact_phone }}</span><span class="ml-auto text-xs text-cap-text-soft">Llamar</span>
                            </a>
                            <p v-else-if="tender.contact_phone" class="text-sm tabular-nums">{{ tender.contact_phone }}</p>
                            <a v-if="tender.contact_email && emailValid" :href="`mailto:${tender.contact_email}?subject=${encodeURIComponent(`Consulta sobre ${tender.reference}`)}`" class="flex min-h-11 items-center gap-3 rounded-cap-control bg-cap-card-2 px-3 text-sm text-gray-900 transition-colors duration-cap-fast ease-cap hover:bg-cap-divider dark:text-gray-100" data-contact-email>
                                <i class="mdi mdi-email-outline text-xl leading-none text-cap-text-soft" aria-hidden="true" /><span class="min-w-0 truncate">{{ tender.contact_email }}</span><span class="ml-auto shrink-0 text-xs text-cap-text-soft">Escribir</span>
                            </a>
                            <p v-else-if="tender.contact_email" class="text-sm">{{ tender.contact_email }} <span class="text-xs text-cap-status-warning-ink">(revisa el correo: no es válido)</span></p>
                        </div>
                        <EmptyState v-else compact icon="account-outline" title="Sin contacto" description="Se completa al importar la captura o con «Editar»." class="mt-3" />
                        <a v-if="tender.source_url" :href="tender.source_url" target="_blank" rel="noopener" :class="textLink" class="mt-4 inline-flex items-center gap-1 text-sm">Ver en HonduCompras<i class="mdi mdi-open-in-new text-base" aria-hidden="true" /></a>
                    </section>

                    <section :class="card" class="p-5" aria-labelledby="sec-proceso" data-card="proceso">
                        <h3 id="sec-proceso" class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Proceso</h3>
                        <dl class="mt-4 grid gap-x-6 gap-y-4 sm:grid-cols-2">
                            <div v-for="field in visible(processFields)" :key="field.label">
                                <dt class="text-xs font-medium text-cap-text-soft">{{ field.label }}</dt>
                                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ blank(field.value) ? '—' : field.value }}</dd>
                            </div>
                        </dl>
                    </section>

                    <section :class="card" class="p-5" aria-labelledby="sec-condiciones" data-card="condiciones">
                        <h3 id="sec-condiciones" class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Condiciones</h3>
                        <dl v-if="visible(conditionFields).length" class="mt-4 grid gap-x-6 gap-y-4 sm:grid-cols-2">
                            <div v-for="field in visible(conditionFields)" :key="field.label" :class="field.wide ? 'sm:col-span-2' : ''">
                                <dt class="text-xs font-medium text-cap-text-soft">{{ field.label }}</dt>
                                <dd class="mt-1 whitespace-pre-line text-sm text-gray-900 dark:text-gray-100">{{ blank(field.value) ? '—' : field.value }}</dd>
                            </div>
                        </dl>
                        <p v-else class="mt-3 text-sm text-cap-text-soft">Aún no hay condiciones. El análisis del pliego las completa.</p>
                    </section>
                </div>
                <button v-if="hiddenCount" type="button" :class="textLink" class="mt-3 text-sm text-cap-text-soft" @click="showEmpty = !showEmpty">{{ showEmpty ? 'Ocultar los campos vacíos' : `Mostrar ${hiddenCount} campos vacíos` }}</button>
            </template>

            <template #documentos>
                <section :class="card" class="p-5">
                    <Deferred data="documents">
                        <template #fallback><CapSkeleton variant="line" :rows="3" label="Cargando documentos" /></template>
                        <EmptyState v-if="!documents?.length" compact icon="paperclip" title="Sin documentos" description="La captura importada y los pliegos analizados aparecen aquí." />
                        <ul v-else v-stagger class="divide-y divide-cap-divider">
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
                <Deferred data="analyses">
                    <template #fallback><section :class="card" class="p-5"><CapSkeleton variant="line" :rows="4" label="Cargando el análisis" /></section></template>
                    <BidAnalysisPanel :tender="tender" :analyses="analyses ?? []" :ai="ai" :max-pdf-mb="maxPdfMb" :max-pdf-pages="maxPdfPages" :can="can" :upload-signal="uploadSignal" />
                </Deferred>
            </template>

            <template #oportunidad>
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <section :class="card" class="p-5">
                        <h3 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Oportunidad en el pipeline</h3>
                        <div v-if="opportunity" class="mt-3 flex items-center justify-between gap-3 rounded-cap-control bg-cap-card-2 p-3">
                            <span class="min-w-0">
                                <Link :href="`/pipeline/${opportunity.id}`" :class="tableLink" class="block truncate">{{ opportunity.title }}</Link>
                                <span class="text-xs text-cap-text-soft">{{ money(opportunity.amount) }} · {{ opportunity.probability }} % · {{ opportunity.participants ? `${opportunity.participants} participantes` : 'Sin participantes' }}</span>
                            </span>
                            <StatusBadge :value="opportunity.stage_label" :label="opportunity.stage_label" />
                        </div>
                        <EmptyState v-else compact icon="briefcase-outline" title="Sin oportunidad" description="Se crea al importar la captura o desde aquí. Una por licitación.">
                            <template v-if="can.update && can.createOpportunity" #action><AppButton icon="briefcase-plus-outline" @click="router.post(`/licitaciones/${tender.id}/oportunidad`)">Crear oportunidad</AppButton></template>
                        </EmptyState>

                        <h3 class="mt-6 font-display text-base font-semibold text-gray-900 dark:text-gray-100">Matriz documental</h3>
                        <Deferred data="matrices">
                            <template #fallback><CapSkeleton variant="line" :rows="1" label="Cargando la matriz" class="mt-3" /></template>
                            <ul v-if="matrices?.length" class="mt-3 flex flex-col gap-2">
                                <li v-for="matrix in matrices" :key="matrix.id" class="rounded-cap-control bg-cap-card-2 p-3 text-sm">
                                    <div class="flex items-center justify-between gap-3">
                                        <Link :href="`/matriz-cumplimiento/${matrix.id}`" :class="tableLink">{{ matrix.name }}</Link>
                                        <span class="tabular-nums text-cap-text-soft">{{ matrix.completed_count }} de {{ matrix.items_count }}</span>
                                    </div>
                                    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-cap-divider" role="progressbar" :aria-valuenow="matrix.completed_count" aria-valuemin="0" :aria-valuemax="matrix.items_count" :aria-label="`Avance de ${matrix.name}`">
                                        <div class="h-full rounded-full bg-cap-status-ok dark:bg-cap-status-dark-ok" :style="{ width: `${matrix.items_count ? (matrix.completed_count / matrix.items_count) * 100 : 0}%` }" />
                                    </div>
                                </li>
                            </ul>
                            <p v-else class="mt-2 text-sm text-cap-text-soft">Se crea desde la revisión del pliego, con los documentos y garantías exigidos.</p>
                        </Deferred>
                    </section>

                    <section :class="card" class="p-5">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Cotizaciones vinculadas</h3>
                            <AppButton v-if="quotes.length" :href="`/cotizaciones?tender_id=${tender.id}`" icon="open-in-app">Ver en Cotizaciones</AppButton>
                        </div>
                        <ul v-if="quotes.length" class="mt-3 divide-y divide-cap-divider">
                            <li v-for="quote in quotes" :key="quote.id" class="flex items-center justify-between gap-3 py-3 text-sm">
                                <span class="min-w-0">
                                    <Link :href="`/cotizaciones?ver=${quote.id}`" :class="tableLink">{{ quote.number }}</Link>
                                    <span class="block text-xs text-cap-text-soft">{{ date(quote.quoted_at, 'medium') }}</span>
                                </span>
                                <span class="flex items-center gap-3">
                                    <span class="tabular-nums">{{ money(quote.total) }}</span>
                                    <StatusBadge :value="quote.status" :label="quote.status" />
                                </span>
                            </li>
                        </ul>
                        <EmptyState v-else compact icon="file-document-outline" title="Sin cotizaciones" description="Crea la cotización desde la revisión del pliego o en Cotizaciones." />
                    </section>
                </div>
            </template>

            <template #historial>
                <section :class="card" class="p-5">
                    <Deferred data="history">
                        <template #fallback><CapSkeleton variant="line" :rows="4" label="Cargando el historial" /></template>
                        <ol v-if="history?.length" class="space-y-4">
                            <li v-for="entry in history" :key="entry.id" class="flex gap-3">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-cap-card-2 text-cap-text-soft" aria-hidden="true"><i class="mdi text-base leading-none" :class="`mdi-${HISTORY_ICONS[entry.action] ?? 'circle-small'}`" /></span>
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ entry.title }}</p>
                                    <p v-if="entry.detail" class="text-sm text-gray-700 dark:text-gray-300">{{ entry.detail }}</p>
                                    <p class="text-xs text-cap-text-soft">{{ [entry.actor, dateTime(entry.at, 'medium')].filter(Boolean).join(', ') }}</p>
                                </div>
                            </li>
                        </ol>
                        <EmptyState v-else compact icon="history" title="Sin movimientos" description="Aquí queda cada cambio de la licitación." />
                    </Deferred>
                </section>
            </template>
        </Tabs>

        <TenderFormModal v-model:open="editOpen" :tender="tender" :statuses="statuses" :modalities="modalities" />

        <BaseModal :open="Boolean(preview)" size="xl" flush :title="preview?.name ?? ''" @update:open="preview = $event ? preview : null">
            <PdfViewer v-if="preview" :src="preview.previewUrl" :name="preview.name" :download-url="preview.downloadUrl" height="h-[75vh]" />
        </BaseModal>
    </AppLayout>
</template>
