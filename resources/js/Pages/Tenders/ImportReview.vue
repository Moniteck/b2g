<script setup>
// Revisión de una captura de HonduCompras: la imagen a un lado (con zoom) y los campos leídos al
// otro, cada uno marcado como leído, dudoso o no encontrado. Nada se guarda hasta «Confirmar».
import { Link, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';
import CapAlert from '../../../design/vue/CapAlert.vue';
import AppButton from '../../Components/AppButton.vue';
import Checkbox from '../../Components/Checkbox.vue';
import DateTimeInput from '../../Components/DateTimeInput.vue';
import FormField from '../../Components/FormField.vue';
import IconButton from '../../Components/IconButton.vue';
import MoneyInput from '../../Components/MoneyInput.vue';
import SearchSelect from '../../Components/SearchSelect.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import TextArea from '../../Components/TextArea.vue';
import TextInput from '../../Components/TextInput.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { focusFirstError } from '../../lib/errors.js';
import { card, tableLink } from '../../lib/ui.js';
import { READ_STATUS } from '../../Modules/Tenders/tenders.js';

const props = defineProps({
    review: { type: Object, required: true },
    statuses: { type: Array, default: () => [] },
    modalities: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
});

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
    create_opportunity: true,
    update_existing: Boolean(props.review.duplicate),
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

const zoom = ref(1);
const zoomIn = () => (zoom.value = Math.min(3, +(zoom.value + 0.25).toFixed(2)));
const zoomOut = () => (zoom.value = Math.max(0.5, +(zoom.value - 0.25).toFixed(2)));

const counts = computed(() => {
    const list = Object.values(fields.value);
    return {
        read: list.filter((field) => field.status === 'read').length,
        doubtful: list.filter((field) => field.status === 'doubtful').length,
        missing: list.filter((field) => field.status === 'missing').length,
    };
});

function badge(key) {
    const status = fields.value[key]?.status ?? 'missing';
    return READ_STATUS[status];
}

function note(key) {
    return fields.value[key]?.note ?? '';
}

function submit() {
    if (entityMode.value === 'new') form.institution_id = null;
    else {
        form.new_institution_name = '';
        form.new_institution_acronym = '';
    }
    form.post(`/licitaciones/importar/${props.review.token}`, {
        preserveScroll: true,
        onError: () => nextTick(() => focusFirstError(document.querySelector('[data-import-form]'))),
    });
}

const groups = [
    {
        title: 'Identificación',
        fields: [
            { key: 'reference', label: 'Expediente', required: true, input: 'text', max: 100 },
            { key: 'title', label: 'Objeto', required: true, input: 'textarea', max: 1000, wide: true },
            { key: 'purchasing_unit', label: 'Unidad de compra', input: 'text', max: 255 },
        ],
    },
    {
        title: 'Fechas críticas',
        fields: [
            { key: 'started_at', label: 'Fecha de inicio', input: 'datetime' },
            { key: 'clarification_deadline', label: 'Cierre de aclaraciones', input: 'datetime' },
            { key: 'presentation_deadline', label: 'Recepción de ofertas', input: 'datetime' },
        ],
    },
    {
        title: 'Clasificación',
        fields: [
            { key: 'purchase_type', label: 'Modalidad', input: 'text', max: 255 },
            { key: 'honducompras_stage', label: 'Etapa', input: 'text', max: 120 },
            { key: 'acquisition_type', label: 'Tipo de adquisición', input: 'text', max: 120 },
            { key: 'funding_source_type', label: 'Tipo de fuente', input: 'text', max: 120 },
            { key: 'funding_source', label: 'Fuente', input: 'text', max: 120 },
            { key: 'bid_document_fee', label: 'Valor de pliegos', input: 'money' },
        ],
    },
    {
        title: 'Recepción y contacto',
        fields: [
            { key: 'offer_reception_place', label: 'Lugar de recepción de ofertas', input: 'text', max: 500, wide: true },
            { key: 'contact_name', label: 'Contacto', input: 'text', max: 255 },
            { key: 'contact_phone', label: 'Teléfono', input: 'text', max: 100 },
            { key: 'contact_email', label: 'Correo', input: 'email', max: 255, wide: true },
        ],
    },
];
</script>

<template>
    <AppLayout title="Revisar captura" back-href="/licitaciones" :breadcrumbs="[{ label: 'Licitaciones', href: '/licitaciones' }, { label: 'Revisar captura' }]">
        <CapAlert v-if="review.error" status="warning" title="No se pudo leer la captura automáticamente" :detail="review.error" />
        <CapAlert v-else status="info" title="Revisa los datos antes de guardar" :detail="`${counts.read} leídos, ${counts.doubtful} dudosos y ${counts.missing} no encontrados. Corrige lo necesario: nada se guarda hasta que confirmes.`" />

        <CapAlert v-if="review.duplicate" status="warning" :title="`Ya existe la licitación ${review.duplicate.reference}`" :detail="`${review.duplicate.title} (${review.duplicate.status}). Puedes actualizarla con los datos de esta captura en lugar de crear otra.`" />

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <section :class="card" class="flex flex-col overflow-hidden lg:sticky lg:top-cap-below-header lg:self-start" aria-label="Captura subida">
                <header class="flex items-center justify-between gap-2 border-b border-cap-divider px-4 py-2">
                    <p class="min-w-0 truncate text-sm font-medium text-gray-900 dark:text-gray-100" :title="review.imageName">{{ review.imageName }}</p>
                    <div class="flex items-center gap-1">
                        <IconButton icon="magnify-minus-outline" label="Alejar" :disabled="zoom <= 0.5" @click="zoomOut" />
                        <span class="w-12 text-center text-xs tabular-nums text-cap-text-soft">{{ Math.round(zoom * 100) }} %</span>
                        <IconButton icon="magnify-plus-outline" label="Acercar" :disabled="zoom >= 3" @click="zoomIn" />
                        <IconButton icon="fit-to-page-outline" label="Ajustar al ancho" @click="zoom = 1" />
                    </div>
                </header>
                <div class="h-[45vh] overflow-auto bg-cap-card-2 lg:h-[calc(100vh-14rem)]">
                    <img :src="review.imageUrl" alt="Captura de la ficha de HonduCompras" class="max-w-none origin-top-left" :style="{ width: `${zoom * 100}%` }" />
                </div>
            </section>

            <form :class="card" class="flex flex-col gap-8 p-5" data-import-form novalidate @submit.prevent="submit">
                <section v-for="group in groups" :key="group.title" class="flex flex-col gap-4">
                    <h3 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">{{ group.title }}</h3>
                    <div class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
                        <FormField v-for="field in group.fields" :key="field.key" :label="field.label" :required="field.required" :error="form.errors[field.key]" :help="note(field.key)" :class="field.wide ? 'md:col-span-2' : ''">
                            <template #default>
                                <div class="flex flex-col gap-1">
                                    <TextArea v-if="field.input === 'textarea'" v-model="form[field.key]" :rows="3" :maxlength="field.max" />
                                    <DateTimeInput v-else-if="field.input === 'datetime'" v-model="form[field.key]" />
                                    <MoneyInput v-else-if="field.input === 'money'" v-model="form[field.key]" :min="0" />
                                    <TextInput v-else v-model="form[field.key]" :type="field.input === 'email' ? 'email' : 'text'" :maxlength="field.max" />
                                    <span class="self-start" :data-field-status="fields[field.key]?.status ?? 'missing'"><StatusBadge :status="badge(field.key).status" :label="badge(field.key).label" /></span>
                                </div>
                            </template>
                        </FormField>
                    </div>
                </section>

                <section class="flex flex-col gap-4" aria-labelledby="entity-title">
                    <h3 id="entity-title" class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Entidad</h3>
                    <div class="rounded-cap-control bg-cap-card-2 p-3 text-sm">
                        <p class="text-xs text-cap-text-soft">Leído en la captura</p>
                        <p class="mt-1 text-gray-900 dark:text-gray-100">{{ entityText || 'No se encontró la entidad' }}</p>
                        <span class="mt-2 inline-block"><StatusBadge :status="badge('entity').status" :label="badge('entity').label" /></span>
                    </div>
                    <fieldset class="flex flex-col gap-2">
                        <legend class="mb-1 text-xs font-semibold text-gray-700 dark:text-gray-300">Institución del catálogo</legend>
                        <label v-for="candidate in candidates" :key="candidate.id" class="flex cursor-pointer items-center gap-3 rounded-cap-control border border-cap-divider p-3 text-sm transition-colors duration-cap-fast ease-cap hover:bg-cap-card-2" :class="entityMode === 'catalog' && form.institution_id === candidate.id ? 'border-cap-brand bg-cap-card-2' : ''">
                            <input type="radio" name="entity" class="h-4 w-4 accent-cap-brand" :checked="entityMode === 'catalog' && form.institution_id === candidate.id" @change="chooseCandidate(candidate.id)" />
                            <span class="min-w-0 flex-1 text-gray-900 dark:text-gray-100">{{ candidate.name }}<span v-if="candidate.acronym" class="text-cap-text-soft"> ({{ candidate.acronym }})</span></span>
                            <span class="text-xs tabular-nums text-cap-text-soft">{{ Math.round(candidate.score * 100) }} % parecido</span>
                        </label>
                        <div class="flex flex-col gap-2 rounded-cap-control border border-cap-divider p-3" :class="entityMode === 'catalog' && form.institution_id && !candidates.some((c) => c.id === form.institution_id) ? 'border-cap-brand bg-cap-card-2' : ''">
                            <span class="text-sm text-gray-900 dark:text-gray-100">Buscar otra institución</span>
                            <SearchSelect v-model="form.institution_id" url="/instituciones/buscar" option-value="id" option-label="name" :selected="searched" placeholder="Nombre o siglas…" aria-label="Buscar institución" @select="(option) => { searched = option; entityMode = 'catalog'; }" />
                        </div>
                        <label v-if="can.createInstitution" class="flex cursor-pointer items-center gap-3 rounded-cap-control border border-cap-divider p-3 text-sm transition-colors duration-cap-fast ease-cap hover:bg-cap-card-2" :class="entityMode === 'new' ? 'border-cap-brand bg-cap-card-2' : ''">
                            <input type="radio" name="entity" class="h-4 w-4 accent-cap-brand" :checked="entityMode === 'new'" @change="chooseNew" />
                            <span class="text-gray-900 dark:text-gray-100">Crear una institución nueva</span>
                        </label>
                    </fieldset>
                    <div v-if="entityMode === 'new'" class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-3">
                        <FormField label="Nombre de la institución" required class="md:col-span-2" :error="form.errors.new_institution_name"><TextInput v-model="form.new_institution_name" maxlength="255" /></FormField>
                        <FormField label="Siglas" optional :error="form.errors.new_institution_acronym"><TextInput v-model="form.new_institution_acronym" maxlength="30" /></FormField>
                    </div>
                    <p v-if="form.errors.institution_id" class="text-xs text-cap-status-error dark:text-cap-status-dark-error">{{ form.errors.institution_id }}</p>
                </section>

                <section class="flex flex-col gap-4">
                    <h3 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Al confirmar</h3>
                    <FormField label="Monto estimado" optional help="La ficha de HonduCompras no lo trae; puedes completarlo después." :error="form.errors.estimated_amount">
                        <MoneyInput v-model="form.estimated_amount" :min="0" class="md:max-w-xs" />
                    </FormField>
                    <Checkbox v-if="review.duplicate" v-model="form.update_existing" label="Actualizar la licitación existente" description="Se completan o corrigen sus datos con los de esta captura; lo que dejes vacío no borra lo que ya tenía." />
                    <p v-if="review.duplicate" class="text-sm"><Link :href="`/licitaciones/${review.duplicate.id}`" :class="tableLink">Ver la licitación existente</Link></p>
                    <Checkbox v-if="can.createOpportunity" v-model="form.create_opportunity" label="Crear oportunidad en el pipeline" description="Se agrega en la etapa Prospecto, enlazada a esta licitación (si aún no tiene una)." />
                    <p v-if="form.errors.reference && review.duplicate" class="text-xs text-cap-status-error dark:text-cap-status-dark-error" role="alert">{{ form.errors.reference }}</p>
                </section>

                <details v-if="review.ocrText" class="rounded-cap-control bg-cap-card-2 p-3 text-sm">
                    <summary class="cursor-pointer text-gray-900 dark:text-gray-100">Texto leído por el OCR</summary>
                    <pre class="mt-2 max-h-64 overflow-auto whitespace-pre-wrap text-xs text-cap-text-soft">{{ review.ocrText }}</pre>
                </details>

                <div class="flex flex-col-reverse gap-2 border-t border-cap-divider pt-4 sm:flex-row sm:justify-end">
                    <AppButton href="/licitaciones" :disabled="form.processing">Cancelar</AppButton>
                    <AppButton variant="primary" type="submit" icon="check" :loading="form.processing">{{ review.duplicate && form.update_existing ? 'Confirmar y actualizar' : 'Confirmar e importar' }}</AppButton>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
