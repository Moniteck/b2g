<script setup>
// Visor de la captura con zoom y paneo (arrastrar, rueda con Ctrl, pellizco, teclado) y resaltado de
// la zona donde el OCR leyó un campo. `box` va en fracciones de la imagen (0 a 1), así sirve a
// cualquier tamaño.
//   <CaptureViewer ref="viewer" :src="url" :box="activeBox" :boxes="allBoxes" />  →  viewer.value.focusBox(box)
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import IconButton from '../../Components/IconButton.vue';
import { reducedMotion } from '../../lib/motion/index.js';

const props = defineProps({
    src: { type: String, required: true },
    alt: { type: String, default: 'Captura de la ficha de HonduCompras' },
    name: { type: String, default: '' },
    /** Zona resaltada: { x, y, w, h } en fracciones. */
    box: { type: Object, default: null },
    /** Zonas de los demás campos (marcas tenues; las dudosas en tono de aviso). */
    boxes: { type: Array, default: () => [] },
    height: { type: String, default: 'h-[60vh]' },
});

const emit = defineEmits(['select']);

const frame = ref(null);
const image = ref(null);
const natural = ref({ width: 0, height: 0 });
const view = ref({ scale: 1, x: 0, y: 0 });
const fitScale = ref(1);
const animated = ref(false);
const loaded = ref(false);
const grabbing = ref(false);
const failed = ref(false);
const MAX = 6;

const percent = computed(() => Math.round((view.value.scale / (fitScale.value || 1)) * 100));
const transform = computed(() => `translate(${view.value.x}px, ${view.value.y}px) scale(${view.value.scale})`);

function frameSize() {
    const rect = frame.value?.getBoundingClientRect();
    return { width: rect?.width ?? 0, height: rect?.height ?? 0 };
}

function clamp(next) {
    const { width, height } = frameSize();
    const w = natural.value.width * next.scale;
    const h = natural.value.height * next.scale;
    // Centrada si cabe; si no, sin dejar ver más allá de los bordes.
    const x = w <= width ? (width - w) / 2 : Math.min(0, Math.max(width - w, next.x));
    const y = h <= height ? Math.min(0, (height - h) / 2) : Math.min(0, Math.max(height - h, next.y));
    return { scale: next.scale, x, y: h <= height ? 0 : y };
}

function set(next, animate = false) {
    animated.value = animate && !reducedMotion.value;
    view.value = clamp(next);
}

function fit(animate = true) {
    const { width } = frameSize();
    if (!natural.value.width || !width) return;
    fitScale.value = width / natural.value.width;
    set({ scale: fitScale.value, x: 0, y: 0 }, animate);
}

function zoomAt(factor, cx, cy, animate = true) {
    const min = fitScale.value * 0.5;
    const scale = Math.min(fitScale.value * MAX, Math.max(min, view.value.scale * factor));
    const ratio = scale / view.value.scale;
    set({ scale, x: cx - (cx - view.value.x) * ratio, y: cy - (cy - view.value.y) * ratio }, animate);
}

function zoomBy(factor) {
    const { width, height } = frameSize();
    zoomAt(factor, width / 2, height / 2);
}

/** Lleva la zona al centro con un zoom que la deja legible. */
function focusBox(box) {
    if (!box || !natural.value.width) return;
    const { width, height } = frameSize();
    const boxW = box.w * natural.value.width;
    const boxH = box.h * natural.value.height;
    const scale = Math.min(fitScale.value * 2.5, Math.max(fitScale.value * 1.25, Math.min((width * 0.85) / boxW, (height * 0.5) / Math.max(boxH, 1))));
    const cx = (box.x + box.w / 2) * natural.value.width * scale;
    const cy = (box.y + box.h / 2) * natural.value.height * scale;
    set({ scale, x: width / 2 - cx, y: height / 2 - cy }, true);
}

function onLoad() {
    natural.value = { width: image.value.naturalWidth, height: image.value.naturalHeight };
    loaded.value = true;
    nextTick(() => fit(false));
}

// Paneo con puntero (ratón, dedo) y pellizco con dos dedos.
const pointers = new Map();
let pinch = null;
let dragged = false;

function onPointerDown(event) {
    frame.value.setPointerCapture?.(event.pointerId);
    pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
    grabbing.value = true;
    dragged = false;
    if (pointers.size === 2) {
        const [a, b] = [...pointers.values()];
        pinch = { distance: Math.hypot(a.x - b.x, a.y - b.y), scale: view.value.scale };
    }
}

function onPointerMove(event) {
    const previous = pointers.get(event.pointerId);
    if (!previous) return;
    const current = { x: event.clientX, y: event.clientY };
    pointers.set(event.pointerId, current);
    if (pointers.size === 2 && pinch) {
        const [a, b] = [...pointers.values()];
        const rect = frame.value.getBoundingClientRect();
        const factor = (Math.hypot(a.x - b.x, a.y - b.y) / pinch.distance) * (pinch.scale / view.value.scale);
        zoomAt(factor, (a.x + b.x) / 2 - rect.left, (a.y + b.y) / 2 - rect.top, false);
        return;
    }
    const dx = current.x - previous.x;
    const dy = current.y - previous.y;
    if (Math.abs(dx) + Math.abs(dy) > 2) dragged = true;
    set({ ...view.value, x: view.value.x + dx, y: view.value.y + dy });
}

function onPointerUp(event) {
    pointers.delete(event.pointerId);
    grabbing.value = pointers.size > 0;
    if (pointers.size < 2) pinch = null;
}

function onWheel(event) {
    // Sin Ctrl la rueda desplaza la imagen; con Ctrl (o pellizco del trackpad) hace zoom.
    event.preventDefault();
    const rect = frame.value.getBoundingClientRect();
    if (event.ctrlKey || event.metaKey) {
        zoomAt(Math.exp(-event.deltaY / 300), event.clientX - rect.left, event.clientY - rect.top, false);
    } else {
        set({ ...view.value, x: view.value.x - event.deltaX, y: view.value.y - event.deltaY });
    }
}

function onKeydown(event) {
    const step = 60;
    const moves = { ArrowLeft: [step, 0], ArrowRight: [-step, 0], ArrowUp: [0, step], ArrowDown: [0, -step] };
    if (moves[event.key]) {
        event.preventDefault();
        set({ ...view.value, x: view.value.x + moves[event.key][0], y: view.value.y + moves[event.key][1] }, true);
    } else if (event.key === '+' || event.key === '=') {
        zoomBy(1.25);
    } else if (event.key === '-') {
        zoomBy(0.8);
    } else if (event.key === '0') {
        fit();
    }
}

function boxStyle(box) {
    return { left: `${box.x * 100}%`, top: `${box.y * 100}%`, width: `${box.w * 100}%`, height: `${box.h * 100}%` };
}

function onBoxClick(item) {
    if (!dragged) emit('select', item.key);
}

let resizeObserver = null;
onMounted(() => {
    resizeObserver = new ResizeObserver(() => {
        if (!loaded.value) return;
        const wasFit = Math.abs(view.value.scale - fitScale.value) < 0.001;
        const { width } = frameSize();
        fitScale.value = width / natural.value.width;
        if (wasFit) fit(false);
        else set(view.value);
    });
    resizeObserver.observe(frame.value);
    if (image.value?.complete && image.value.naturalWidth) onLoad();
});
onBeforeUnmount(() => resizeObserver?.disconnect());

watch(
    () => props.box,
    (box) => {
        if (box) focusBox(box);
    },
);

defineExpose({ focusBox, fit, zoomIn: () => zoomBy(1.25), zoomOut: () => zoomBy(0.8) });
</script>

<template>
    <div class="flex flex-col overflow-hidden">
        <div class="flex items-center justify-between gap-2 border-b border-cap-divider px-3 py-1.5">
            <p class="min-w-0 truncate text-xs text-cap-text-soft" :title="name">{{ name || 'Captura' }}</p>
            <div class="flex shrink-0 items-center gap-1">
                <IconButton icon="magnify-minus-outline" label="Alejar" @click="zoomBy(0.8)" />
                <span class="w-12 text-center text-xs tabular-nums text-cap-text-soft" aria-live="polite">{{ percent }} %</span>
                <IconButton icon="magnify-plus-outline" label="Acercar" @click="zoomBy(1.25)" />
                <IconButton icon="fit-to-page-outline" label="Ajustar al ancho" @click="fit()" />
            </div>
        </div>
        <div
            ref="frame"
            class="relative touch-none select-none overflow-hidden bg-cap-card-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-cap-chrome dark:focus-visible:ring-gray-100"
            :class="[height, grabbing ? 'cursor-grabbing' : 'cursor-grab']"
            tabindex="0"
            role="img"
            :aria-label="`${alt}. Arrastra para mover, Ctrl y la rueda o los botones para el zoom; flechas, + y − con el teclado.`"
            data-capture-viewer
            @pointerdown="onPointerDown"
            @pointermove="onPointerMove"
            @pointerup="onPointerUp"
            @pointercancel="onPointerUp"
            @wheel="onWheel"
            @keydown="onKeydown"
            @dblclick="zoomBy(1.6)"
        >
            <div class="absolute left-0 top-0 origin-top-left" :class="animated ? 'capture-animated' : ''" :style="{ transform, width: `${natural.width}px`, height: `${natural.height}px` }">
                <img ref="image" :src="src" :alt="alt" draggable="false" class="block h-full w-full max-w-none" @load="onLoad" @error="failed = true" />
                <button
                    v-for="item in boxes"
                    :key="item.key"
                    type="button"
                    tabindex="-1"
                    class="absolute rounded-sm border transition-colors duration-cap-fast ease-cap"
                    :class="item.status === 'doubtful' ? 'border-cap-status-warning/70 bg-cap-status-warning/10 dark:border-cap-status-dark-warning/70 dark:bg-cap-status-dark-warning/10' : 'border-transparent hover:border-cap-status-info/60 hover:bg-cap-status-info/10 dark:hover:border-cap-status-dark-info/60 dark:hover:bg-cap-status-dark-info/10'"
                    :style="boxStyle(item.box)"
                    :aria-label="`Ir al campo ${item.label}`"
                    :data-box="item.key"
                    @pointerup="onBoxClick(item)"
                />
                <div v-if="box" class="capture-highlight pointer-events-none absolute rounded-sm" :style="{ ...boxStyle(box), '--halo': `${Math.max(natural.width, natural.height) * 2}px` }" data-capture-highlight aria-hidden="true" />
            </div>
            <p v-if="failed" class="absolute inset-0 flex items-center justify-center p-6 text-center text-sm text-cap-text-soft">No se pudo mostrar la captura. Puedes revisar los datos igual.</p>
        </div>
    </div>
</template>

<style scoped>
.capture-animated {
    transition: transform var(--cap-duration-slow) var(--cap-ease-standard);
}
/* Resaltado: borde del color de información con un halo que oscurece el resto de la captura. */
.capture-highlight {
    outline: 2px solid var(--cap-status-info);
    outline-offset: 2px;
    box-shadow: 0 0 0 var(--halo, 2000px) color-mix(in srgb, var(--cap-chrome) 38%, transparent);
    animation: capture-pulse var(--cap-duration-slow) var(--cap-ease-enter);
}
@keyframes capture-pulse {
    from {
        outline-offset: 10px;
        opacity: 0;
    }
    to {
        outline-offset: 2px;
        opacity: 1;
    }
}
@media (prefers-reduced-motion: reduce) {
    .capture-highlight {
        animation: none;
    }
}
</style>
