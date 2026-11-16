<script setup>
// Revisión del análisis del pliego: cada dato con su cita, su página (abre el PDF ahí) y su
// verificación. Lo «sin evidencia» nunca se marca solo; si un dato difiere del que ya tiene la
// licitación (p. ej. de la captura), se muestran los dos y la persona elige.
import { router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import CapAlert from '../../../design/vue/CapAlert.vue';
import CapSegmented from '../../../design/vue/CapSegmented.vue';
import AppButton from '../../Components/AppButton.vue';
import BaseModal from '../../Components/BaseModal.vue';
import Checkbox from '../../Components/Checkbox.vue';
import DateTimeInput from '../../Components/DateTimeInput.vue';
import EmptyState from '../../Components/EmptyState.vue';
import FormField from '../../Components/FormField.vue';
import PdfViewer from '../../Components/PdfViewer.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import TextArea from '../../Components/TextArea.vue';
import TextInput from '../../Components/TextInput.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { dateTime, number, quantity } from '../../lib/format.js';
import { focusFirstError } from '../../lib/errors.js';
import { card, textLink } from '../../lib/ui.js';
import { EVIDENCE_STATUS } from '../../Modules/Tenders/tenders.js';

const props = defineProps({
    tender: { type: Object, required: true },
    analysis: { type: Object, required: true },
    rows: { type: Array, default: () => [] },
    lists: { type: Object, default: () => ({}) },
    applied: { type: Array, default: () => [] },
    pdfUrl: { type: String, required: true },
    matrixId: { type: Number, default: null },
    quoteId: { type: Number, default: null },
    maxPages: { type: Number, default: 300 },
    can: { type: Object, default: () => ({}) },
});

// En escritorio el PDF queda fijo a la derecha y cada cita lo lleva a su página; en móvil se abre en
// una modal a pantalla completa.
const desktopQuery = typeof window !== 'undefined' ? window.matchMedia('(min-width: 1280px)') : null;
const isDesktop = ref(desktopQuery?.matches ?? true);
const onMedia = (event) => (isDesktop.value = event.matches);
onMounted(() => desktopQuery?.addEventListener('change', onMedia));
onBeforeUnmount(() => desktopQuery?.removeEventListener('change', onMedia));
const sideViewer = ref(null);
const activeRow = ref(null);
const counts = computed(() => ({
    verified: props.rows.filter((row) => row.found && row.status === 'verified').length,
    doubtful: props.rows.filter((row) => row.found && row.status === 'doubtful').length,
    unverified: props.rows.filter((row) => row.found && row.status === 'unverified').length,
    missing: props.rows.filter((row) => !row.found).length,
}));

const confirmed = computed(() => props.analysis.status === 'confirmed');
const editable = computed(() => props.can.confirm && !confirmed.value);

function inputValue(row) {
    if (row.value === null || row.value === undefined) return '';
    if (row.type !== 'datetime') return String(row.value);
    const value = String(row.value);
    // Sin hora en el pliego: aclaraciones «hasta el día» = fin del día; lo demás, revisar.
    if (value.length <= 10) return `${value}T${row.key === 'clarification_deadline' ? '23:59' : '00:00'}`;
    return value.slice(0, 16);
}

const state = reactive(
    Object.fromEntries(
        props.rows.map((row) => [
            row.key,
            {
                apply: confirmed.value ? props.applied.includes(row.key) : row.apply,
                value: inputValue(row),
                // Con conflicto la persona elige: no hay opción por defecto.
                choice: row.conflict && !confirmed.value ? null : undefined,
            },
        ]),
    ),
);
const listChoices = reactive({
    documents: confirmed.value ? props.applied.includes('required_documents') : Boolean(props.lists.required_documents?.length),
    criteria: confirmed.value ? props.applied.includes('evaluation_criteria') : false,
});

const form = useForm({ fields: {}, lists: {} });
const pendingChoices = computed(() => props.rows.filter((row) => row.conflict && row.column && state[row.key].choice === null));
const toApply = computed(() => props.rows.filter((row) => row.column && isApplied(row)).length);

function isApplied(row) {
    const entry = state[row.key];
    return entry.choice === undefined ? entry.apply : entry.choice === 'pliego';
}

function evidence(row) {
    return EVIDENCE_STATUS[row.found ? row.status : 'missing'] ?? EVIDENCE_STATUS.missing;
}

function currentLabel(row) {
    if (row.current === null || row.current === undefined) return '—';
    return row.type === 'datetime' ? dateTime(row.current) : String(row.current);
}

function submit() {
    form.fields = Object.fromEntries(props.rows.filter((row) => row.column).map((row) => [row.key, { apply: isApplied(row), value: state[row.key].value === '' ? null : state[row.key].value }]));
    form.lists = { ...listChoices };
    form.post(`/licitaciones/${props.tender.id}/pliego/${props.analysis.id}/confirmar`, {
        preserveScroll: true,
        onError: () => nextTick(() => focusFirstError(document.querySelector('[data-bid-review]'))),
    });
}

// Visor del PDF en la página de la cita.
const viewer = ref(null);
const pdfOpen = ref(false);
const targetPage = ref(1);
const pdfTitle = ref('');

function openPage(page, title = '') {
    targetPage.value = page || 1;
    pdfTitle.value = title;
    if (isDesktop.value) {
        sideViewer.value?.goToPage(targetPage.value);
        return;
    }
    if (pdfOpen.value) viewer.value?.goToPage(targetPage.value);
    pdfOpen.value = true;
}

function onLoaded() {
    nextTick(() => viewer.value?.goToPage(targetPage.value));
}

const busy = ref('');
function action(kind) {
    busy.value = kind;
    router.post(`/licitaciones/${props.tender.id}/pliego/${props.analysis.id}/${kind}`, {}, { preserveScroll: true, onFinish: () => (busy.value = '') });
}

const LIST_SECTIONS = [
    { key: 'guarantees', title: 'Garantías exigidas', empty: 'No se encontraron garantías.', text: (item) => [item.type, item.percentage, item.validity && `vigencia ${item.validity}`].filter(Boolean).join(' · ') },
    { key: 'required_documents', title: 'Documentos a presentar', empty: 'No se encontraron documentos exigidos.', text: (item) => item.name },
    { key: 'items', title: 'Productos o servicios', empty: 'No se encontró la lista de productos.', text: (item) => `${item.description}${item.quantity ? ` — ${quantity(item.quantity) !== '—' ? quantity(item.quantity) : item.quantity} ${item.unit ?? ''}` : ''}` },
    { key: 'evaluation_criteria', title: 'Criterios de evaluación', empty: 'No se encontraron criterios.', text: (item) => item.criterion },
];
</script>

<template>
    <AppLayout title="Revisar pliego" :back-href="`/licitaciones/${tender.id}?tab=analisis`" :breadcrumbs="[{ label: 'Licitaciones', href: '/licitaciones' }, { label: tender.reference, href: `/licitaciones/${tender.id}?tab=analisis` }, { label: 'Revisar pliego' }]">
        <CapAlert v-if="confirmed" status="ok" title="Revisión confirmada" :detail="`Los datos marcados se aplicaron a la licitación el ${dateTime(analysis.reviewedAt)}.`" />
        <CapAlert v-else status="info" title="Revisa cada dato con su cita antes de aplicarlo" detail="La IA propuso estos datos y el sistema comprobó que cada cita exista en la página indicada del PDF. Lo «sin evidencia» no se marca solo. Nada cambia en la licitación hasta que confirmes." />

        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm" data-evidence-summary>
            <span class="inline-flex items-center gap-1 text-cap-status-ok-ink"><i class="mdi mdi-check-decagram-outline text-base leading-none" aria-hidden="true" />{{ counts.verified }} verificados</span>
            <span class="inline-flex items-center gap-1 text-cap-status-warning-ink"><i class="mdi mdi-alert-outline text-base leading-none" aria-hidden="true" />{{ counts.doubtful }} dudosos</span>
            <span class="inline-flex items-center gap-1 text-cap-status-error-ink"><i class="mdi mdi-close-octagon-outline text-base leading-none" aria-hidden="true" />{{ counts.unverified }} sin evidencia</span>
            <span class="inline-flex items-center gap-1 text-cap-text-soft"><i class="mdi mdi-help-circle-outline text-base leading-none" aria-hidden="true" />{{ counts.missing }} no encontrados</span>
        </div>
        <CapAlert v-if="analysis.pages && analysis.pages >= maxPages" status="warning" title="Se analizó solo una parte del pliego" :detail="`El análisis lee hasta ${maxPages} páginas. Revisa a mano los datos que estén después de la página ${maxPages}.`" />

        <p class="text-xs text-cap-text-soft">
            {{ tender.reference }} · Análisis #{{ analysis.id }}<span v-if="analysis.pages"> · {{ analysis.pages }} páginas</span><span v-if="analysis.tokens"> · {{ number(analysis.tokens) }} tokens</span><span v-if="analysis.seconds"> · {{ analysis.seconds }} s</span> · El texto del pliego se envió a OpenAI.
        </p>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        <form class="flex min-w-0 flex-col gap-6" data-bid-review novalidate @submit.prevent="submit">
            <section :class="card" class="p-5">
                <h3 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Datos del proceso</h3>
                <ol v-stagger class="mt-4 flex flex-col divide-y divide-cap-divider">
                    <li v-for="row in rows" :key="row.key" class="-mx-2 flex flex-col gap-3 rounded-cap-control px-2 py-4 transition-colors duration-cap-fast ease-cap first:pt-2 last:pb-2" :class="activeRow === row.key ? 'bg-cap-status-info-tint' : ''" :data-row="row.key" :data-evidence="row.found ? row.status : 'missing'" @focusin="activeRow = row.key">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ row.label }}</p>
                            <StatusBadge :status="evidence(row).status" :label="evidence(row).label" />
                        </div>

                        <template v-if="row.found">
                            <figure v-if="row.quote" class="rounded-cap-control bg-cap-card-2 p-3">
                                <blockquote class="text-sm italic text-gray-800 dark:text-gray-200">«{{ row.quote }}»</blockquote>
                                <figcaption class="mt-2 flex flex-wrap items-center gap-3 text-xs text-cap-text-soft">
                                    <button v-if="row.page" type="button" :class="textLink" class="inline-flex items-center gap-1" @click="(activeRow = row.key), openPage(row.page, row.label)"><i class="mdi mdi-file-eye-outline text-base" aria-hidden="true" />Ver en el PDF, página {{ row.page }}</button>
                                    <span v-if="row.note">{{ row.note }}</span>
                                </figcaption>
                            </figure>
                            <p v-if="row.alternatives?.length" class="text-xs text-cap-text-soft">Otras lecturas: <span v-for="(alternative, index) in row.alternatives" :key="index">{{ alternative.value }} (pág. {{ alternative.page ?? '—' }}){{ index < row.alternatives.length - 1 ? '; ' : '' }}</span></p>

                            <div v-if="row.column" class="grid grid-cols-1 gap-x-6 gap-y-3 md:grid-cols-2">
                                <div>
                                    <p class="text-xs font-medium text-cap-text-soft">En la licitación ahora</p>
                                    <p class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ currentLabel(row) }}</p>
                                </div>
                                <FormField :label="row.conflict ? 'Valor del pliego' : 'Valor a aplicar'" :error="form.errors[`fields.${row.key}.value`]">
                                    <DateTimeInput v-if="row.type === 'datetime'" v-model="state[row.key].value" :readonly="!editable" />
                                    <TextInput v-else-if="row.type === 'integer'" v-model="state[row.key].value" type="number" min="1" max="3650" :readonly="!editable" class="md:max-w-40" />
                                    <TextArea v-else-if="row.key === 'object'" v-model="state[row.key].value" :rows="2" maxlength="1000" :readonly="!editable" />
                                    <TextInput v-else v-model="state[row.key].value" maxlength="255" :readonly="!editable" />
                                </FormField>
                            </div>
                            <p v-if="row.type === 'datetime' && String(row.value).length <= 10 && editable" class="text-xs text-cap-text-soft">El pliego no indica la hora: revísala antes de aplicar.</p>

                            <div v-if="row.column && editable">
                                <div v-if="row.conflict" class="flex flex-col gap-2">
                                    <p class="text-xs font-medium text-cap-status-warning-ink">El pliego dice algo distinto a lo que ya tiene la licitación. Elige cuál conservar.</p>
                                    <CapSegmented v-model="state[row.key].choice" :label="`Valor de ${row.label}`" :items="[{ label: 'Mantener el actual', value: 'actual' }, { label: 'Usar el del pliego', value: 'pliego' }]" />
                                </div>
                                <Checkbox v-else v-model="state[row.key].apply" :label="row.status === 'unverified' ? 'Aplicar aunque no tenga evidencia' : 'Aplicar a la licitación'" />
                            </div>
                            <p v-else-if="row.column && confirmed" class="text-xs text-cap-text-soft">{{ applied.includes(row.key) ? 'Aplicado a la licitación.' : 'No se aplicó.' }}</p>
                        </template>
                        <p v-else class="text-sm text-cap-text-soft">La IA no encontró este dato en el pliego.</p>
                    </li>
                </ol>
            </section>

            <section v-for="section in LIST_SECTIONS" :key="section.key" :class="card" class="p-5" :data-list="section.key">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h3 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">{{ section.title }} <span class="text-sm font-normal text-cap-text-soft">({{ lists[section.key]?.length ?? 0 }})</span></h3>
                    <Checkbox v-if="section.key === 'required_documents' && lists.required_documents?.length" v-model="listChoices.documents" :disabled="!editable" label="Guardar en la licitación" />
                    <Checkbox v-if="section.key === 'evaluation_criteria' && lists.evaluation_criteria?.length" v-model="listChoices.criteria" :disabled="!editable" label="Guardar como requisitos" />
                </div>
                <EmptyState v-if="!lists[section.key]?.length" compact class="mt-3" icon="text-search" :title="section.empty" />
                <ul v-stagger v-else class="mt-3 flex flex-col gap-2">
                    <li v-for="(item, index) in lists[section.key]" :key="index" class="rounded-cap-control bg-cap-card-2 p-3">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <p class="min-w-0 flex-1 text-sm text-gray-900 dark:text-gray-100">{{ section.text(item) }}</p>
                            <StatusBadge :status="(EVIDENCE_STATUS[item.status] ?? EVIDENCE_STATUS.missing).status" :label="(EVIDENCE_STATUS[item.status] ?? EVIDENCE_STATUS.missing).label" />
                        </div>
                        <p v-if="item.quote" class="mt-1 text-xs italic text-cap-text-soft">«{{ item.quote }}»</p>
                        <button v-if="item.page" type="button" :class="textLink" class="mt-1 inline-flex items-center gap-1 text-xs text-cap-text-soft" @click="openPage(item.page, section.title)"><i class="mdi mdi-file-eye-outline text-base" aria-hidden="true" />Página {{ item.page }}</button>
                    </li>
                </ul>
            </section>

            <section :class="card" class="flex flex-col gap-4 p-5">
                <h3 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Siguientes pasos</h3>
                <div class="flex flex-wrap gap-2">
                    <AppButton v-if="can.createMatrix" icon="table-check" :loading="busy === 'matriz'" :disabled="!lists.required_documents?.length && !lists.guarantees?.length" @click="action('matriz')">{{ matrixId ? 'Actualizar matriz documental' : 'Crear matriz documental' }}</AppButton>
                    <AppButton v-if="matrixId" :href="`/matriz-cumplimiento/${matrixId}`" icon="open-in-app">Abrir matriz</AppButton>
                    <AppButton v-if="can.createQuote && !quoteId" icon="file-document-plus-outline" :loading="busy === 'cotizacion'" :disabled="!lists.items?.length" @click="action('cotizacion')">Crear cotización borrador</AppButton>
                    <AppButton v-if="quoteId" href="/cotizaciones" icon="open-in-app">Ver cotizaciones</AppButton>
                </div>
                <p class="text-xs text-cap-text-soft">La matriz usa los documentos y garantías exigidos; la cotización, la lista de productos con precio en cero para completar.</p>
            </section>

            <div v-if="editable" class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-end">
                <p v-if="pendingChoices.length" class="text-sm text-cap-status-warning-ink" role="status">Falta elegir el valor de: {{ pendingChoices.map((row) => row.label).join(', ') }}.</p>
                <p v-else class="text-sm text-cap-text-soft">{{ toApply }} datos se aplicarán a la licitación.</p>
                <div class="flex flex-col-reverse gap-2 sm:flex-row">
                    <AppButton :href="`/licitaciones/${tender.id}?tab=analisis`" :disabled="form.processing">Cancelar</AppButton>
                    <AppButton variant="primary" type="submit" icon="check" :loading="form.processing" :disabled="pendingChoices.length > 0">Confirmar y aplicar</AppButton>
                </div>
            </div>
        </form>

        <aside v-if="isDesktop" class="sticky top-cap-below-header self-start overflow-hidden rounded-cap-card border border-cap-divider bg-cap-card" aria-label="Pliego en PDF" data-bid-pdf>
            <p class="border-b border-cap-divider px-4 py-2 text-xs text-cap-text-soft">{{ pdfTitle ? `${pdfTitle} · página ${targetPage}` : 'Pliego de condiciones: usa «Ver en el PDF» de cada dato para ir a su página.' }}</p>
            <PdfViewer ref="sideViewer" :src="pdfUrl" name="Pliego de condiciones" height="h-[calc(100vh-12rem)]" />
        </aside>
        </div>

        <BaseModal v-if="!isDesktop" v-model:open="pdfOpen" size="xl" flush :title="pdfTitle ? `Pliego · ${pdfTitle}` : 'Pliego'" :description="`Página ${targetPage}`">
            <PdfViewer ref="viewer" :src="pdfUrl" name="Pliego de condiciones" height="h-[75vh]" @loaded="onLoaded" />
        </BaseModal>
    </AppLayout>
</template>
