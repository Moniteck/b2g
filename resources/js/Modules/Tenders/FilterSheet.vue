<script setup>
// Hoja de filtros: cajón lateral derecho en escritorio y hoja inferior en móvil (por debajo de `lg`).
// La biblioteca compartida no tiene cajón lateral: vive en el módulo (anotado en el informe).
//   <FilterSheet v-model:open="open" title="Filtros" :count="3" @clear="reset">…campos…<template #footer>…</template></FilterSheet>
// Esc y el fondo cierran; el foco entra en la hoja y vuelve al botón que la abrió.
import { nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: 'Filtros' },
    description: { type: String, default: '' },
});

const emit = defineEmits(['update:open']);

const panel = ref(null);
const titleId = useId();
let returnFocus = null;

function close() {
    emit('update:open', false);
}

function onKeydown(event) {
    if (event.key === 'Escape') {
        event.stopPropagation();
        close();
        return;
    }
    if (event.key !== 'Tab' || !panel.value) return;
    const focusable = [...panel.value.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])')].filter((el) => el.offsetParent !== null);
    if (!focusable.length) return;
    const [first, last] = [focusable[0], focusable[focusable.length - 1]];
    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

watch(
    () => props.open,
    async (open) => {
        if (open) {
            returnFocus = document.activeElement;
            document.documentElement.style.overflow = 'hidden';
            await nextTick();
            panel.value?.querySelector('input,select,button:not([data-close])')?.focus({ preventScroll: true });
        } else {
            document.documentElement.style.overflow = '';
            returnFocus?.focus?.({ preventScroll: true });
        }
    },
);

onBeforeUnmount(() => (document.documentElement.style.overflow = ''));
</script>

<template>
    <Teleport to="body">
        <Transition name="m-fade">
            <div v-if="open" class="fixed inset-0 z-cap-drawer bg-cap-chrome/35 backdrop-blur-sm" aria-hidden="true" @click="close" />
        </Transition>
        <Transition name="tender-sheet">
            <div
                v-if="open"
                ref="panel"
                role="dialog"
                aria-modal="true"
                :aria-labelledby="titleId"
                class="fixed inset-x-0 bottom-0 z-cap-drawer flex max-h-[88vh] flex-col rounded-t-cap-sheet border border-cap-divider bg-cap-card shadow-xl dark:bg-cap-card-2 lg:inset-x-auto lg:bottom-3 lg:right-3 lg:top-3 lg:max-h-none lg:w-[26rem] lg:rounded-cap-shell"
                data-filter-sheet
                @keydown="onKeydown"
            >
                <span class="mx-auto mt-2 h-1 w-10 shrink-0 rounded-full bg-cap-divider lg:hidden" aria-hidden="true" />
                <header class="flex items-start justify-between gap-3 border-b border-cap-divider px-5 py-4">
                    <div class="min-w-0">
                        <h2 :id="titleId" class="font-display text-lg font-semibold text-gray-900 dark:text-gray-100">{{ title }}</h2>
                        <p v-if="description" class="mt-0.5 text-xs text-cap-text-soft">{{ description }}</p>
                    </div>
                    <button type="button" data-close aria-label="Cerrar filtros" title="Cerrar" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-cap-text-soft transition-colors duration-cap-fast ease-cap hover:bg-cap-card-2 hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome focus-visible:ring-offset-2 focus-visible:ring-offset-cap-card dark:hover:bg-cap-card dark:hover:text-gray-100 dark:focus-visible:ring-gray-100" @click="close"><i class="mdi mdi-close text-xl leading-none" aria-hidden="true" /></button>
                </header>
                <div class="flex-1 overflow-y-auto overscroll-contain px-5 py-5">
                    <slot />
                </div>
                <footer v-if="$slots.footer" class="flex items-center justify-between gap-2 border-t border-cap-divider px-5 py-4 pb-[max(1rem,env(safe-area-inset-bottom))]">
                    <slot name="footer" />
                </footer>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.tender-sheet-enter-active {
    transition: transform var(--cap-duration-slow) var(--cap-ease-enter), opacity var(--cap-duration-base) var(--cap-ease-enter);
}
.tender-sheet-leave-active {
    transition: transform var(--cap-duration-base) var(--cap-ease-exit), opacity var(--cap-duration-fast) var(--cap-ease-exit);
}
.tender-sheet-enter-from,
.tender-sheet-leave-to {
    transform: translateY(100%);
    opacity: 0;
}
@media (min-width: 1024px) {
    .tender-sheet-enter-from,
    .tender-sheet-leave-to {
        transform: translateX(calc(100% + 0.75rem));
    }
}
@media (prefers-reduced-motion: reduce) {
    .tender-sheet-enter-active,
    .tender-sheet-leave-active {
        transition: opacity var(--cap-duration-fast) linear;
    }
    .tender-sheet-enter-from,
    .tender-sheet-leave-to {
        transform: none;
    }
}
</style>
