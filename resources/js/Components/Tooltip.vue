<script setup>
// Tooltip de vidrio para explicar un control (motivo de un botón deshabilitado, texto recortado).
// Props: text, placement ('top' | 'bottom'), disabled. El control va en el slot.
// Aparece al pasar el cursor o al enfocar con teclado; Esc lo oculta. Nunca es el único portador
// de la información: los botones de ícono siguen llevando su `aria-label`.
import { ref, useId } from 'vue';
import { useFloating } from '@/composables/useFloating.js';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    text: { type: String, default: '' },
    placement: { type: String, default: 'top' },
    disabled: { type: Boolean, default: false },
});

const SHOW_DELAY_MS = 300;

const tooltipId = useId();
const anchor = ref(null);
const bubble = ref(null);
const open = ref(false);
let timer = null;

const { style, teleportTarget } = useFloating(anchor, bubble, open, { placement: props.placement, offset: 8 });

function show(delay) {
    if (props.disabled || !props.text) return;
    clearTimeout(timer);
    timer = setTimeout(() => {
        open.value = true;
    }, delay);
}

function hide() {
    clearTimeout(timer);
    open.value = false;
}
</script>

<template>
    <span
        ref="anchor"
        class="inline-flex"
        v-bind="$attrs"
        :aria-describedby="open ? tooltipId : undefined"
        @mouseenter="show(SHOW_DELAY_MS)"
        @mouseleave="hide"
        @focusin="show(0)"
        @focusout="hide"
        @keydown.esc="hide"
    >
        <slot />
    </span>
    <Teleport :to="teleportTarget">
        <Transition name="m-pop">
            <span v-if="open" :id="tooltipId" ref="bubble" role="tooltip" :style="style" class="app-tooltip z-cap-dropdown">{{ text }}</span>
        </Transition>
    </Teleport>
</template>
