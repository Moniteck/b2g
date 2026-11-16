<script setup>
// Lienzo para dibujar la firma con ratón, lápiz o dedo (pointer events).
//
//   <SignaturePad ref="pad" :color="'#1f3a93'" :weight="1" @change="(count) => (strokes = count)" />
//   pad.value.undo(); pad.value.clear(); const canvas = pad.value.toCanvas(); // recortado y transparente
//
// El trazo se guarda en un espacio lógico de 1000 × 400 y se pinta con curvas cuadráticas entre los
// puntos medios; el grosor varía con la velocidad (más rápido, más fino) o con la presión real del
// lápiz. Así se puede volver a pintar a cualquier tamaño: al girar el teléfono, al cambiar color o
// grosor y al exportar a alta resolución. Mientras se dibuja, la página no se desplaza
// (`touch-action: none` y captura del puntero).
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { trimCanvas } from '@/Pages/Signatures/Partials/ink.js';

const props = defineProps({
    color: { type: String, required: true },
    weight: { type: Number, default: 1 },
    label: { type: String, default: 'Área para dibujar la firma' },
});

const emit = defineEmits(['change']);

const WIDTH = 1000;
const HEIGHT = 400;
const MIN_WIDTH = 1.6;
const MAX_WIDTH = 5.6;
const VELOCITY_FILTER = 0.7;

const frame = ref(null);
const canvas = ref(null);
const drawing = ref(false);
const strokes = ref([]);
let current = null;
let scale = 1;
let observer = null;

function context() {
    return canvas.value?.getContext('2d');
}

/** Grosor de un punto nuevo según la velocidad (o la presión del lápiz). */
function widthFor(point, previous, stroke, event) {
    if (event.pointerType === 'pen' && event.pressure > 0 && event.pressure < 1) {
        const target = MIN_WIDTH + (MAX_WIDTH - MIN_WIDTH) * Math.pow(event.pressure, 0.8);
        return previous ? previous.w * 0.4 + target * 0.6 : target;
    }
    if (!previous) return (MIN_WIDTH + MAX_WIDTH) / 2.2;
    const elapsed = Math.max(1, point.t - previous.t);
    const distance = Math.hypot(point.x - previous.x, point.y - previous.y);
    const velocity = VELOCITY_FILTER * (distance / elapsed) + (1 - VELOCITY_FILTER) * (stroke.velocity ?? 0);
    stroke.velocity = velocity;
    const target = Math.max(MAX_WIDTH / (velocity + 1), MIN_WIDTH);
    return previous.w * 0.55 + target * 0.45;
}

/** Curva cuadrática de `start` a `end` con control en `ctrl`, rellenada con discos de grosor variable. */
function curve(ctx, start, ctrl, end, w0, w1, k) {
    const length = Math.hypot(ctrl.x - start.x, ctrl.y - start.y) + Math.hypot(end.x - ctrl.x, end.y - ctrl.y);
    const steps = Math.max(2, Math.ceil((length * k) / 0.6));
    ctx.beginPath();
    for (let i = 0; i <= steps; i += 1) {
        const t = i / steps;
        const u = 1 - t;
        const x = (u * u * start.x + 2 * u * t * ctrl.x + t * t * end.x) * k;
        const y = (u * u * start.y + 2 * u * t * ctrl.y + t * t * end.y) * k;
        const r = Math.max(0.35, ((w0 + (w1 - w0) * t) * props.weight * k) / 2);
        ctx.moveTo(x + r, y);
        ctx.arc(x, y, r, 0, Math.PI * 2);
    }
    ctx.fill();
}

const mid = (a, b) => ({ x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 });

/** Pinta el tramo que termina en el punto `index` (o el cierre del trazo con `closing`). */
function segment(ctx, points, index, k, closing = false) {
    if (closing) {
        const n = points.length - 1;
        if (n === 0) {
            const p = points[0];
            curve(ctx, p, p, p, p.w * 1.1, p.w * 1.1, k);
            return;
        }
        curve(ctx, mid(points[n - 1], points[n]), points[n], points[n], (points[n - 1].w + points[n].w) / 2, points[n].w * 0.8, k);
        return;
    }
    if (index === 1) {
        curve(ctx, points[0], points[0], mid(points[0], points[1]), points[0].w * 0.8, (points[0].w + points[1].w) / 2, k);
        return;
    }
    const a = points[index - 2];
    const b = points[index - 1];
    const c = points[index];
    curve(ctx, mid(a, b), b, mid(b, c), (a.w + b.w) / 2, (b.w + c.w) / 2, k);
}

function paintStroke(ctx, stroke, k) {
    const { points } = stroke;
    for (let i = 1; i < points.length; i += 1) segment(ctx, points, i, k);
    segment(ctx, points, points.length - 1, k, true);
}

function render() {
    const ctx = context();
    if (!ctx) return;
    ctx.setTransform(1, 0, 0, 1, 0, 0);
    ctx.clearRect(0, 0, canvas.value.width, canvas.value.height);
    ctx.fillStyle = props.color;
    strokes.value.forEach((stroke) => paintStroke(ctx, stroke, scale));
    if (current) paintStroke(ctx, current, scale);
}

function resize() {
    if (!canvas.value || !frame.value) return;
    const rect = frame.value.getBoundingClientRect();
    if (!rect.width) return;
    const ratio = window.devicePixelRatio || 1;
    canvas.value.width = Math.round(rect.width * ratio);
    canvas.value.height = Math.round(rect.width * (HEIGHT / WIDTH) * ratio);
    scale = canvas.value.width / WIDTH;
    render();
}

function pointFrom(event) {
    const rect = canvas.value.getBoundingClientRect();
    return {
        x: ((event.clientX - rect.left) / rect.width) * WIDTH,
        y: ((event.clientY - rect.top) / rect.height) * HEIGHT,
        t: event.timeStamp || performance.now(),
    };
}

function addPoint(event) {
    const points = current.points;
    const point = pointFrom(event);
    const previous = points[points.length - 1];
    // Puntos casi iguales ensucian la curva; con menos de medio punto lógico se descartan.
    if (previous && Math.hypot(point.x - previous.x, point.y - previous.y) < 0.6) return;
    point.w = widthFor(point, previous, current, event);
    points.push(point);
    if (points.length > 1) {
        const ctx = context();
        ctx.fillStyle = props.color;
        segment(ctx, points, points.length - 1, scale);
    }
}

function onPointerDown(event) {
    if (event.pointerType === 'mouse' && event.button !== 0) return;
    event.preventDefault();
    canvas.value.setPointerCapture?.(event.pointerId);
    drawing.value = true;
    current = { points: [], velocity: 0, pointerId: event.pointerId };
    addPoint(event);
}

function onPointerMove(event) {
    if (!drawing.value || event.pointerId !== current?.pointerId) return;
    event.preventDefault();
    const events = event.getCoalescedEvents?.() ?? [];
    (events.length ? events : [event]).forEach(addPoint);
}

function onPointerUp(event) {
    if (!drawing.value || event.pointerId !== current?.pointerId) return;
    event.preventDefault();
    const ctx = context();
    ctx.fillStyle = props.color;
    segment(ctx, current.points, current.points.length - 1, scale, true);
    strokes.value = [...strokes.value, { points: current.points }];
    current = null;
    drawing.value = false;
    emit('change', strokes.value.length);
}

function undo() {
    strokes.value = strokes.value.slice(0, -1);
    render();
    emit('change', strokes.value.length);
}

function clear() {
    strokes.value = [];
    render();
    emit('change', 0);
}

/** Lienzo de alta resolución, recortado al dibujo y con fondo transparente (o null si está vacío). */
function toCanvas(resolution = 2.4) {
    if (!strokes.value.length) return null;
    const out = document.createElement('canvas');
    out.width = Math.round(WIDTH * resolution);
    out.height = Math.round(HEIGHT * resolution);
    const ctx = out.getContext('2d');
    ctx.fillStyle = props.color;
    strokes.value.forEach((stroke) => paintStroke(ctx, stroke, resolution));
    return trimCanvas(out, Math.round(8 * resolution));
}

watch(() => [props.color, props.weight], render);

onMounted(() => {
    resize();
    observer = new ResizeObserver(() => resize());
    observer.observe(frame.value);
});

onBeforeUnmount(() => observer?.disconnect());

defineExpose({ undo, clear, toCanvas, strokes });
</script>

<template>
    <div ref="frame" class="relative w-full overflow-hidden rounded-cap-control border border-cap-divider bg-white shadow-inner dark:bg-white" :style="{ aspectRatio: `${WIDTH} / ${HEIGHT}` }">
        <!-- Línea guía y marca «×»: ayudan a firmar derecho y no se exportan. -->
        <div class="pointer-events-none absolute inset-x-[7%] top-[70%] border-b-2 border-dashed border-gray-300 dark:border-gray-300" aria-hidden="true" />
        <span class="pointer-events-none absolute left-[3%] top-[70%] -translate-y-full font-display text-xl text-gray-400 dark:text-gray-400" aria-hidden="true">×</span>
        <span
            v-if="!strokes.length && !drawing"
            class="pointer-events-none absolute inset-x-0 top-[38%] text-center text-sm text-gray-500 dark:text-gray-500"
            aria-hidden="true"
        >Dibuja tu firma sobre la línea</span>
        <canvas
            ref="canvas"
            class="absolute inset-0 h-full w-full cursor-crosshair touch-none select-none"
            role="img"
            :aria-label="`${label}. ${strokes.length ? `${strokes.length} trazos dibujados.` : 'Vacía.'}`"
            data-signature-pad
            @pointerdown="onPointerDown"
            @pointermove="onPointerMove"
            @pointerup="onPointerUp"
            @pointercancel="onPointerUp"
            @lostpointercapture="onPointerUp"
            @contextmenu.prevent
        />
    </div>
</template>
