<script setup>
// Firma o sello colocado sobre una página: arrastrable y redimensionable con ratón, táctil y teclado.
// Props: modelValue ({ x, y, width } en % de la página; el alto sale de la proporción de la imagen),
//        pageWidth / pageHeight (px de la hoja pintada), ratio (alto ÷ ancho de la imagen), src, label,
//        ghost (copia de referencia en otras páginas: no se mueve).
// Teclado: flechas mueven 0,5 % (con Mayús, 5 %); «+» y «-» (o Alt + flechas) cambian el tamaño.
import { computed, onBeforeUnmount } from 'vue';

const props = defineProps({
    modelValue: { type: Object, required: true },
    pageWidth: { type: Number, required: true },
    pageHeight: { type: Number, required: true },
    ratio: { type: Number, default: 0.33 },
    src: { type: String, default: '' },
    label: { type: String, default: '' },
    ghost: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const MIN_WIDTH = 4;
let gesture = null;

/** Alto en % de la página para un ancho en %. */
function heightFor(width) {
    if (!props.pageHeight) return 0;
    return (width * props.pageWidth * props.ratio) / props.pageHeight;
}

const height = computed(() => heightFor(props.modelValue.width));

const style = computed(() => ({
    left: `${props.modelValue.x}%`,
    top: `${props.modelValue.y}%`,
    width: `${props.modelValue.width}%`,
    height: `${height.value}%`,
}));

/** Mantiene la caja dentro de la página. */
function clamp({ x, y, width }) {
    let w = Math.max(MIN_WIDTH, Math.min(width, 100));
    // Si por alto no cabe, se reduce el ancho.
    const maxByHeight = props.pageHeight ? (100 * props.pageHeight) / (props.pageWidth * props.ratio) : 100;
    w = Math.min(w, maxByHeight);
    const h = heightFor(w);
    return {
        x: round(Math.max(0, Math.min(x, 100 - w))),
        y: round(Math.max(0, Math.min(y, 100 - h))),
        width: round(w),
    };
}

function round(value) {
    return Math.round(value * 1000) / 1000;
}

function update(next) {
    emit('update:modelValue', clamp({ ...props.modelValue, ...next }));
}

function start(event, mode) {
    if (props.ghost || (event.pointerType === 'mouse' && event.button !== 0)) return;
    event.preventDefault();
    event.stopPropagation();
    event.currentTarget.setPointerCapture?.(event.pointerId);
    event.currentTarget.closest('[data-placement]')?.focus({ preventScroll: true });
    gesture = { mode, id: event.pointerId, startX: event.clientX, startY: event.clientY, origin: { ...props.modelValue }, target: event.currentTarget };
    gesture.target.addEventListener('pointermove', move);
    gesture.target.addEventListener('pointerup', end);
    gesture.target.addEventListener('pointercancel', end);
}

function move(event) {
    if (!gesture || event.pointerId !== gesture.id) return;
    const dx = ((event.clientX - gesture.startX) / props.pageWidth) * 100;
    const dy = ((event.clientY - gesture.startY) / props.pageHeight) * 100;
    if (gesture.mode === 'move') update({ x: gesture.origin.x + dx, y: gesture.origin.y + dy });
    else update({ width: gesture.origin.width + dx });
}

function end(event) {
    if (!gesture || (event && event.pointerId !== gesture.id)) return;
    gesture.target.removeEventListener('pointermove', move);
    gesture.target.removeEventListener('pointerup', end);
    gesture.target.removeEventListener('pointercancel', end);
    gesture = null;
}

function onKeydown(event) {
    const step = event.shiftKey ? 5 : 0.5;
    const resize = event.altKey;
    const { x, y, width } = props.modelValue;
    const actions = {
        ArrowLeft: () => (resize ? update({ width: width - step }) : update({ x: x - step })),
        ArrowRight: () => (resize ? update({ width: width + step }) : update({ x: x + step })),
        ArrowUp: () => (resize ? update({ width: width + step }) : update({ y: y - step })),
        ArrowDown: () => (resize ? update({ width: width - step }) : update({ y: y + step })),
        '+': () => update({ width: width + 1 }),
        '=': () => update({ width: width + 1 }),
        '-': () => update({ width: width - 1 }),
    };
    if (!actions[event.key]) return;
    event.preventDefault();
    event.stopPropagation();
    actions[event.key]();
}

onBeforeUnmount(() => end());
</script>

<template>
    <div
        :data-placement="ghost ? undefined : label"
        class="absolute select-none"
        :class="ghost ? 'pointer-events-none opacity-50' : 'group cursor-move touch-none rounded-sm outline-2 outline-dashed outline-cap-brand focus:outline-none focus-visible:outline-solid focus-visible:ring-2 focus-visible:ring-cap-chrome focus-visible:ring-offset-2 dark:outline-cap-brand-soft dark:focus-visible:ring-gray-100'"
        :style="style"
        :tabindex="ghost ? -1 : 0"
        role="group"
        :aria-roledescription="ghost ? undefined : 'elemento movible'"
        :aria-label="ghost ? undefined : `${label}: ${Math.round(modelValue.x)} % desde la izquierda, ${Math.round(modelValue.y)} % desde arriba, ${Math.round(modelValue.width)} % de ancho. Flechas para mover; más y menos para cambiar el tamaño.`"
        @pointerdown="start($event, 'move')"
        @keydown="onKeydown"
    >
        <img v-if="src" :src="src" :alt="ghost ? '' : label" class="pointer-events-none block h-full w-full object-contain" draggable="false">
        <span v-if="!ghost" class="pointer-events-none absolute -top-6 left-0 whitespace-nowrap rounded-full bg-cap-chrome px-2 py-0.5 text-xs font-medium text-white dark:bg-gray-100 dark:text-gray-900">{{ label }}</span>
        <span
            v-if="!ghost"
            class="absolute -bottom-3 -right-3 flex h-6 w-6 cursor-nwse-resize touch-none items-center justify-center rounded-full border-2 border-white bg-cap-brand shadow-md dark:border-gray-900 dark:bg-cap-brand-soft"
            data-resize-handle
            :aria-hidden="true"
            @pointerdown="start($event, 'resize')"
        >
            <i class="mdi mdi-arrow-top-left-bottom-right text-xs leading-none text-white dark:text-gray-900" aria-hidden="true" />
        </span>
    </div>
</template>
