<script setup>
// Importar captura de HonduCompras, pasos 2 y 3 (Leer → Revisar).
// Mientras el OCR corre en cola, Lici «piensa» y la pantalla consulta el estado (con espera creciente
// y pausa con la pestaña oculta). Con el borrador listo: la captura a la izquierda (zoom y paneo) y
// los campos a la derecha; al enfocar un campo, la captura hace zoom a la zona donde se leyó (cajas
// del TSV de Tesseract). «Ir al siguiente dudoso» recorre los que hay que confirmar. Las
// contradicciones (fechas al revés, fechas pasadas, etapa terminada, monto ilegible) se avisan en
// vivo (B-18). Nada se guarda hasta «Confirmar».
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import CapAlert from '@design/vue/CapAlert.vue';
import AppButton from '@/Components/AppButton.vue';
import Checkbox from '@/Components/Checkbox.vue';
import DateTimeInput from '@/Components/DateTimeInput.vue';
import FormField from '@/Components/FormField.vue';
import MoneyInput from '@/Components/MoneyInput.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextArea from '@/Components/TextArea.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { focusFirstError } from '@/lib/errors.js';
import { getJson } from '@/lib/http.js';
import { card, tableLink, textLink } from '@/lib/ui.js';
import Mascot from '@/Modules/Auth/Mascot.vue';
import CaptureViewer from '@/Modules/Tenders/CaptureViewer.vue';
import { captureWarnings, createPoller, READ_STATUS } from '@/Modules/Tenders/tenders.js';

const props = defineProps({
    review: { type: Object, required: true },
    statuses: { type: Array, default: () => [] },
    modalities: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
});

// ── Lectura en cola ─────────────────────────────────────────────────────────────────────────────
const waited = ref(0);
const poller = createPoller(
    async () => {
        const fresh = await getJson(props.review.statusUrl);
        if (!fresh.pending) {
            // Sin conservar el estado: el formulario se arma de nuevo con los datos leídos.
            router.visit(window.location.href, { preserveState: false, replace: true });
            return false;
        }
        return true;
    },
    { start: 1200, max: 6000, factor: 1.4 },
);
let waitClock = null;
onMounted(() => {
    if (!props.review.pending) return;
    poller.start();
    waitClock = setInterval(() => waited.value++, 1000);
});
onBeforeUnmount(() => {
    poller.stop();
    clearInterval(waitClock);
});

// ── Datos leídos ────────────────────────────────────────────────────────────────────────────────
const fields = computed(() => props.review.fields ?? {});
const value = (key) => fields.value[key]?.value ?? null;
const asInput = (key) => (value(key) ? String(value(key)).replace(' ', 'T').slice(0, 16) : '');
const candidates = computed(() => props.review.institutionCandidates ?? []);
const entityText = computed(() => value('entity') ?? '');
const acronym = computed(() => /\(([^)]+)\)\s*$/.exec(entityText.value)?.[1] ?? '');

const form = useForm({
    reference: value('reference') ?? '',
    title: value('title') ?? '',
    purchasing_unit: value('purchasing_unit') ?? '',
    started_at: asInput('started_at'),
    clarification_deadline: asInput('clarification_deadline'),
    presentation_deadline: asInput('presentation_deadline'),
    funding_source_type: value('funding_source_type') ?? '',
    funding_source: value('funding_source') ?? '',
    purchase_type: value('purchase_type') ?? '',
    honducompras_stage: value('honducompras_stage') ?? '',
    acquisition_type: value('acquisition_type') ?? '',
    offer_reception_place: value('offer_reception_place') ?? '',
    bid_document_fee: value('bid_document_fee'),
    estimated_amount: null,
    contact_name: value('contact_name') ?? '',
    contact_phone: value('contact_phone') ?? '',
    contact_email: value('contact_email') ?? '',
    institution_id: props.review.suggestedInstitutionId,
    new_institution_name: '',
    new_institution_acronym: '',
    status: props.review.suggestedStatus ?? 'Activa',
    create_opportunity: true,
    update_existing: Boolean(props.review.duplicate),
    update_tender_id: props.review.duplicate?.id ?? null,
});

// Entidad: una del catálogo (sugerida, otra parecida o buscada) o una nueva con el nombre leído.
const entityMode = ref(props.review.suggestedInstitutionId || candidates.value.length ? 'catalog' : 'new');
if (entityMode.value === 'new') {
    form.new_institution_name = entityText.value;
    form.new_institution_acronym = acronym.value;
}
const searched = ref(null);

function chooseCandidate(id) {
    entityMode.value = 'catalog';
    form.institution_id = id;
}

function chooseNew() {
    entityMode.value = 'new';
    form.institution_id = null;
    form.new_institution_name ||= entityText.value;
    form.new_institution_acronym ||= acronym.value;
}

// ── Campos, dudosos y resaltado ─────────────────────────────────────────────────────────────────
const groups = [
    {
        id: 'identificacion',
        title: 'Identificación',
        fields: [
            { key: 'reference', label: 'Expediente', required: true, input: 'text', max: 100 },
            { key: 'title', label: 'Objeto', required: true, input: 'textarea', max: 1000, wide: true },
            { key: 'purchasing_unit', label: 'Unidad de compra', input: 'text', max: 255, wide: true },
        ],
    },
    {
        id: 'fechas',
        title: 'Fechas críticas',
        fields: [
            { key: 'started_at', label: 'Fecha de inicio', input: 'datetime' },
            { key: 'clarification_deadline', label: 'Cierre de aclaraciones', input: 'datetime' },
            { key: 'presentation_deadline', label: 'Recepción de ofertas', input: 'datetime', wide: true },
        ],
    },
    {
        id: 'clasificacion',
        title: 'Clasificación',
        fields: [
            { key: 'purchase_type', label: 'Modalidad', input: 'text', max: 255 },
            { key: 'honducompras_stage', label: 'Etapa en HonduCompras', input: 'text', max: 120 },
            { key: 'acquisition_type', label: 'Tipo de adquisición', input: 'text', max: 120 },
            { key: 'bid_document_fee', label: 'Valor de pliegos', input: 'money' },
            { key: 'funding_source_type', label: 'Tipo de fuente', input: 'text', max: 120 },
            { key: 'funding_source', label: 'Fuente', input: 'text', max: 120 },
        ],
    },
    {
        id: 'contacto',
        title: 'Recepción y contacto',
        fields: [
            { key: 'offer_reception_place', label: 'Lugar de recepción de ofertas', input: 'text', max: 500, wide: true },
            { key: 'contact_name', label: 'Contacto', input: 'text', max: 255 },
            { key: 'contact_phone', label: 'Teléfono', input: 'text', max: 100 },
            { key: 'contact_email', label: 'Correo', input: 'email', max: 255, wide: true },
        ],
    },
];
const ORDER = [...groups.flatMap((group) => group.fields.map((field) => field.key)), 'entity'];
const LABELS = Object.fromEntries([...groups.flatMap((group) => group.fields.map((field) => [field.key, field.label])), ['entity', 'Entidad']]);

const statusOf = (key) => fields.value[key]?.status ?? 'missing';
const reviewed = reactive(new Set());
const doubtful = computed(() => ORDER.filter((key) => statusOf(key) === 'doubtful'));
const pendingDoubtful = computed(() => doubtful.value.filter((key) => !reviewed.has(key)));
const counts = computed(() => ({
    read: ORDER.filter((key) => statusOf(key) === 'read').length,
    doubtful: doubtful.value.length,
    missing: ORDER.filter((key) => statusOf(key) === 'missing').length,
}));

const activeKey = ref(null);
const activeBox = computed(() => (activeKey.value ? fields.value[activeKey.value]?.box ?? null : null));
const boxes = computed(() => ORDER.filter((key) => fields.value[key]?.box).map((key) => ({ key, label: LABELS[key], box: fields.value[key].box, status: reviewed.has(key) ? 'read' : statusOf(key) })));
const hasBoxes = computed(() => boxes.value.length > 0);
const viewer = ref(null);
const captureOpen = ref(true);
// Un solo visor: en escritorio a la izquierda; en móvil, fijo arriba junto al resumen.
const desktopQuery = typeof window !== 'undefined' ? window.matchMedia('(min-width: 1024px)') : null;
const isDesktop = ref(desktopQuery?.matches ?? true);
const onMedia = (event) => (isDesktop.value = event.matches);
onMounted(() => desktopQuery?.addEventListener('change', onMedia));
onBeforeUnmount(() => desktopQuery?.removeEventListener('change', onMedia));

function activate(key) {
    activeKey.value = key;
}

function markReviewed(key) {
    if (statusOf(key) === 'doubtful') reviewed.add(key);
}

/** Lleva al siguiente dudoso sin revisar (o al primero, en ciclo) y lo enfoca. */
function nextDoubtful() {
    const list = pendingDoubtful.value.length ? pendingDoubtful.value : doubtful.value;
    if (!list.length) return;
    const start = activeKey.value ? ORDER.indexOf(activeKey.value) : -1;
    const key = list.find((candidate) => ORDER.indexOf(candidate) > start) ?? list[0];
    focusField(key);
}

function focusField(key) {
    activate(key);
    captureOpen.value = true;
    nextTick(() => {
        const wrapper = document.querySelector(`[data-field="${key}"]`);
        if (!wrapper) return;
        // Debajo del resumen fijo (y de la captura, en móvil), no tapado por ellos.
        const covered = document.querySelector('[data-read-summary]')?.getBoundingClientRect().bottom ?? 0;
        const top = wrapper.getBoundingClientRect().top + window.scrollY - covered - 16;
        window.scrollTo({ top, behavior: 'smooth' });
        wrapper?.querySelector('input, textarea, select, [role="combobox"]')?.focus({ preventScroll: true });
    });
}

function badge(key) {
    if (reviewed.has(key)) return { status: 'ok', label: 'Revisado', icon: 'check-circle-outline' };
    return READ_STATUS[statusOf(key)];
}

const TONE_TEXT = { ok: 'text-cap-status-ok-ink', warning: 'text-cap-status-warning-ink', neutral: 'text-cap-text-soft' };

// ── Contradicciones (en vivo) ───────────────────────────────────────────────────────────────────
const warnings = computed(() => captureWarnings(form.data(), fields.value));
const contradictions = computed(() => warnings.value.filter((warning) => warning.kind === 'contradiction'));
const notices = computed(() => warnings.value.filter((warning) => warning.kind === 'info'));
const warningFor = (key) => warnings.value.find((warning) => warning.field === key);

// ── Confirmar ───────────────────────────────────────────────────────────────────────────────────
const statusOptions = computed(() => props.statuses.map((status) => ({ value: status, label: status })));

function submit() {
    if (entityMode.value === 'new') form.institution_id = null;
    else {
        form.new_institution_name = '';
        form.new_institution_acronym = '';
    }
    form.transform((data) => ({ ...data, update_tender_id: data.update_existing ? data.update_tender_id : null })).post(`/licitaciones/importar/${props.review.token}`, {
        preserveScroll: true,
        onError: () => nextTick(() => focusFirstError(document.querySelector('[data-import-form]'))),
    });
}

const steps = [
    { key: 'subir', label: 'Subir' },
    { key: 'leer', label: 'Leer' },
    { key: 'revisar', label: 'Revisar' },
];
const currentStep = computed(() => (props.review.pending ? 1 : 2));
</script>

<template>
    <AppLayout title="Revisar captura" back-href="/licitaciones" :breadcrumbs="[{ label: 'Licitaciones', href: '/licitaciones' }, { label: 'Revisar captura' }]">
        <ol class="flex items-center gap-2 text-xs" aria-label="Pasos de la importación" data-import-steps>
            <li v-for="(step, index) in steps" :key="step.key" class="flex items-center gap-2">
                <span class="flex h-6 w-6 items-center justify-center rounded-full text-xs font-semibold" :class="index < currentStep ? 'bg-cap-status-ok-tint text-cap-status-ok-ink' : index === currentStep ? 'bg-cap-chrome text-white dark:bg-gray-100 dark:text-gray-900' : 'bg-cap-card-2 text-cap-text-soft'" :aria-current="index === currentStep ? 'step' : undefined">
                    <i v-if="index < currentStep" class="mdi mdi-check text-sm leading-none" aria-hidden="true" /><span v-else>{{ index + 1 }}</span>
                </span>
                <span :class="index === currentStep ? 'font-semibold text-gray-900 dark:text-gray-100' : 'text-cap-text-soft'">{{ step.label }}</span>
                <i v-if="index < steps.length - 1" class="mdi mdi-chevron-right text-base text-cap-text-soft" aria-hidden="true" />
            </li>
        </ol>

        <!-- Paso 2: leyendo -->
        <section v-if="review.pending" :class="card" class="grid grid-cols-1 items-center gap-6 p-6 md:grid-cols-[auto_minmax(0,1fr)] md:p-8" role="status" aria-live="polite" data-import-pending>
            <div class="flex justify-center"><Mascot state="thinking" :size="128" /></div>
            <div class="flex flex-col gap-3">
                <h2 class="font-display text-lg font-semibold text-gray-900 dark:text-gray-100">Leyendo la captura…</h2>
                <p class="max-w-lg text-sm text-cap-text-soft">Lici está reconociendo el texto de la ficha. Suele tardar unos segundos; esta pantalla se actualiza sola cuando el borrador esté listo.</p>
                <p v-if="waited > 20" class="text-sm text-cap-status-warning-ink">Está tardando más de lo normal: la cola puede estar ocupada. Puedes esperar aquí o volver más tarde con el mismo enlace.</p>
                <div class="mt-2 flex flex-col gap-2" aria-hidden="true">
                    <span class="cap-skeleton h-3 w-3/4" />
                    <span class="cap-skeleton h-3 w-1/2" />
                    <span class="cap-skeleton h-3 w-2/3" />
                </div>
            </div>
        </section>

        <template v-else>
            <CapAlert v-if="review.error" status="warning" title="No se pudo leer la captura automáticamente" :detail="review.error" />

            <!-- Resumen de la lectura y navegación por dudosos (en móvil, con la captura fija debajo). -->
            <div class="sticky z-cap-header -mx-1 overflow-hidden rounded-cap-card border border-cap-divider bg-cap-card shadow-sm max-lg:top-16 lg:top-cap-below-header" data-read-summary>
                <div class="flex items-center gap-x-3 gap-y-2 px-3 py-2 sm:flex-wrap sm:px-4 sm:py-3">
                    <p class="flex min-w-0 flex-1 items-center gap-x-3 overflow-hidden text-sm">
                        <span class="inline-flex items-center gap-1 text-cap-status-ok-ink" :title="`${counts.read} leídos`"><i class="mdi mdi-check-circle-outline text-base leading-none" aria-hidden="true" />{{ counts.read }}<span class="max-sm:sr-only">leídos</span></span>
                        <span class="inline-flex items-center gap-1 font-medium text-cap-status-warning-ink" data-doubtful-count><i class="mdi mdi-alert-outline text-base leading-none" aria-hidden="true" />{{ pendingDoubtful.length }} dudosos<span class="max-sm:sr-only">por revisar</span></span>
                        <span class="inline-flex items-center gap-1 text-cap-text-soft" :title="`${counts.missing} no encontrados`"><i class="mdi mdi-help-circle-outline text-base leading-none" aria-hidden="true" />{{ counts.missing }}<span class="max-sm:sr-only">no encontrados</span></span>
                    </p>
                    <div class="ml-auto flex shrink-0 items-center gap-1">
                        <button v-if="!isDesktop" type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-full text-cap-text-soft hover:bg-cap-card-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome dark:focus-visible:ring-gray-100" :aria-label="captureOpen ? 'Ocultar la captura' : 'Mostrar la captura'" :aria-pressed="String(captureOpen)" @click="captureOpen = !captureOpen"><i class="mdi text-xl leading-none" :class="captureOpen ? 'mdi-image-off-outline' : 'mdi-image-outline'" aria-hidden="true" /></button>
                        <AppButton v-if="doubtful.length" icon="debug-step-over" data-next-doubtful @click="nextDoubtful"><span class="max-sm:hidden">Ir al siguiente dudoso</span><span class="sm:hidden">Siguiente</span></AppButton>
                    </div>
                </div>
                <div v-if="!isDesktop && captureOpen" class="border-t border-cap-divider" data-capture-panel>
                    <CaptureViewer ref="viewer" :src="review.imageUrl" :name="review.imageName" :box="activeBox" :boxes="boxes" height="h-44" @select="focusField" />
                </div>
            </div>

            <!-- Avisos en línea (receta «Aviso en línea»: CapAlert no admite contenido propio). -->
            <div v-if="contradictions.length" class="cap-alert cap-alert--warning" role="status" data-contradictions>
                <i class="cap-alert__icon mdi mdi-alert-outline" aria-hidden="true" />
                <div>
                    <strong class="cap-alert__title">{{ contradictions.length === 1 ? 'Un dato no cuadra' : `${contradictions.length} datos no cuadran` }}</strong>
                    <ul class="cap-alert__detail mt-1 list-disc space-y-1 pl-4">
                        <li v-for="warning in contradictions" :key="warning.field + warning.message"><button type="button" :class="textLink" class="text-left" @click="focusField(warning.field)">{{ warning.message }}</button></li>
                    </ul>
                </div>
            </div>

            <div v-if="review.duplicate" class="cap-alert cap-alert--info" role="status" data-duplicate>
                <i class="cap-alert__icon mdi mdi-information-outline" aria-hidden="true" />
                <div>
                    <strong class="cap-alert__title">Ya existe {{ review.duplicate.reference }}</strong>
                    <span class="cap-alert__detail">
                        <Link :href="`/licitaciones/${review.duplicate.id}`" :class="textLink">Ver la licitación</Link>.
                        {{ form.update_existing ? 'Se completará con esta captura; lo vacío no borra nada.' : 'Marca «Actualizar la licitación existente» abajo para no crear otra.' }}
                        <template v-if="review.existingOpportunity"> Su oportunidad del pipeline se reutiliza.</template>
                    </span>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                <!-- Captura -->
                <section v-if="isDesktop" :class="card" class="sticky top-44 self-start overflow-hidden" aria-label="Captura subida" data-capture-panel>
                    <CaptureViewer ref="viewer" :src="review.imageUrl" :name="review.imageName" :box="activeBox" :boxes="boxes" height="h-52 lg:h-[calc(100vh-15rem)]" @select="focusField" />
                    <p v-if="!hasBoxes" class="border-t border-cap-divider px-3 py-2 text-xs text-cap-text-soft">Esta lectura no trae la ubicación de cada dato: usa el zoom para comparar.</p>
                    <p v-else class="border-t border-cap-divider px-3 py-2 text-xs text-cap-text-soft">Al enfocar un campo, la captura se acerca a donde se leyó. Toca una zona para ir a su campo.</p>
                </section>

                <form :class="card" class="flex flex-col gap-6 p-4 sm:p-5" data-import-form novalidate @submit.prevent="submit">
                    <section v-for="group in groups" :key="group.id" class="flex flex-col gap-3" :aria-labelledby="`g-${group.id}`">
                        <h3 :id="`g-${group.id}`" class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">{{ group.title }}</h3>
                        <div class="grid grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-2">
                            <div
                                v-for="field in group.fields"
                                :key="field.key"
                                class="relative -m-2 rounded-cap-control p-2 transition-colors duration-cap-fast ease-cap"
                                :class="[field.wide ? 'sm:col-span-2' : '', activeKey === field.key ? 'bg-cap-status-info-tint' : statusOf(field.key) === 'doubtful' && !reviewed.has(field.key) ? 'bg-cap-status-warning-tint' : '']"
                                :data-field="field.key"
                                :data-field-status="reviewed.has(field.key) ? 'reviewed' : statusOf(field.key)"
                                @focusin="activate(field.key)"
                                @change="markReviewed(field.key)"
                                @input="markReviewed(field.key)"
                            >
                                <FormField :label="field.label" :required="field.required" :error="form.errors[field.key]" :help="warningFor(field.key)?.message ?? fields[field.key]?.note ?? ''">
                                    <TextArea v-if="field.input === 'textarea'" v-model="form[field.key]" :rows="3" :maxlength="field.max" />
                                    <DateTimeInput v-else-if="field.input === 'datetime'" v-model="form[field.key]" />
                                    <MoneyInput v-else-if="field.input === 'money'" v-model="form[field.key]" :min="0" />
                                    <TextInput v-else v-model="form[field.key]" :type="field.input === 'email' ? 'email' : 'text'" :maxlength="field.max" />
                                </FormField>
                                <!-- Estado de la lectura en la línea de la etiqueta: no gasta una fila por campo. -->
                                <span class="absolute right-2 top-2 inline-flex items-center gap-1 text-xs font-medium" :class="TONE_TEXT[badge(field.key).status]"><i class="mdi text-sm leading-none" :class="`mdi-${badge(field.key).icon}`" aria-hidden="true" />{{ badge(field.key).label }}</span>
                            </div>
                        </div>
                    </section>

                    <section class="flex flex-col gap-3" aria-labelledby="entity-title" data-field="entity" @focusin="activate('entity')">
                        <h3 id="entity-title" class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Entidad</h3>
                        <p class="rounded-cap-control bg-cap-card-2 p-3 text-sm text-gray-900 dark:text-gray-100"><span class="block text-xs text-cap-text-soft">Leído en la captura</span>{{ entityText || 'No se encontró la entidad' }}</p>
                        <fieldset class="flex flex-col gap-2">
                            <legend class="mb-1 text-xs font-semibold text-gray-700 dark:text-gray-300">Institución del catálogo</legend>
                            <label v-for="candidate in candidates" :key="candidate.id" class="flex min-h-11 cursor-pointer items-center gap-3 rounded-cap-control border border-cap-divider px-3 py-2 text-sm transition-colors duration-cap-fast ease-cap hover:bg-cap-card-2" :class="entityMode === 'catalog' && form.institution_id === candidate.id ? 'border-cap-chrome bg-cap-card-2 dark:border-gray-100' : ''">
                                <input type="radio" name="entity" class="h-4 w-4 accent-cap-brand" :checked="entityMode === 'catalog' && form.institution_id === candidate.id" @change="chooseCandidate(candidate.id)" />
                                <span class="min-w-0 flex-1 text-gray-900 dark:text-gray-100">{{ candidate.name }}<span v-if="candidate.acronym" class="text-cap-text-soft"> ({{ candidate.acronym }})</span></span>
                                <span class="shrink-0 text-xs tabular-nums text-cap-text-soft">{{ Math.round(candidate.score * 100) }} %</span>
                            </label>
                            <div class="flex flex-col gap-2 rounded-cap-control border border-cap-divider p-3" :class="entityMode === 'catalog' && form.institution_id && !candidates.some((c) => c.id === form.institution_id) ? 'border-cap-chrome bg-cap-card-2 dark:border-gray-100' : ''">
                                <span class="text-xs font-medium text-gray-700 dark:text-gray-300">Buscar otra institución</span>
                                <SearchSelect v-model="form.institution_id" url="/instituciones/buscar" option-value="id" option-label="name" :selected="searched" placeholder="Nombre o siglas…" aria-label="Buscar institución" @select="(option) => { searched = option; entityMode = 'catalog'; }" />
                            </div>
                            <label v-if="can.createInstitution" class="flex min-h-11 cursor-pointer items-center gap-3 rounded-cap-control border border-cap-divider px-3 py-2 text-sm transition-colors duration-cap-fast ease-cap hover:bg-cap-card-2" :class="entityMode === 'new' ? 'border-cap-chrome bg-cap-card-2 dark:border-gray-100' : ''">
                                <input type="radio" name="entity" class="h-4 w-4 accent-cap-brand" :checked="entityMode === 'new'" @change="chooseNew" />
                                <span class="text-gray-900 dark:text-gray-100">Crear una institución nueva</span>
                            </label>
                        </fieldset>
                        <div v-if="entityMode === 'new'" class="grid grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-3">
                            <FormField label="Nombre de la institución" required class="sm:col-span-2" :error="form.errors.new_institution_name"><TextInput v-model="form.new_institution_name" maxlength="255" /></FormField>
                            <FormField label="Siglas" optional :error="form.errors.new_institution_acronym"><TextInput v-model="form.new_institution_acronym" maxlength="30" /></FormField>
                        </div>
                        <p v-if="form.errors.institution_id" class="text-xs text-cap-status-error dark:text-cap-status-dark-error">{{ form.errors.institution_id }}</p>
                    </section>

                    <section class="flex flex-col gap-3" aria-labelledby="confirm-title">
                        <h3 id="confirm-title" class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Al confirmar</h3>
                        <ul v-if="notices.length" class="flex flex-col gap-1 text-xs text-cap-text-soft" data-notices>
                            <li v-for="notice in notices" :key="notice.message" class="flex items-start gap-1.5"><i class="mdi mdi-information-outline text-sm leading-none" aria-hidden="true" />{{ notice.message }}</li>
                        </ul>
                        <div class="grid grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-2">
                            <FormField v-if="!(review.duplicate && form.update_existing)" label="Estado al importar" :error="form.errors.status" :help="review.suggestedStatus !== 'Activa' ? `Sugerido por la etapa y las fechas: ${review.suggestedStatus}.` : ''">
                                <SelectInput v-model="form.status" :options="statusOptions" />
                            </FormField>
                            <FormField label="Monto estimado" optional help="La ficha no lo trae; puedes completarlo después." :error="form.errors.estimated_amount">
                                <MoneyInput v-model="form.estimated_amount" :min="0" />
                            </FormField>
                        </div>
                        <Checkbox v-if="review.duplicate" v-model="form.update_existing" label="Actualizar la licitación existente" description="Completa o corrige sus datos con esta captura (aunque cambies el expediente) en lugar de crear otra." />
                        <Checkbox v-if="can.createOpportunity" v-model="form.create_opportunity" :label="review.existingOpportunity ? 'Vincular su oportunidad del pipeline' : 'Crear oportunidad en el pipeline'" :description="review.existingOpportunity ? `Ya existe «${review.existingOpportunity.title.length > 70 ? `${review.existingOpportunity.title.slice(0, 70)}…` : review.existingOpportunity.title}»: se reutiliza, no se crea otra.` : 'Se agrega en Prospecto, una sola por licitación. Si ya hay una con este expediente, se reutiliza.'" />
                        <p v-if="form.errors.reference && review.duplicate" class="text-xs text-cap-status-error dark:text-cap-status-dark-error" role="alert">{{ form.errors.reference }}</p>
                    </section>

                    <details v-if="review.ocrText" class="rounded-cap-control bg-cap-card-2 p-3 text-sm">
                        <summary class="cursor-pointer text-gray-900 dark:text-gray-100">Texto leído por el OCR</summary>
                        <pre class="mt-2 max-h-64 overflow-auto whitespace-pre-wrap text-xs text-cap-text-soft">{{ review.ocrText }}</pre>
                    </details>

                    <div class="sticky bottom-0 -mx-4 -mb-4 flex items-center justify-end gap-2 rounded-b-cap-card border-t border-cap-divider bg-cap-card px-4 py-3 sm:-mx-5 sm:-mb-5 sm:px-5 max-lg:bottom-20">
                        <p v-if="pendingDoubtful.length" class="mr-auto text-xs text-cap-status-warning-ink max-sm:hidden">Quedan {{ pendingDoubtful.length }} dudosos sin revisar.</p>
                        <AppButton href="/licitaciones" :disabled="form.processing" class="max-sm:hidden">Cancelar</AppButton>
                        <AppButton variant="primary" type="submit" icon="check" :loading="form.processing" class="max-sm:flex-1 max-sm:justify-center" data-confirm-import>{{ review.duplicate && form.update_existing ? 'Confirmar y actualizar' : 'Confirmar e importar' }}</AppButton>
                    </div>
                </form>
            </div>
        </template>
    </AppLayout>
</template>
