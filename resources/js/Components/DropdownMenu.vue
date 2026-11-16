<script setup>
// Menú de acciones (el `⋮` de cada fila y cualquier menú desplegable).
// Props: items ([{ label, icon?, href?, external?, danger?, disabled?, onSelect?, separator? }];
//        `separator: true` dibuja una línea antes del ítem), label (nombre accesible del botón:
//        «Acciones de F-000123»), icon ('dots-vertical'), align ('end' | 'start'), width (clase, 'w-48').
// Slots: trigger ({ open, toggle, attrs }) para un disparador propio; header (encima de los ítems);
//        default ({ close }) para contenido libre en lugar de `items`.
// Disparador y opciones con área táctil de 44 px por debajo de `lg` (B-23).
// Eventos: select (ítem). Teclado: ↑ ↓ Inicio Fin recorren, Enter activa, Esc cierra y devuelve el foco.
import { Link } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import { onPointerOutside, useFloating } from '../composables/useFloating.js';
import { floatingPanel, iconButton, menuItem, menuItemDanger } from '../lib/ui.js';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    items: { type: Array, default: () => [] },
    label: { type: String, default: 'Acciones' },
    icon: { type: String, default: 'dots-vertical' },
    align: { type: String, default: 'end' },
    width: { type: String, default: 'w-48' },
});

const emit = defineEmits(['select']);

const menuId = useId();
const open = ref(false);
const anchor = ref(null);
const menu = ref(null);
let releaseOutside = null;

const { style, teleportTarget } = useFloating(anchor, menu, open, { placement: `bottom-${props.align}` });

const triggerAttrs = computed(() => ({
    'aria-haspopup': 'menu',
    'aria-expanded': open.value ? 'true' : 'false',
    'aria-controls': open.value ? menuId : undefined,
}));

function focusables() {
    return [...(menu.value?.querySelectorAll('[role="menuitem"]:not([disabled]):not([aria-disabled="true"])') ?? [])];
}

function focusItem(index) {
    const list = focusables();
    if (list.length) list[(index + list.length) % list.length].focus();
}

function close({ restoreFocus = true } = {}) {
    if (!open.value) return;
    open.value = false;
    if (restoreFocus) anchor.value?.querySelector('button, a, [tabindex]')?.focus();
}

function toggle() {
    if (open.value) close();
    else open.value = true;
}

watch(open, async (isOpen) => {
    releaseOutside?.();
    releaseOutside = null;
    if (!isOpen) return;
    const releasePointer = onPointerOutside(() => [anchor.value, menu.value], () => close({ restoreFocus: false }));
    // Esc cierra aunque el foco se haya quedado en el disparador.
    const onEscape = (event) => {
        if (event.key !== 'Escape') return;
        event.preventDefault();
        close();
    };
    document.addEventListener('keydown', onEscape);
    releaseOutside = () => {
        releasePointer();
        document.removeEventListener('keydown', onEscape);
    };
    await nextTick();
    // El panel se hace visible al posicionarse; hasta entonces no acepta el foco.
    requestAnimationFrame(() => focusItem(0));
});

onBeforeUnmount(() => releaseOutside?.());

function onMenuKeydown(event) {
    const list = focusables();
    const current = list.indexOf(document.activeElement);
    if (event.key === 'ArrowDown') focusItem(current + 1);
    else if (event.key === 'ArrowUp') focusItem(current - 1);
    else if (event.key === 'Home') focusItem(0);
    else if (event.key === 'End') focusItem(list.length - 1);
    else if (event.key === 'Escape') close();
    else if (event.key === 'Tab') return close({ restoreFocus: false });
    else return;
    event.preventDefault();
    event.stopPropagation();
}

function onTriggerKeydown(event) {
    if (event.key === 'ArrowDown' && !open.value) {
        event.preventDefault();
        open.value = true;
    }
}

function select(item) {
    if (item.disabled) return;
    close();
    item.onSelect?.(item);
    emit('select', item);
}

function itemTag(item) {
    if (!item.href) return 'button';
    return item.external ? 'a' : Link;
}

function itemAttrs(item) {
    if (!item.href) return { type: 'button', disabled: item.disabled };
    return { href: item.href, ...(item.external ? { target: item.target, download: item.download } : {}) };
}
</script>

<template>
    <span ref="anchor" class="inline-flex" v-bind="$attrs" @keydown="onTriggerKeydown">
        <slot name="trigger" :open="open" :toggle="toggle" :attrs="triggerAttrs">
            <button type="button" :aria-label="label" :title="label" v-bind="triggerAttrs" :class="[iconButton, 'max-lg:h-11 max-lg:w-11']" @click.stop="toggle">
                <i class="mdi text-xl leading-none" :class="`mdi-${icon}`" aria-hidden="true" />
            </button>
        </slot>
    </span>
    <Teleport :to="teleportTarget">
        <!-- Movimiento: cae desde el disparador con un rebote mínimo (m-menu, resources/css/motion.css). -->
        <Transition name="m-menu">
            <div
                v-if="open"
                :id="menuId"
                ref="menu"
                role="menu"
                :aria-label="label"
                :style="style"
                class="z-cap-dropdown max-h-80 overflow-y-auto py-1"
                :class="[floatingPanel, width, align === 'start' ? 'origin-top-left' : 'origin-top-right']"
                @keydown="onMenuKeydown"
                @click.stop
            >
                <slot name="header" />
                <slot :close="close">
                    <template v-for="item in items" :key="item.label">
                        <div v-if="item.separator" class="my-1 border-t border-cap-divider" role="separator" />
                        <component
                            :is="itemTag(item)"
                            role="menuitem"
                            v-bind="itemAttrs(item)"
                            :class="[item.danger ? menuItemDanger : menuItem, 'max-lg:h-11', item.disabled ? 'cursor-not-allowed opacity-50' : '']"
                            @click="select(item)"
                        >
                            <i v-if="item.icon" class="mdi text-xl leading-none" :class="`mdi-${item.icon}`" aria-hidden="true" />
                            <span class="min-w-0 truncate">{{ item.label }}</span>
                            <i v-if="item.external && item.target === '_blank'" class="mdi mdi-open-in-new ml-auto text-base leading-none text-cap-text-soft" aria-hidden="true" />
                        </component>
                    </template>
                </slot>
            </div>
        </Transition>
    </Teleport>
</template>
