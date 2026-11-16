<script setup>
// Hoja «Más» del móvil: buscador, el resto del menú (sin repetir las 4 pestañas de abajo) con los
// subniveles plegables, lo personal (cuenta, firma, recordatorios, MCP), el tema y cerrar sesión.
// Cerrada queda `inert` (fuera del orden de tabulación y del árbol de accesibilidad).
import { Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, toRef } from 'vue';
import CapAvatar from '../../../design/vue/CapAvatar.vue';
import { useEscapeKey } from '../../../design/vue/composables/useEscapeKey.js';
import { useFocusTrap } from '../../../design/vue/composables/useFocusTrap.js';
import { iconClass } from '../../../design/vue/helpers.js';
import { createDragToClose } from '../../../design/shell/js/sheet.js';
import { toggleTheme } from '../../../design/shell/js/theme.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    menu: { type: Array, default: () => [] },
    account: { type: Array, default: () => [] },
    hiddenHrefs: { type: Array, default: () => [] },
    user: { type: Object, required: true },
    roleLabel: { type: String, default: '' },
});

const emit = defineEmits(['close', 'logout', 'search']);

const sheetRef = ref(null);
const isOpen = toRef(props, 'open');

useFocusTrap(sheetRef, isOpen);
useEscapeKey(isOpen, () => emit('close'));

const drag = createDragToClose(() => props.open, () => emit('close'));

const groups = computed(() =>
    props.menu
        .map((group) => ({ ...group, items: group.items.filter((item) => item.children.length || !props.hiddenHrefs.includes(item.href)) }))
        .filter((group) => group.items.length),
);

const closeAfterNavigation = (event) => {
    if (event.target.closest('a')) emit('close');
};

onMounted(() => document.addEventListener('pointerup', drag.end));
onBeforeUnmount(() => document.removeEventListener('pointerup', drag.end));
</script>

<template>
    <div
        id="cap-more-sheet"
        ref="sheetRef"
        class="cap-shell-sheet app-more-sheet"
        role="dialog"
        aria-modal="true"
        aria-label="Menú completo"
        :data-open="String(open)"
        :inert="!open"
        :aria-hidden="open ? undefined : 'true'"
    >
        <div class="cap-shell-sheet__backdrop" @click="emit('close')" />
        <div class="cap-shell-sheet__panel cap-glass" @click="closeAfterNavigation">
            <div class="cap-shell-sheet__handle" @pointerdown="drag.start" />

            <button type="button" class="app-more-sheet__search" @click="emit('search')">
                <i class="mdi mdi-magnify" aria-hidden="true" />
                <span>Buscar expedientes, cotizaciones, clientes…</span>
            </button>

            <template v-for="group in groups" :key="group.label">
                <p class="cap-shell-sidebar__group-label">{{ group.label }}</p>
                <template v-for="item in group.items" :key="item.label">
                    <details v-if="item.children.length" class="app-more-sheet__branch" :open="item.active">
                        <summary class="cap-shell-sidebar__item" :data-active="item.active ? 'true' : undefined">
                            <i class="cap-shell-sidebar__icon mdi" :class="iconClass(item)" aria-hidden="true" />
                            <span class="min-w-0 flex-1 truncate">{{ item.label }}</span>
                            <i class="mdi mdi-chevron-down app-more-sheet__chevron" aria-hidden="true" />
                        </summary>
                        <div class="app-more-sheet__children">
                            <Link
                                v-for="child in item.children"
                                :key="child.href"
                                class="app-more-sheet__child"
                                :href="child.href"
                                :aria-current="child.active ? 'page' : undefined"
                            >{{ child.label }}</Link>
                        </div>
                    </details>
                    <Link v-else class="cap-shell-sidebar__item" :href="item.href" :aria-current="item.active ? 'page' : undefined">
                        <i class="cap-shell-sidebar__icon mdi" :class="iconClass(item)" aria-hidden="true" />
                        <span class="min-w-0 flex-1 truncate">{{ item.label }}</span>
                    </Link>
                </template>
            </template>

            <p class="cap-shell-sidebar__group-label">Tu cuenta</p>
            <Link v-for="entry in account" :key="entry.href" class="cap-shell-sidebar__item" :href="entry.href">
                <i class="cap-shell-sidebar__icon mdi" :class="`mdi-${entry.icon}`" aria-hidden="true" />
                <span class="min-w-0 flex-1 truncate">{{ entry.label }}</span>
            </Link>
            <button class="cap-shell-sidebar__item" type="button" aria-label="Alternar modo oscuro" @click="toggleTheme">
                <i class="cap-shell-sidebar__icon mdi mdi-weather-night" aria-hidden="true" />
                <span class="min-w-0 flex-1 truncate">Modo oscuro</span>
                <span class="cap-shell-sidebar__switch" aria-hidden="true" />
            </button>
            <div class="cap-shell-sidebar__user">
                <CapAvatar :user="user" />
                <div class="cap-shell-sidebar__user-text">
                    <strong class="cap-shell-sidebar__user-name">{{ user.name }}</strong>
                    <small class="cap-shell-sidebar__user-mail">{{ roleLabel || user.email }}</small>
                </div>
                <button class="cap-shell-sidebar__user-menu" type="button" aria-label="Cerrar sesión" @click="emit('logout')">
                    <i class="mdi mdi-logout" aria-hidden="true" />
                </button>
            </div>
        </div>
    </div>
</template>
