<script setup>
// Modal para formularios y contenido (las confirmaciones usan `useConfirm()`).
// Props: open (v-model:open), title, description, size ('sm' | 'md' | 'lg' | 'xl' | 'full'; por debajo
//        de `md` siempre ocupa toda la pantalla con el pie fijo), persistent (ni Esc ni clic fuera
//        cierran: para formularios con cambios sin guardar o mientras envía), hideClose,
//        flush (cuerpo sin relleno, para visores y tablas), as ('div' | 'form': con 'form' el pie puede
//        llevar un botón `type="submit"` y la modal emite `submit`).
// Slots: default (cuerpo), footer (acciones: secundario a la izquierda del primario), header (reemplaza el título).
// Eventos: update:open, close, submit, opened, closed.
// Accesible: role="dialog", foco atrapado, foco inicial en `[data-autofocus]` o en el primer campo,
// Esc cierra y el foco vuelve a quien la abrió. Apilable: una modal puede abrir otra encima.
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import { trapFocus } from '../../design/shell/js/focus-trap.js';
import { useLayer } from '../composables/useLayers.js';
import { iconButton } from '../lib/ui.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: '' },
    description: { type: String, default: '' },
    size: { type: String, default: 'md' },
    persistent: { type: Boolean, default: false },
    hideClose: { type: Boolean, default: false },
    flush: { type: Boolean, default: false },
    as: { type: String, default: 'div' },
});

const emit = defineEmits(['update:open', 'close', 'submit', 'opened', 'closed']);

const SIZES = {
    sm: 'md:max-w-md',
    md: 'md:max-w-lg',
    lg: 'md:max-w-3xl',
    xl: 'md:max-w-5xl',
    full: 'md:h-full md:max-w-none',
};

const titleId = useId();
const descriptionId = useId();
const panel = ref(null);
const body = ref(null);
const rendered = ref(props.open);
const layer = useLayer();
let releaseFocus = null;
let pressedOnBackdrop = false;

const sizeClass = computed(() => SIZES[props.size] ?? SIZES.md);

function close() {
    emit('update:open', false);
    emit('close');
}

function onKeydown(event) {
    if (event.key !== 'Escape' || !layer.isTop.value || event.defaultPrevented) return;
    event.preventDefault();
    if (!props.persistent) close();
}

function onBackdropClick() {
    if (pressedOnBackdrop && !props.persistent) close();
    pressedOnBackdrop = false;
}

function initialFocus() {
    const marked = panel.value.querySelector('[data-autofocus]');
    if (marked) return marked;
    const field = body.value.querySelector('input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])');
    return field ?? panel.value;
}

async function engage() {
    layer.push();
    document.addEventListener('keydown', onKeydown);
    await nextTick();
    if (panel.value) releaseFocus = trapFocus(panel.value, initialFocus());
}

function release() {
    document.removeEventListener('keydown', onKeydown);
    releaseFocus?.();
    releaseFocus = null;
    layer.pop();
}

watch(
    () => props.open,
    (open) => {
        if (open) {
            rendered.value = true;
            engage();
        } else {
            release();
        }
    },
    { immediate: true, flush: 'post' },
);

onBeforeUnmount(release);

function afterLeave() {
    rendered.value = false;
    emit('closed');
}
</script>

<template>
    <!-- El Teleport se monta al abrir: así la modal más reciente queda encima de las anteriores. -->
    <Teleport v-if="rendered" to="body">
        <Transition
            appear
            enter-active-class="transition-opacity duration-cap-base ease-cap-enter"
            enter-from-class="opacity-0"
            leave-active-class="transition-opacity duration-cap-fast ease-cap-exit"
            leave-to-class="opacity-0"
            @after-enter="emit('opened')"
            @after-leave="afterLeave"
        >
            <div v-if="open" class="fixed inset-0 z-cap-modal flex items-stretch justify-center md:items-center md:p-6" data-floating-root>
                <div class="absolute inset-0 bg-cap-chrome/35 backdrop-blur-sm" @pointerdown="pressedOnBackdrop = true" @click="onBackdropClick" />
                <component
                    :is="as"
                    ref="panel"
                    role="dialog"
                    aria-modal="true"
                    :aria-labelledby="titleId"
                    :aria-describedby="description ? descriptionId : undefined"
                    tabindex="-1"
                    class="relative flex max-h-full w-full animate-cap-rise-in flex-col bg-cap-card shadow-xl focus:outline-none md:max-h-full md:rounded-cap-shell md:border md:border-cap-divider"
                    :class="sizeClass"
                    :novalidate="as === 'form' ? '' : undefined"
                    @submit.prevent="emit('submit', $event)"
                >
                    <header class="flex shrink-0 items-start justify-between gap-4 border-b border-cap-divider px-4 py-4 md:px-6">
                        <div class="min-w-0">
                            <slot name="header">
                                <h2 :id="titleId" class="font-display text-lg font-semibold text-gray-900 dark:text-gray-100">{{ title }}</h2>
                            </slot>
                            <p v-if="description" :id="descriptionId" class="mt-1 text-sm text-cap-text-soft">{{ description }}</p>
                        </div>
                        <button v-if="!hideClose" type="button" aria-label="Cerrar" title="Cerrar" :class="[iconButton, '-mr-2 -mt-1']" @click="close">
                            <i class="mdi mdi-close text-xl leading-none" aria-hidden="true" />
                        </button>
                    </header>
                    <div ref="body" class="min-h-0 flex-1 overflow-y-auto text-sm text-gray-700 dark:text-gray-300" :class="flush ? '' : 'p-4 md:p-6'">
                        <slot />
                    </div>
                    <footer v-if="$slots.footer" class="flex shrink-0 flex-wrap justify-end gap-2 border-t border-cap-divider px-4 py-4 md:px-6 max-md:[&>.cap-button]:flex-1">
                        <slot name="footer" />
                    </footer>
                </component>
            </div>
        </Transition>
    </Teleport>
</template>
