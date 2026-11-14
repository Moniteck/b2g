// Pila de capas modales abiertas (BaseModal y la confirmación). Solo la de arriba responde a Esc,
// y el scroll de la ventana queda bloqueado mientras haya alguna abierta.
import { computed, onBeforeUnmount, ref } from 'vue';

const layers = ref([]);
let nextId = 0;
let savedOverflow = '';
let savedPadding = '';

function lockScroll() {
    const root = document.documentElement;
    const scrollbar = window.innerWidth - root.clientWidth;
    savedOverflow = root.style.overflow;
    savedPadding = root.style.paddingRight;
    root.style.overflow = 'hidden';
    if (scrollbar > 0) root.style.paddingRight = `${scrollbar}px`;
}

function unlockScroll() {
    const root = document.documentElement;
    root.style.overflow = savedOverflow;
    root.style.paddingRight = savedPadding;
}

export function useLayer() {
    const id = ++nextId;
    const isTop = computed(() => layers.value[layers.value.length - 1] === id);

    function push() {
        if (layers.value.includes(id)) return;
        if (layers.value.length === 0) lockScroll();
        layers.value = [...layers.value, id];
    }

    function pop() {
        if (!layers.value.includes(id)) return;
        layers.value = layers.value.filter((layer) => layer !== id);
        if (layers.value.length === 0) unlockScroll();
    }

    onBeforeUnmount(pop);

    return { isTop, push, pop };
}
