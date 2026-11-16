<script setup>
// Visor de un PDF o de una imagen servidos por una ruta autorizada (misma sesión, con cookies).
// `pdfjs-dist` solo se descarga cuando el visor abre un PDF.
// Props: src (URL), name (nombre del archivo: título accesible y nombre de la descarga),
//        downloadUrl (por defecto `src`; '' oculta «Descargar»), height (clase de alto: 'h-[70vh]';
//        dentro de una `BaseModal` con `flush`, 'h-[75vh]' o 'h-full'), zoom inicial ('fit' o un número: 1 = 100 %).
// Slots: page-overlay ({ page, width, height, scale, pdfWidth, pdfHeight }) — capa absoluta sobre
//        cada página, del mismo tamaño que la hoja pintada: ahí se coloca la firma o el sello
//        arrastrable; las coordenadas en puntos PDF salen de dividir entre `scale`.
//        toolbar ({ page, pages }) — acciones extra a la derecha de la barra.
// Eventos: loaded ({ pages, kind: 'pdf' | 'image' }), page-rendered ({ page, width, height, scale,
//          pdfWidth, pdfHeight, element }), page-change (número), error (mensaje).
// Expone: goToPage(n), zoomIn(), zoomOut(), fitWidth(), reload().
// Teclado: la zona de páginas es enfocable y se desplaza con las flechas; los botones de la barra
// cambian de página y de zoom.
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { httpErrorMessage } from '@/lib/errors.js';
import { EASE, play } from '@/lib/motion/index.js';
import { iconButton, inputClass } from '@/lib/ui.js';
import ErrorState from '@/Components/ErrorState.vue';

const props = defineProps({
    src: { type: String, default: '' },
    name: { type: String, default: 'documento' },
    downloadUrl: { type: String, default: null },
    height: { type: String, default: 'h-[70vh]' },
    zoom: { type: [String, Number], default: 'fit' },
});

const emit = defineEmits(['loaded', 'page-rendered', 'page-change', 'error']);

const MIN_SCALE = 0.25;
const MAX_SCALE = 4;
const ZOOM_STEP = 1.2;
const GUTTER = 32;
const MAX_PIXEL_RATIO = 2;
const MAX_FIT_SCALE = 1.5;

const scroller = ref(null);
const status = ref('idle'); // idle | loading | ready | error
const errorMessage = ref('');
const kind = ref('pdf');
const pages = ref([]); // [{ number, pdfWidth, pdfHeight }]
const scale = ref(1);
const fitMode = ref(props.zoom === 'fit');
const currentPage = ref(1);
const imageUrl = ref('');
const pdfDocument = shallowRef(null);
const pageElements = new Map();
const canvases = new Map();
const renderedScale = new Map();
const renderTasks = new Map();
let loadToken = 0;
let loadingTask = null;
let intersection = null;
let resizeObserver = null;
let scrollFrame = null;

const pageCount = computed(() => pages.value.length);
const zoomLabel = computed(() => `${Math.round(scale.value * 100)} %`);
const download = computed(() => (props.downloadUrl === null ? props.src : props.downloadUrl));

function pageInfo(page) {
    return {
        page: page.number,
        width: Math.round(page.pdfWidth * scale.value),
        height: Math.round(page.pdfHeight * scale.value),
        scale: scale.value,
        pdfWidth: page.pdfWidth,
        pdfHeight: page.pdfHeight,
    };
}

function fail(message) {
    status.value = 'error';
    errorMessage.value = message;
    emit('error', message);
}

function cleanup() {
    renderTasks.forEach((task) => task.cancel());
    renderTasks.clear();
    renderedScale.clear();
    intersection?.disconnect();
    // Destruir la tarea de carga libera también el documento y su worker.
    loadingTask?.destroy();
    loadingTask = null;
    pdfDocument.value = null;
    if (imageUrl.value) URL.revokeObjectURL(imageUrl.value);
    imageUrl.value = '';
    pages.value = [];
}

async function load() {
    const token = ++loadToken;
    cleanup();
    if (!props.src) return (status.value = 'idle');
    status.value = 'loading';
    currentPage.value = 1;
    try {
        const response = await fetch(props.src, { credentials: 'same-origin', headers: { Accept: 'application/pdf,image/*' } });
        if (token !== loadToken) return;
        if (!response.ok) return fail(httpErrorMessage(response.status));
        const blob = await response.blob();
        if (token !== loadToken) return;

        if (blob.type.startsWith('image/')) {
            kind.value = 'image';
            imageUrl.value = URL.createObjectURL(blob);
            const size = await imageSize(imageUrl.value);
            if (token !== loadToken) return;
            pages.value = [{ number: 1, pdfWidth: size.width, pdfHeight: size.height }];
        } else {
            kind.value = 'pdf';
            const [pdfjs, worker] = await Promise.all([import('pdfjs-dist'), import('pdfjs-dist/build/pdf.worker.min.mjs?url')]);
            pdfjs.GlobalWorkerOptions.workerSrc = worker.default;
            const task = pdfjs.getDocument({ data: await blob.arrayBuffer() });
            const loaded = await task.promise;
            if (token !== loadToken) return task.destroy();
            loadingTask = task;
            pdfDocument.value = loaded;
            const first = (await loaded.getPage(1)).getViewport({ scale: 1 });
            // Todas arrancan con el tamaño de la primera; cada una se corrige al pintarse.
            pages.value = Array.from({ length: loaded.numPages }, (_, index) => ({ number: index + 1, pdfWidth: first.width, pdfHeight: first.height }));
        }
        status.value = 'ready';
        await nextTick();
        if (fitMode.value) applyFit();
        else scale.value = Number(props.zoom) || 1;
        observePages();
        emit('loaded', { pages: pages.value.length, kind: kind.value });
        if (kind.value === 'image') await nextTick(() => emitRendered(pages.value[0]));
    } catch {
        if (token === loadToken) fail('El archivo no se pudo abrir. Puede estar dañado o protegido con contraseña.');
    }
}

function imageSize(url) {
    return new Promise((resolve, reject) => {
        const image = new Image();
        image.onload = () => resolve({ width: image.naturalWidth, height: image.naturalHeight });
        image.onerror = reject;
        image.src = url;
    });
}

function emitRendered(page) {
    emit('page-rendered', { ...pageInfo(page), element: pageElements.get(page.number) ?? null });
}

async function renderPage(number) {
    const loaded = pdfDocument.value;
    const canvas = canvases.get(number);
    if (!loaded || !canvas || renderedScale.get(number) === scale.value) return;
    const targetScale = scale.value;
    const firstPaint = !renderedScale.has(number);
    renderTasks.get(number)?.cancel();
    try {
        const pdfPage = await loaded.getPage(number);
        const base = pdfPage.getViewport({ scale: 1 });
        const entry = pages.value[number - 1];
        if (entry && (entry.pdfWidth !== base.width || entry.pdfHeight !== base.height)) {
            entry.pdfWidth = base.width;
            entry.pdfHeight = base.height;
        }
        const viewport = pdfPage.getViewport({ scale: targetScale });
        const ratio = Math.min(window.devicePixelRatio || 1, MAX_PIXEL_RATIO);
        canvas.width = Math.floor(viewport.width * ratio);
        canvas.height = Math.floor(viewport.height * ratio);
        const task = pdfPage.render({ canvas, canvasContext: canvas.getContext('2d'), viewport, transform: ratio === 1 ? undefined : [ratio, 0, 0, ratio, 0, 0] });
        renderTasks.set(number, task);
        await task.promise;
        renderTasks.delete(number);
        renderedScale.set(number, targetScale);
        // Movimiento: la hoja aparece con un fundido la primera vez que se pinta.
        if (firstPaint) play(canvas, [{ opacity: 0, transform: 'scale(0.985)' }, { opacity: 1, transform: 'none' }], { duration: 320, easing: EASE.expo, reduced: 'fade' });
        if (entry) emitRendered(entry);
    } catch (error) {
        if (error?.name !== 'RenderingCancelledException') renderedScale.delete(number);
    }
}

function observePages() {
    intersection?.disconnect();
    if (kind.value !== 'pdf' || !scroller.value) return;
    intersection = new IntersectionObserver(
        (entries) => {
            entries.filter((entry) => entry.isIntersecting).forEach((entry) => renderPage(Number(entry.target.dataset.page)));
        },
        { root: scroller.value, rootMargin: '400px 0px' },
    );
    pageElements.forEach((element) => intersection.observe(element));
}

function setPageElement(number, element) {
    if (element) pageElements.set(number, element);
    else pageElements.delete(number);
}

function setCanvas(number, element) {
    if (element) canvases.set(number, element);
    else canvases.delete(number);
}

function applyFit() {
    const first = pages.value[0];
    if (!first || !scroller.value) return;
    const available = scroller.value.clientWidth - GUTTER;
    if (available <= 0) return;
    // Las imágenes no pasan de su tamaño real ni un PDF de 150 %: en pantallas anchas la hoja sería enorme.
    const fitted = available / first.pdfWidth;
    scale.value = Math.max(MIN_SCALE, Math.min(Math.min(fitted, kind.value === 'image' ? 1 : MAX_FIT_SCALE), MAX_SCALE));
}

function fitWidth() {
    fitMode.value = true;
    applyFit();
}

function zoomBy(factor) {
    fitMode.value = false;
    scale.value = Math.max(MIN_SCALE, Math.min(Math.round(scale.value * factor * 100) / 100, MAX_SCALE));
}

function goToPage(number) {
    const target = Math.max(1, Math.min(Number(number) || 1, pageCount.value));
    pageElements.get(target)?.scrollIntoView({ block: 'start' });
    currentPage.value = target;
}

function onScroll() {
    if (scrollFrame) return;
    scrollFrame = requestAnimationFrame(() => {
        scrollFrame = null;
        const box = scroller.value?.getBoundingClientRect();
        if (!box) return;
        const middle = box.top + box.height / 2;
        let closest = currentPage.value;
        let distance = Infinity;
        pageElements.forEach((element, number) => {
            const rect = element.getBoundingClientRect();
            const gap = middle < rect.top ? rect.top - middle : middle > rect.bottom ? middle - rect.bottom : 0;
            if (gap < distance) {
                distance = gap;
                closest = number;
            }
        });
        currentPage.value = closest;
    });
}

watch(currentPage, (number) => emit('page-change', number));

// Al cambiar el zoom se repintan solo las páginas visibles; el resto, cuando entren en pantalla.
watch(scale, async () => {
    if (status.value !== 'ready') return;
    await nextTick();
    if (kind.value === 'image') return emitRendered(pages.value[0]);
    observePages();
});

watch(() => props.src, load);

onMounted(() => {
    resizeObserver = new ResizeObserver(() => {
        if (fitMode.value && status.value === 'ready') applyFit();
    });
    if (scroller.value) resizeObserver.observe(scroller.value);
    load();
});

onBeforeUnmount(() => {
    loadToken += 1;
    resizeObserver?.disconnect();
    cancelAnimationFrame(scrollFrame);
    cleanup();
});

defineExpose({ goToPage, zoomIn: () => zoomBy(ZOOM_STEP), zoomOut: () => zoomBy(1 / ZOOM_STEP), fitWidth, reload: load });
</script>

<template>
    <section class="flex min-h-0 min-w-0 flex-col overflow-hidden bg-cap-card-2" :class="height" :aria-label="`Visor de ${name}`">
        <div class="flex shrink-0 flex-wrap items-center gap-x-3 gap-y-1 border-b border-cap-divider bg-cap-card px-2 py-1 text-sm text-gray-900 dark:text-gray-100">
            <div v-if="kind === 'pdf'" class="flex items-center gap-1">
                <button type="button" aria-label="Página anterior" title="Página anterior" :disabled="status !== 'ready' || currentPage <= 1" :class="iconButton" @click="goToPage(currentPage - 1)">
                    <i class="mdi mdi-chevron-up text-xl leading-none" aria-hidden="true" />
                </button>
                <label class="flex items-center gap-1.5 text-xs text-cap-text-soft">
                    <span class="max-sm:sr-only">Página</span>
                    <input type="text" inputmode="numeric" :value="currentPage" :disabled="status !== 'ready'" aria-label="Ir a la página" :class="[inputClass(), 'h-8 !w-12 px-1 text-center tabular-nums']" @change="goToPage($event.target.value)" @keydown.enter.prevent="goToPage($event.target.value)">
                    <span class="tabular-nums">de {{ pageCount || '—' }}</span>
                </label>
                <button type="button" aria-label="Página siguiente" title="Página siguiente" :disabled="status !== 'ready' || currentPage >= pageCount" :class="iconButton" @click="goToPage(currentPage + 1)">
                    <i class="mdi mdi-chevron-down text-xl leading-none" aria-hidden="true" />
                </button>
            </div>
            <div class="flex items-center gap-1">
                <button type="button" aria-label="Alejar" title="Alejar" :disabled="status !== 'ready' || scale <= MIN_SCALE" :class="iconButton" @click="zoomBy(1 / ZOOM_STEP)">
                    <i class="mdi mdi-minus text-xl leading-none" aria-hidden="true" />
                </button>
                <button type="button" title="Ajustar al ancho" :aria-label="`Zoom ${zoomLabel}. Ajustar al ancho`" :disabled="status !== 'ready'" class="h-8 min-w-14 rounded-md px-1 text-xs font-medium tabular-nums text-cap-text-soft transition-colors duration-cap-fast ease-cap hover:bg-cap-card-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome disabled:opacity-50 dark:focus-visible:ring-gray-100" @click="fitWidth">
                    {{ zoomLabel }}
                </button>
                <button type="button" aria-label="Acercar" title="Acercar" :disabled="status !== 'ready' || scale >= MAX_SCALE" :class="iconButton" @click="zoomBy(ZOOM_STEP)">
                    <i class="mdi mdi-plus text-xl leading-none" aria-hidden="true" />
                </button>
            </div>
            <div class="ml-auto flex items-center gap-1">
                <slot name="toolbar" :page="currentPage" :pages="pageCount" />
                <a v-if="download" :href="download" :download="name" aria-label="Descargar" title="Descargar" :class="iconButton">
                    <i class="mdi mdi-download-outline text-xl leading-none" aria-hidden="true" />
                </a>
            </div>
        </div>

        <div ref="scroller" class="relative min-h-0 flex-1 overflow-auto focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-cap-chrome dark:focus-visible:ring-gray-100" tabindex="0" :aria-busy="status === 'loading' ? 'true' : undefined" @scroll.passive="onScroll">
            <div v-if="status === 'loading'" class="mx-auto flex h-full max-w-xl flex-col gap-3 p-6" role="status" aria-label="Cargando el documento">
                <span class="cap-skeleton block w-2/3" /><span class="cap-skeleton block" /><span class="cap-skeleton block w-5/6" /><span class="cap-skeleton block w-1/2" /><span class="cap-skeleton block" /><span class="cap-skeleton block w-3/4" />
            </div>

            <ErrorState v-else-if="status === 'error'" title="No se pudo abrir el documento" :description="errorMessage" @retry="load" />

            <div v-else-if="status === 'idle'" class="flex h-full items-center justify-center p-6 text-sm text-cap-text-soft">No hay documento para mostrar.</div>

            <div v-else class="flex w-max min-w-full flex-col items-center gap-4 p-4">
                <div
                    v-for="page in pages"
                    :key="page.number"
                    :ref="(element) => setPageElement(page.number, element)"
                    :data-page="page.number"
                    class="app-pdf-page relative shrink-0"
                    :style="{ width: `${pageInfo(page).width}px`, height: `${pageInfo(page).height}px` }"
                >
                    <img v-if="kind === 'image'" :src="imageUrl" :alt="name" class="block h-full w-full" draggable="false">
                    <canvas v-else :ref="(element) => setCanvas(page.number, element)" class="block h-full w-full" role="img" :aria-label="`Página ${page.number} de ${pageCount}`" />
                    <div v-if="$slots['page-overlay']" class="absolute inset-0">
                        <slot name="page-overlay" v-bind="pageInfo(page)" />
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
