<script setup>
// Modal «Crear firma» / «Crear sello»: dibujar, escribir con una fuente manuscrita, generar un sello
// o subir una imagen. Todo termina en un PNG transparente recortado que se guarda por el mismo
// endpoint de activos (`POST /firmas/activos`, PNG o JPG de hasta 2 MB).
//
//   <AssetCreatorModal v-model:open="open" type="firma" :company="{ name, tax_id }" @saved="..." />
//
// Props: open, type ('firma' | 'sello'), tab (pestaña inicial: draw | type | stamp | upload),
//        company ({ name, tax_id } de la empresa activa, para el sello), maxSizeMb.
// Eventos: update:open, saved (tipo guardado).
import { usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import CapAlert from '../../../design/vue/CapAlert.vue';
import CapSegmented from '../../../design/vue/CapSegmented.vue';
import CapSkeleton from '../../../design/vue/CapSkeleton.vue';
import AppButton from '../../Components/AppButton.vue';
import BaseModal from '../../Components/BaseModal.vue';
import Checkbox from '../../Components/Checkbox.vue';
import FileDropzone from '../../Components/FileDropzone.vue';
import FormField from '../../Components/FormField.vue';
import Tabs from '../../Components/Tabs.vue';
import TextInput from '../../Components/TextInput.vue';
import { useUpload } from '../../composables/useUpload.js';
import { endpoints } from './api.js';
import { canvasToFile, fitCanvas, HANDWRITING_FONTS, INKS, inkColor, loadHandwritingFonts, loadImage, removeLightBackground, STAMP_INKS, trimCanvas } from './ink.js';
import { renderStamp, renderTyped } from './renderers.js';
import SignaturePad from './SignaturePad.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    type: { type: String, default: 'firma' },
    tab: { type: String, default: '' },
    company: { type: Object, default: () => ({}) },
    maxSizeMb: { type: Number, default: 2 },
});

const emit = defineEmits(['update:open', 'saved']);

const page = usePage();
const uploader = useUpload();
const pad = ref(null);
const active = ref('draw');
const strokeCount = ref(0);
const fonts = ref('idle'); // idle | loading | ready | error
const previewUrl = ref('');
const previewSize = ref(null);
const previewMode = ref('paper');
const building = ref(false);
const failure = ref('');
const landscape = ref(false);
let previewTimer = 0;
let landscapeQuery = null;

const draw = reactive({ ink: 'azul', weight: 1 });
const typed = reactive({ text: '', font: HANDWRITING_FONTS[0].value, ink: 'azul', size: 100, slant: 8 });
const stamp = reactive({ shape: 'circle', company: '', taxId: '', text: '', ink: 'azul' });
const upload = reactive({ files: [], removeBackground: true, image: null });

const isSeal = computed(() => props.type === 'sello');
const noun = computed(() => (isSeal.value ? 'sello' : 'firma'));
const tabs = computed(() =>
    isSeal.value
        ? [
              { value: 'stamp', label: 'Generar', icon: 'stamper' },
              { value: 'upload', label: 'Subir imagen', icon: 'image-outline' },
          ]
        : [
              { value: 'draw', label: 'Dibujar', icon: 'draw' },
              { value: 'type', label: 'Escribir', icon: 'format-text' },
              { value: 'upload', label: 'Subir imagen', icon: 'image-outline' },
          ],
);
const weights = [
    { label: 'Fino', value: 0.7 },
    { label: 'Medio', value: 1 },
    { label: 'Grueso', value: 1.45 },
];
const shapes = [
    { label: 'Circular', value: 'circle' },
    { label: 'Rectangular', value: 'rect' },
];
const previewModes = [
    { label: 'Sobre papel', value: 'paper' },
    { label: 'Sobre un documento', value: 'document' },
];
const visible = computed({ get: () => props.open, set: (value) => emit('update:open', value) });
const saving = computed(() => uploader.uploading.value || building.value);
const serverError = computed(() => Object.values(uploader.errors.value ?? {}).flat()[0] ?? '');
const ready = computed(() => {
    if (active.value === 'draw') return strokeCount.value > 0;
    if (active.value === 'type') return fonts.value === 'ready' && typed.text.trim().length > 0;
    if (active.value === 'stamp') return Boolean(stamp.company.trim() || stamp.text.trim());
    return Boolean(upload.image);
});

function reset() {
    failure.value = '';
    uploader.reset();
    strokeCount.value = 0;
    pad.value?.clear();
    upload.files = [];
    upload.image = null;
    typed.text = page.props.auth?.user?.name ?? '';
    stamp.company = props.company?.name ?? '';
    stamp.taxId = props.company?.tax_id ?? '';
    stamp.text = '';
    active.value = tabs.value.some((item) => item.value === props.tab) ? props.tab : tabs.value[0].value;
    setPreview(null);
}

async function prepareFonts() {
    if (isSeal.value || fonts.value === 'ready' || fonts.value === 'loading') return;
    fonts.value = 'loading';
    try {
        await loadHandwritingFonts();
        fonts.value = 'ready';
        schedulePreview();
    } catch {
        fonts.value = 'error';
    }
}

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        reset();
        prepareFonts();
        nextTick(schedulePreview);
    },
);

/** Lienzo final de la pestaña activa (transparente y recortado) o null si aún no hay nada. */
async function currentCanvas() {
    if (active.value === 'draw') return pad.value?.toCanvas() ?? null;
    if (active.value === 'type') return fonts.value === 'ready' ? renderTyped({ ...typed, color: inkColor(typed.ink) }) : null;
    if (active.value === 'stamp') return renderStamp({ ...stamp, color: inkColor(stamp.ink, STAMP_INKS) });
    if (!upload.image) return null;
    if (upload.removeBackground) return trimCanvas(removeLightBackground(upload.image), 10);
    const canvas = document.createElement('canvas');
    canvas.width = upload.image.naturalWidth;
    canvas.height = upload.image.naturalHeight;
    canvas.getContext('2d').drawImage(upload.image, 0, 0);
    return trimCanvas(canvas, 10) ?? canvas;
}

function setPreview(canvas) {
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
    previewUrl.value = '';
    previewSize.value = null;
    if (!canvas) return;
    previewSize.value = { width: canvas.width, height: canvas.height };
    canvas.toBlob((blob) => {
        if (blob) previewUrl.value = URL.createObjectURL(blob);
    }, 'image/png');
}

function schedulePreview() {
    window.clearTimeout(previewTimer);
    previewTimer = window.setTimeout(async () => {
        if (!props.open) return;
        try {
            setPreview(await currentCanvas());
        } catch {
            setPreview(null);
        }
    }, 160);
}

watch([active, () => ({ ...typed }), () => ({ ...stamp }), () => upload.removeBackground, () => upload.image, () => draw.ink, () => draw.weight], schedulePreview, { deep: true });

watch(
    () => upload.files,
    async (files) => {
        failure.value = '';
        if (!files.length) {
            upload.image = null;
            return;
        }
        try {
            upload.image = await loadImage(files[0]);
            upload.removeBackground = !/\.png$/i.test(files[0].name);
        } catch (error) {
            failure.value = error.message;
            upload.image = null;
        }
    },
);

function onStrokes(count) {
    strokeCount.value = count;
    schedulePreview();
}

const FILE_NAMES = { draw: 'firma-dibujada.png', type: 'firma-escrita.png', stamp: 'sello-generado.png' };

async function save() {
    if (!ready.value || saving.value) return;
    failure.value = '';
    building.value = true;
    let file = null;
    try {
        const canvas = await currentCanvas();
        if (!canvas) throw new Error(`Aún no hay ${noun.value} para guardar.`);
        const base = upload.files[0]?.name?.replace(/\.[a-z0-9]+$/i, '') || noun.value;
        file = await canvasToFile(fitCanvas(canvas, 2400), FILE_NAMES[active.value] ?? `${base}.png`);
        if (file.size > props.maxSizeMb * 1024 * 1024) throw new Error(`La imagen pesa más de ${props.maxSizeMb} MB. Prueba con una foto más pequeña.`);
    } catch (error) {
        failure.value = error.message;
        return;
    } finally {
        building.value = false;
    }
    const done = await uploader.upload(endpoints.assets, [file], { field: 'file', data: { type: props.type }, only: ['assets', 'flash'] });
    if (done) {
        emit('saved', props.type);
        visible.value = false;
    }
}

function close() {
    if (!saving.value) visible.value = false;
}

// Teléfono en horizontal: la modal ocupa toda la pantalla y el lienzo usa todo el alto disponible.
function onOrientation() {
    landscape.value = Boolean(landscapeQuery?.matches);
}

onMounted(() => {
    landscapeQuery = window.matchMedia('(orientation: landscape) and (max-height: 520px)');
    onOrientation();
    landscapeQuery.addEventListener?.('change', onOrientation);
});

onBeforeUnmount(() => {
    landscapeQuery?.removeEventListener?.('change', onOrientation);
    window.clearTimeout(previewTimer);
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
});

const swatch = 'relative inline-flex h-9 w-9 items-center justify-center rounded-full border-2 transition-transform duration-cap-fast ease-cap focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome focus-visible:ring-offset-2 focus-visible:ring-offset-cap-card dark:focus-visible:ring-gray-100';
const rangeClass = 'h-2 w-full cursor-pointer accent-cap-brand';
</script>

<template>
    <BaseModal
        v-model:open="visible"
        :size="landscape ? 'full' : 'xl'"
        :persistent="saving"
        :title="isSeal ? 'Crear mi sello' : 'Crear mi firma'"
        :description="isSeal ? 'Genera un sello con los datos de la empresa o sube la imagen del tuyo.' : 'Dibújala, escríbela con un estilo manuscrito o sube una foto.'"
        @update:open="(value) => (value ? null : close())"
    >
        <div class="grid min-w-0 grid-cols-1 gap-6" :class="landscape ? '' : 'lg:grid-cols-[minmax(0,1fr)_300px]'">
            <div class="flex min-w-0 flex-col gap-4">
                <Tabs v-model="active" :tabs="tabs" :label="`Forma de crear ${noun === 'firma' ? 'la firma' : 'el sello'}`" />

                <!-- Dibujar: se usa v-show para no perder el trazo al cambiar de pestaña. -->
                <section v-if="!isSeal" v-show="active === 'draw'" class="flex flex-col gap-3" aria-label="Dibujar la firma" data-panel="draw">
                    <div :style="landscape ? { width: 'min(100%, calc((100dvh - 190px) * 2.5))' } : null" class="mx-auto w-full">
                        <SignaturePad ref="pad" :color="inkColor(draw.ink)" :weight="draw.weight" @change="onStrokes" />
                    </div>
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-3">
                        <div class="flex items-center gap-2" role="radiogroup" aria-label="Color de la tinta">
                            <button
                                v-for="ink in INKS"
                                :key="ink.value"
                                type="button"
                                role="radio"
                                :aria-checked="draw.ink === ink.value"
                                :aria-label="ink.label"
                                :title="ink.label"
                                :class="[swatch, draw.ink === ink.value ? 'scale-110 border-cap-chrome dark:border-gray-100' : 'border-cap-divider']"
                                @click="draw.ink = ink.value"
                            >
                                <span class="h-6 w-6 rounded-full" :style="{ background: ink.color }" />
                            </button>
                        </div>
                        <CapSegmented v-model="draw.weight" :items="weights" label="Grosor del trazo" />
                        <div class="ml-auto flex items-center gap-2">
                            <AppButton icon="undo" :disabled="!strokeCount" @click="pad?.undo()">Deshacer</AppButton>
                            <AppButton icon="eraser" :disabled="!strokeCount" @click="pad?.clear()">Limpiar</AppButton>
                        </div>
                    </div>
                    <p class="text-xs text-cap-text-soft">
                        Usa el ratón, el lápiz o el dedo. El trazo se adelgaza cuando vas rápido, como con tinta.
                        <span class="sm:hidden">Gira el teléfono para tener más espacio.</span>
                        Si prefieres no dibujar, usa «Escribir».
                    </p>
                </section>

                <section v-if="active === 'type'" class="flex flex-col gap-4" aria-label="Escribir la firma" data-panel="type">
                    <FormField label="Tu nombre como firmas" required>
                        <TextInput v-model="typed.text" maxlength="60" placeholder="Nombre y apellido" />
                    </FormField>
                    <CapSkeleton v-if="fonts === 'loading'" variant="line" :rows="3" />
                    <div v-else-if="fonts === 'error'" class="flex flex-col items-start gap-3">
                        <CapAlert status="error" title="No se pudieron cargar los estilos" detail="Revisa tu conexión y vuelve a intentarlo." />
                        <AppButton icon="refresh" @click="prepareFonts">Reintentar</AppButton>
                    </div>
                    <fieldset v-else>
                        <legend class="mb-2 text-sm font-medium text-gray-900 dark:text-gray-100">Estilo</legend>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-3" role="radiogroup" aria-label="Estilo manuscrito">
                            <button
                                v-for="font in HANDWRITING_FONTS"
                                :key="font.value"
                                type="button"
                                role="radio"
                                :aria-checked="typed.font === font.value"
                                class="flex min-w-0 flex-col items-start gap-1 rounded-cap-control border bg-white px-3 py-2 text-left transition-colors duration-cap-fast ease-cap focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome dark:bg-white dark:focus-visible:ring-gray-100"
                                :class="typed.font === font.value ? 'border-cap-chrome ring-1 ring-cap-chrome dark:border-gray-100 dark:ring-gray-100' : 'border-cap-divider hover:border-gray-400 dark:hover:border-gray-400'"
                                :data-font="font.value"
                                @click="typed.font = font.value"
                            >
                                <span class="block w-full truncate text-3xl leading-tight" :style="{ fontFamily: `'${font.family}', cursive`, color: inkColor(typed.ink), fontSize: `${1.9 * font.scale}rem` }">{{ typed.text || 'Tu firma' }}</span>
                                <span class="text-xs text-gray-600 dark:text-gray-600">{{ font.label }}</span>
                            </button>
                        </div>
                    </fieldset>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <FormField label="Tinta">
                            <div class="flex items-center gap-2" role="radiogroup" aria-label="Color de la tinta">
                                <button v-for="ink in INKS" :key="ink.value" type="button" role="radio" :aria-checked="typed.ink === ink.value" :aria-label="ink.label" :title="ink.label" :class="[swatch, typed.ink === ink.value ? 'scale-110 border-cap-chrome dark:border-gray-100' : 'border-cap-divider']" @click="typed.ink = ink.value">
                                    <span class="h-6 w-6 rounded-full" :style="{ background: ink.color }" />
                                </button>
                            </div>
                        </FormField>
                        <FormField :label="`Tamaño · ${typed.size} %`">
                            <input v-model.number="typed.size" type="range" min="70" max="130" step="5" :class="rangeClass" aria-label="Tamaño">
                        </FormField>
                        <FormField :label="`Inclinación · ${typed.slant}°`">
                            <input v-model.number="typed.slant" type="range" min="-10" max="25" step="1" :class="rangeClass" aria-label="Inclinación">
                        </FormField>
                    </div>
                    <p class="text-xs text-cap-text-soft">Fuentes de licencia libre (OFL) incluidas en el sistema.</p>
                </section>

                <section v-if="active === 'stamp'" class="flex flex-col gap-4" aria-label="Generar el sello" data-panel="stamp">
                    <div class="flex flex-wrap items-center gap-3">
                        <CapSegmented v-model="stamp.shape" :items="shapes" label="Forma del sello" />
                    </div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <FormField label="Empresa" required help="Sale de la empresa activa; puedes ajustarlo.">
                            <TextInput v-model="stamp.company" maxlength="90" />
                        </FormField>
                        <FormField label="RTN" optional>
                            <TextInput v-model="stamp.taxId" maxlength="20" placeholder="08019000000000" />
                        </FormField>
                        <FormField label="Leyenda" optional help="Cargo o área: «Gerencia General», «Ventas»…" class="sm:col-span-2">
                            <TextInput v-model="stamp.text" maxlength="40" placeholder="Gerencia General" />
                        </FormField>
                    </div>
                    <FormField label="Tinta">
                        <div class="flex flex-wrap items-center gap-2" role="radiogroup" aria-label="Color de la tinta del sello">
                            <button v-for="ink in STAMP_INKS" :key="ink.value" type="button" role="radio" :aria-checked="stamp.ink === ink.value" :aria-label="ink.label" :title="ink.label" :class="[swatch, stamp.ink === ink.value ? 'scale-110 border-cap-chrome dark:border-gray-100' : 'border-cap-divider']" @click="stamp.ink = ink.value">
                                <span class="h-6 w-6 rounded-full" :style="{ background: ink.color }" />
                            </button>
                        </div>
                    </FormField>
                </section>

                <section v-if="active === 'upload'" class="flex flex-col gap-4" aria-label="Subir una imagen" data-panel="upload">
                    <FileDropzone v-model="upload.files" accept=".png,.jpg,.jpeg" :max-size-mb="10" :title="`Arrastra la imagen de tu ${noun} o elígela`" hint="PNG transparente o una foto sobre papel blanco." />
                    <Checkbox v-model="upload.removeBackground" :disabled="!upload.image" label="Quitar el fondo blanco" description="Para fotos sobre papel: deja solo la tinta y el fondo transparente." />
                    <p class="text-xs text-cap-text-soft">Se recorta el espacio vacío y se guarda como PNG de hasta {{ maxSizeMb }} MB.</p>
                </section>

                <CapAlert v-if="failure || serverError" status="error" :title="`No se pudo guardar ${noun === 'firma' ? 'la firma' : 'el sello'}`" :detail="failure || serverError" />
            </div>

            <aside class="flex min-w-0 flex-col gap-3" :class="landscape ? 'hidden' : ''" aria-label="Vista previa">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="font-display text-sm font-semibold text-gray-900 dark:text-gray-100">Vista previa</h3>
                    <span v-if="previewSize" class="text-xs tabular-nums text-cap-text-soft">{{ previewSize.width }} × {{ previewSize.height }} px</span>
                </div>
                <CapSegmented v-model="previewMode" :items="previewModes" label="Fondo de la vista previa" />

                <div v-if="previewMode === 'paper'" class="flex h-48 items-center justify-center rounded-cap-control border border-cap-divider bg-white p-4 shadow-sm dark:bg-white" data-preview="paper">
                    <div class="relative flex h-full w-full flex-col items-center justify-end">
                        <img v-if="previewUrl" :src="previewUrl" :alt="`Vista previa de tu ${noun}`" class="relative z-10 max-h-[78%] max-w-full object-contain" :class="isSeal ? '' : '-mb-3'" draggable="false">
                        <span v-else class="mb-auto mt-auto text-sm text-gray-500 dark:text-gray-500">Aquí verás tu {{ noun }}</span>
                        <span class="w-4/5 border-t border-gray-400 dark:border-gray-400" aria-hidden="true" />
                        <span class="mt-1 text-xs text-gray-600 dark:text-gray-600">{{ isSeal ? 'Sello de la empresa' : (page.props.auth?.user?.name ?? 'Firma') }}</span>
                    </div>
                </div>

                <!-- Hoja de ejemplo: muestra el tamaño relativo de la firma sobre un documento. -->
                <div v-else class="mx-auto w-full max-w-[17rem] rounded-sm border border-cap-divider bg-white p-5 shadow-md dark:bg-white" data-preview="document" aria-label="Documento de ejemplo">
                    <div class="mb-3 h-2 w-1/2 rounded-full bg-gray-300 dark:bg-gray-300" />
                    <div class="flex flex-col gap-1.5">
                        <span v-for="n in 9" :key="n" class="h-1.5 rounded-full bg-gray-200 dark:bg-gray-200" :class="n % 4 === 0 ? 'w-2/3' : 'w-full'" />
                    </div>
                    <div class="mt-6 flex items-end justify-between gap-2">
                        <div class="flex w-3/5 flex-col items-center">
                            <img v-if="previewUrl && !isSeal" :src="previewUrl" alt="" class="-mb-1.5 h-10 max-w-full object-contain" draggable="false">
                            <span v-else class="h-10" />
                            <span class="w-full border-t border-gray-400 dark:border-gray-400" />
                            <span class="mt-1 h-1 w-2/3 rounded-full bg-gray-200 dark:bg-gray-200" />
                        </div>
                        <img v-if="previewUrl && isSeal" :src="previewUrl" alt="" class="h-16 w-16 -rotate-6 object-contain opacity-90" draggable="false">
                        <span v-else class="h-14 w-14 rounded-full border border-dashed border-gray-300 dark:border-gray-300" aria-hidden="true" />
                    </div>
                </div>
                <p class="text-xs text-cap-text-soft">Fondo transparente: al firmar se ve solo la tinta. Luego la colocas y ajustas sobre cada documento.</p>
            </aside>
        </div>

        <template #footer>
            <AppButton :disabled="saving" @click="close">Cancelar</AppButton>
            <AppButton variant="primary" icon="content-save-outline" :loading="saving" :disabled="!ready" data-save-asset @click="save">
                {{ isSeal ? 'Guardar sello' : 'Guardar firma' }}
            </AppButton>
        </template>
    </BaseModal>
</template>
