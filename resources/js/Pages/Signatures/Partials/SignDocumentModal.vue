<script setup>
// Modal para colocar y aplicar MI firma y MI sello sobre un PDF. Uso y contrato en ./README.md.
// Props: open (v-model:open), document ({ id, name, previewUrl } de un documento del sistema, o
//        { id: null, name, previewUrl (URL local del archivo), file (File), saveToDocuments, documentName }
//        para un PDF suelto).
// Eventos: signed (documento actualizado o null si es un PDF suelto, respuesta completa), update:open.
import { computed, nextTick, reactive, ref, watch } from 'vue';
import CapAlert from '@design/vue/CapAlert.vue';
import { useToasts } from '@design/vue/composables/useToasts.js';
import AppButton from '@/Components/AppButton.vue';
import BaseModal from '@/Components/BaseModal.vue';
import Checkbox from '@/Components/Checkbox.vue';
import FormField from '@/Components/FormField.vue';
import PdfViewer from '@/Components/PdfViewer.vue';
import SelectInput from '@/Components/SelectInput.vue';
import Toggle from '@/Components/Toggle.vue';
import { useConfirm } from '@/composables/useConfirm.js';
import { dateTime } from '@/lib/format.js';
import { fetchState, signDocument, signFile } from '@/Pages/Signatures/Partials/api.js';
import AssetCard from '@/Pages/Signatures/Partials/AssetCard.vue';
import PlacementBox from '@/Pages/Signatures/Partials/PlacementBox.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    document: { type: Object, default: null },
});

const emit = defineEmits(['update:open', 'signed']);

const DEFAULTS = {
    firma: { x: 56, y: 78, width: 28 },
    sello: { x: 14, y: 72, width: 16 },
};

const confirm = useConfirm();
const toasts = useToasts();
const viewer = ref(null);
const stage = ref(null);
const status = ref('idle'); // idle | loading | ready | error
const loadError = ref('');
const assets = ref({ firma: null, sello: null });
const canSign = ref(true);
const company = ref({ name: '', tax_id: '' });
const alreadySigned = ref(null);
const pageCount = ref(0);
const targetPage = ref(1);
const allPages = ref(false);
const use = reactive({ firma: false, sello: false });
const placements = reactive({ firma: { ...DEFAULTS.firma }, sello: { ...DEFAULTS.sello } });
const pageSizes = reactive({}); // número => { width, height } en px de la hoja pintada
const submitting = ref(false);
const error = ref('');
let lastPlacements = [];

const isLoose = computed(() => Boolean(props.document?.file));
const missing = computed(() => ['firma', 'sello'].filter((type) => !assets.value[type]));
const chosen = computed(() => ['firma', 'sello'].filter((type) => use[type] && assets.value[type]));
const pageOptions = computed(() => Array.from({ length: pageCount.value }, (_, index) => ({ value: index + 1, label: `Página ${index + 1}${index + 1 === pageCount.value ? ' (última)' : ''}` })));
const canSubmit = computed(() => status.value === 'ready' && canSign.value && chosen.value.length > 0 && pageCount.value > 0 && !submitting.value);

function ratioOf(type) {
    const asset = assets.value[type];
    return asset?.width && asset?.height ? asset.height / asset.width : 0.33;
}

/** Alto en % de la página objetivo, para enviarlo al servidor. */
function heightOf(type) {
    const size = pageSizes[targetPage.value];
    if (!size) return placements[type].width * ratioOf(type);
    return (placements[type].width * size.width * ratioOf(type)) / size.height;
}

async function loadState() {
    status.value = 'loading';
    loadError.value = '';
    try {
        const state = await fetchState(isLoose.value ? null : props.document?.id);
        applyAssets(state.assets);
        canSign.value = state.can_sign;
        company.value = state.company ?? company.value;
        alreadySigned.value = state.document?.already_signed ? state.document : null;
        lastPlacements = state.last_placements ?? [];
        lastPlacements.forEach((placement) => {
            if (placements[placement.type]) placements[placement.type] = { x: Number(placement.x), y: Number(placement.y), width: Number(placement.width) };
        });
        status.value = 'ready';
    } catch (exception) {
        loadError.value = exception.message;
        status.value = 'error';
    }
}

function applyAssets(next) {
    const before = { ...assets.value };
    assets.value = { firma: next?.firma ?? null, sello: next?.sello ?? null };
    // Un activo recién subido se marca solo; uno que no existe nunca queda marcado.
    ['firma', 'sello'].forEach((type) => {
        if (assets.value[type] && !before[type]) use[type] = true;
        if (!assets.value[type]) use[type] = false;
    });
}

async function refreshAssets() {
    try {
        const state = await fetchState(null);
        applyAssets(state.assets);
    } catch (exception) {
        error.value = exception.message;
    }
}

function reset() {
    error.value = '';
    pageCount.value = 0;
    allPages.value = false;
    use.firma = false;
    use.sello = false;
    assets.value = { firma: null, sello: null };
    placements.firma = { ...DEFAULTS.firma };
    placements.sello = { ...DEFAULTS.sello };
    Object.keys(pageSizes).forEach((key) => delete pageSizes[key]);
}

watch(
    () => [props.open, props.document?.id, props.document?.previewUrl],
    ([open]) => {
        if (!open) return;
        reset();
        loadState();
    },
    { immediate: true },
);

function onLoaded({ pages }) {
    pageCount.value = pages;
    targetPage.value = pages;
    setTimeout(() => viewer.value?.goToPage(pages), 50);
}

function onPageRendered({ page, width, height }) {
    const first = !pageSizes[page];
    pageSizes[page] = { width, height };
    if (first && page === targetPage.value) revealPlacement();
}

/** Lleva la firma (o el sello) al centro del visor para que se vea sin buscarla. */
function revealPlacement() {
    nextTick(() => {
        // `PdfViewer.goToPage` usa `scrollIntoView` y en móvil también desplaza el cuerpo de la modal:
        // se devuelve arriba para que la barra del visor siga a la vista.
        const body = stage.value?.parentElement?.closest('.overflow-y-auto');
        if (body) body.scrollTop = 0;
        const box = stage.value?.querySelector(`.app-pdf-page[data-page="${targetPage.value}"] [data-placement]`);
        const scroller = box?.closest('.overflow-auto');
        if (!box || !scroller) return;
        // Solo se desplaza el visor: `scrollIntoView` movería también la página de fondo.
        const area = scroller.getBoundingClientRect();
        const rect = box.getBoundingClientRect();
        scroller.scrollTop += rect.top - area.top - (area.height - rect.height) / 2;
    });
}

watch(targetPage, (page) => {
    viewer.value?.goToPage(page);
    if (pageSizes[page]) revealPlacement();
});

function payload(confirmed) {
    return {
        use_signature: use.firma && Boolean(assets.value.firma),
        use_seal: use.sello && Boolean(assets.value.sello),
        all_pages: allPages.value,
        confirm: confirmed,
        placements: chosen.value.map((type) => ({
            type,
            page: targetPage.value,
            x: placements[type].x,
            y: placements[type].y,
            width: placements[type].width,
            height: Math.round(heightOf(type) * 1000) / 1000,
        })),
    };
}

async function submit(confirmed = false) {
    if (!canSubmit.value && !confirmed) return;
    if (alreadySigned.value && !confirmed) {
        const accepted = await confirm({
            verb: 'Firmar de nuevo',
            object: `«${props.document.name}»`,
            title: `¿Firmar de nuevo «${props.document.name}»?`,
            confirmLabel: 'Firmar de nuevo',
            icon: 'draw-pen',
            message: alreadySigned.value.last_signed_at ? `Ya lo firmaste el ${dateTime(alreadySigned.value.last_signed_at)}; se creará otra versión firmada y la anterior se conserva.` : `Ya lo firmaste; se creará otra versión firmada y la anterior se conserva.`,
        });
        if (!accepted) return;
        confirmed = true;
    }
    submitting.value = true;
    error.value = '';
    try {
        const body = payload(confirmed);
        const response = isLoose.value
            ? await signFile(props.document.file, { ...body, save_to_documents: Boolean(props.document.saveToDocuments), document_name: props.document.documentName || null })
            : await signDocument({ ...body, document_id: props.document.id });
        toasts.show({ status: 'ok', title: 'Documento firmado', detail: response.message });
        emit('signed', response.document ?? null, response);
        emit('update:open', false);
    } catch (exception) {
        if (exception.status === 409) {
            alreadySigned.value = { already_signed: true, last_signed_at: null };
            submitting.value = false;
            return submit(false);
        }
        const fieldErrors = Object.values(exception.errors ?? {}).flat();
        error.value = fieldErrors[0] || exception.message;
    } finally {
        submitting.value = false;
    }
}

function close() {
    if (!submitting.value) emit('update:open', false);
}
</script>

<template>
    <BaseModal :open="open" size="full" flush :persistent="submitting" :title="document ? `Firmar «${document.name}»` : 'Firmar documento'" @update:open="(value) => (value ? null : close())">
        <div class="flex min-h-full flex-col lg:h-full lg:flex-row">
            <div ref="stage" class="min-w-0 border-cap-divider max-lg:border-b lg:flex-1 lg:border-r">
                <PdfViewer
                    v-if="document?.previewUrl"
                    ref="viewer"
                    :src="document.previewUrl"
                    :name="document.name"
                    :download-url="''"
                    height="h-[58vh] lg:h-full"
                    @loaded="onLoaded"
                    @page-rendered="onPageRendered"
                    @error="(message) => (error = message)"
                >
                    <template #page-overlay="{ page, width, height }">
                        <template v-for="type in chosen" :key="type">
                            <PlacementBox
                                v-if="page === targetPage || allPages"
                                v-model="placements[type]"
                                :page-width="width"
                                :page-height="height"
                                :ratio="ratioOf(type)"
                                :src="assets[type].url"
                                :label="type === 'firma' ? 'Firma' : 'Sello'"
                                :ghost="page !== targetPage"
                            />
                        </template>
                    </template>
                </PdfViewer>
            </div>

            <aside class="flex w-full shrink-0 flex-col gap-5 p-4 md:p-6 lg:w-96 lg:overflow-y-auto" aria-label="Opciones de firma">
                <div v-if="status === 'loading'" class="flex flex-col gap-3" role="status" aria-label="Cargando tu firma y sello">
                    <span class="cap-skeleton block w-2/3" /><span class="cap-skeleton block" /><span class="cap-skeleton block w-1/2" />
                </div>

                <template v-else-if="status === 'error'">
                    <CapAlert status="error" title="No se pudo cargar tu firma y sello" :detail="loadError" />
                    <AppButton icon="refresh" @click="loadState">Reintentar</AppButton>
                </template>

                <template v-else-if="status === 'ready'">
                    <CapAlert v-if="!canSign" status="warning" title="Tu rol no permite firmar" detail="En la empresa activa tu acceso es de solo lectura. Puedes preparar tu firma y sello." />
                    <CapAlert v-if="alreadySigned" status="info" title="Ya firmaste este documento" :detail="alreadySigned.last_signed_at ? `Lo firmaste el ${dateTime(alreadySigned.last_signed_at)}; si vuelves a firmar se creará otra versión.` : 'Si vuelves a firmar se creará otra versión.'" />

                    <fieldset class="flex flex-col gap-3">
                        <legend class="mb-2 text-sm font-semibold text-gray-900 dark:text-gray-100">Qué aplicar</legend>
                        <Checkbox v-model="use.firma" :disabled="!assets.firma" label="Firma" :description="assets.firma ? 'Arrástrala sobre la página y ajusta su tamaño desde la esquina.' : 'Aún no tienes firma: créala abajo.'" />
                        <Checkbox v-model="use.sello" :disabled="!assets.sello" label="Sello" :description="assets.sello ? 'Se coloca y se ajusta igual que la firma.' : 'Aún no tienes sello: créalo abajo.'" />
                    </fieldset>

                    <div v-if="missing.length" class="flex flex-col gap-3">
                        <p class="text-sm text-cap-text-soft">Créalos aquí mismo (dibujados, escritos o subidos); no pierdes lo que estás firmando.</p>
                        <AssetCard v-for="type in missing" :key="type" :type="type" :asset="null" :company="company" compact @saved="refreshAssets" />
                    </div>

                    <FormField label="Página" help="Por defecto, la última.">
                        <SelectInput v-model.number="targetPage" :options="pageOptions" placeholder="" :disabled="!pageCount" />
                    </FormField>
                    <Toggle v-model="allPages" label="Aplicar en todas las páginas" description="Misma posición en cada página." />

                    <p class="text-xs text-cap-text-soft">
                        Con el teclado: enfoca la firma o el sello y muévelos con las flechas (Mayús para pasos largos); «+» y «−» cambian el tamaño.
                    </p>

                    <CapAlert v-if="error" status="error" title="No se pudo firmar" :detail="error" />
                </template>
            </aside>
        </div>

        <template #footer>
            <AppButton :disabled="submitting" @click="close">Cancelar</AppButton>
            <AppButton variant="primary" icon="draw-pen" :loading="submitting" :disabled="!canSubmit" @click="submit(false)">
                {{ chosen.length === 2 ? 'Firmar y sellar' : chosen[0] === 'sello' ? 'Sellar' : 'Firmar' }}
            </AppButton>
        </template>
    </BaseModal>
</template>
