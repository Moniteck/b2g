<script setup>
// Barra lateral del marco persistente (escritorio y tableta ≥ 1024 px). Usa las clases del sistema de
// diseño (`cap-shell-sidebar*`) y las correcciones de `resources/css/app.css` (bloque «Barra lateral»).
//
// - Panel de 236 px o riel de 66 px. Por defecto: expandida desde 1440 px y riel por debajo; la
//   preferencia del usuario se recuerda (misma clave que el sistema de diseño).
// - El riel se «asoma» con el cursor o el teclado (bindSidebarPeek). En pantallas táctiles, tocar un
//   ítem con subnivel abre el panel asomado con ese grupo desplegado; tocar fuera lo cierra.
// - Grupos con subnivel plegable (<details>), abiertos si contienen la página actual; el resto
//   recuerda si se abrió.
// - El ítem activo siempre queda a la vista (también en el riel) y la lista se difumina arriba y
//   abajo cuando hay más ítems de los que caben.
// Props: menu (de visibleMenu), user, owner, mark, systemName. Eventos: logout.
import { Link } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import CapAvatar from '../../../design/vue/CapAvatar.vue';
import { iconClass } from '../../../design/vue/helpers.js';
import { bindSidebarPeek } from '../../../design/shell/js/collapse.js';
import { readCollapsed, readItem, writeCollapsed, writeItem } from '../../../design/shell/js/storage.js';
import { toggleTheme } from '../../../design/shell/js/theme.js';

const props = defineProps({
    systemName: { type: String, required: true },
    owner: { type: String, default: '' },
    mark: { type: String, default: '' },
    menu: { type: Array, required: true },
    user: { type: Object, required: true },
});

const emit = defineEmits(['logout']);

const EXPANDED_FROM = 1440;
const OPEN_KEY = 'crm:sidebar-open-groups';

const sidebar = ref(null);
const nav = ref(null);
const collapsed = ref(typeof window === 'undefined' ? false : (readCollapsed() ?? window.innerWidth < EXPANDED_FROM));
const fade = ref({ top: false, bottom: false });

function toggleCollapsed() {
    collapsed.value = !collapsed.value;
    writeCollapsed(collapsed.value);
    if (sidebar.value) delete sidebar.value.dataset.peek;
    nextTick(revealActive);
}

// Subniveles abiertos: el del ítem activo siempre; los demás, según lo que el usuario dejó abierto.
const remembered = ref(new Set(parseOpen(readItem(OPEN_KEY))));
function parseOpen(value) {
    try {
        return JSON.parse(value ?? '[]');
    } catch {
        return [];
    }
}
const isOpen = (item) => item.active || remembered.value.has(item.label);

function onToggle(item, event) {
    const open = event.target.open;
    if (open === isOpen(item)) return;
    const next = new Set(remembered.value);
    if (open) next.add(item.label);
    else next.delete(item.label);
    remembered.value = next;
    writeItem(OPEN_KEY, JSON.stringify([...next]));
}

const isRail = () => sidebar.value?.dataset.collapsed === 'true' && !sidebar.value?.dataset.peek;

// En el riel, tocar un grupo con subnivel abre el panel asomado con el grupo desplegado.
function onSummaryClick(item, event) {
    if (!isRail()) return;
    event.preventDefault();
    sidebar.value.dataset.peek = 'open';
    const details = event.currentTarget.closest('details');
    if (details) details.open = true;
    if (!remembered.value.has(item.label) && !item.active) {
        remembered.value = new Set([...remembered.value, item.label]);
    }
}

// Toque fuera del panel asomado: se cierra (el ratón ya lo cierra al salir).
function onDocumentPointerDown(event) {
    if (sidebar.value?.dataset.peek && !sidebar.value.contains(event.target)) delete sidebar.value.dataset.peek;
}

// Al llegar a una página, el panel asomado se cierra.
function closePeek() {
    if (sidebar.value) delete sidebar.value.dataset.peek;
}

/** Hace visible el ítem activo dentro de la lista sin mover la ventana. */
function revealActive() {
    const box = nav.value;
    if (!box) return;
    const candidates = [...box.querySelectorAll('[aria-current="page"], [data-active="true"]')].filter((element) => element.getClientRects().length);
    const target = candidates.at(-1);
    if (!target) return updateFade();
    const boxRect = box.getBoundingClientRect();
    const rect = target.getBoundingClientRect();
    const margin = 28;
    if (rect.top < boxRect.top + margin) box.scrollTop -= boxRect.top + margin - rect.top;
    else if (rect.bottom > boxRect.bottom - margin) box.scrollTop += rect.bottom - (boxRect.bottom - margin);
    updateFade();
}

function updateFade() {
    const box = nav.value;
    if (!box) return;
    fade.value = { top: box.scrollTop > 2, bottom: box.scrollTop + box.clientHeight < box.scrollHeight - 2 };
}

const activeKey = computed(() =>
    props.menu
        .flatMap((group) => group.items)
        .filter((item) => item.active)
        .map((item) => `${item.href}|${item.children.find((child) => child.active)?.href ?? ''}`)
        .join(),
);

watch(activeKey, () => {
    closePeek();
    nextTick(revealActive);
});

let unbindPeek = () => {};
let resizeObserver = null;
onMounted(() => {
    unbindPeek = bindSidebarPeek(sidebar.value);
    document.addEventListener('pointerdown', onDocumentPointerDown, true);
    resizeObserver = new ResizeObserver(() => updateFade());
    resizeObserver.observe(nav.value);
    // Al asomarse o colapsarse cambia lo que cabe: se recalcula el difuminado.
    new MutationObserver(() => requestAnimationFrame(revealActive)).observe(sidebar.value, { attributes: true, attributeFilter: ['data-peek', 'data-collapsed'] });
    revealActive();
});

onBeforeUnmount(() => {
    unbindPeek();
    document.removeEventListener('pointerdown', onDocumentPointerDown, true);
    resizeObserver?.disconnect();
});

const railActive = (item) => item.active && item.children.length > 0;
</script>

<template>
    <aside
        ref="sidebar"
        class="cap-shell-sidebar app-sidebar cap-glass"
        :data-collapsed="String(collapsed)"
        :data-fade-top="fade.top ? 'true' : undefined"
        :data-fade-bottom="fade.bottom ? 'true' : undefined"
        aria-label="Barra lateral"
    >
        <button
            class="cap-shell-sidebar__collapse"
            type="button"
            data-cap-collapse
            :aria-expanded="String(!collapsed)"
            :aria-label="collapsed ? 'Expandir menú' : 'Contraer menú'"
            :title="collapsed ? 'Expandir menú' : 'Contraer menú'"
            @click="toggleCollapsed"
        >
            <i class="mdi mdi-chevron-left" aria-hidden="true" />
        </button>

        <Link href="/" class="cap-shell-sidebar__brand app-sidebar__brand" :aria-label="`${systemName}: ir al Dashboard`">
            <span class="cap-shell-logo" aria-hidden="true">{{ mark }}</span>
            <span class="cap-shell-sidebar__brand-text">
                <strong class="cap-shell-sidebar__brand-name">{{ systemName }}</strong>
                <small class="cap-shell-sidebar__brand-owner">{{ owner }}</small>
            </span>
        </Link>

        <nav ref="nav" class="cap-shell-sidebar__nav" aria-label="Menú principal" @scroll.passive="updateFade">
            <template v-for="(group, index) in menu" :key="group.label">
                <p class="cap-shell-sidebar__group-label" :class="{ 'app-sidebar__group-label--first': index === 0 }">
                    <span>{{ group.label }}</span>
                </p>
                <template v-for="item in group.items" :key="item.label">
                    <details v-if="item.children.length" class="cap-shell-sidebar__branch" :open="isOpen(item)" @toggle="onToggle(item, $event)">
                        <summary
                            class="cap-shell-sidebar__item"
                            :data-active="railActive(item) ? 'true' : undefined"
                            :aria-label="item.label"
                            @click="onSummaryClick(item, $event)"
                        >
                            <i class="cap-shell-sidebar__icon mdi" :class="iconClass(item)" aria-hidden="true" />
                            <span class="cap-shell-sidebar__label">{{ item.label }}</span>
                            <i class="cap-shell-sidebar__chevron mdi mdi-chevron-down" aria-hidden="true" />
                        </summary>
                        <div class="cap-shell-sidebar__children">
                            <Link
                                v-for="child in item.children"
                                :key="child.href"
                                class="cap-shell-sidebar__child"
                                :href="child.href"
                                :aria-current="child.active ? 'page' : undefined"
                            >{{ child.label }}</Link>
                        </div>
                    </details>
                    <Link v-else class="cap-shell-sidebar__item" :href="item.href" :aria-current="item.active ? 'page' : undefined" :aria-label="item.label">
                        <i class="cap-shell-sidebar__icon mdi" :class="iconClass(item)" aria-hidden="true" />
                        <span class="cap-shell-sidebar__label">{{ item.label }}</span>
                    </Link>
                </template>
            </template>
        </nav>

        <div class="cap-shell-sidebar__spacer" />

        <button class="cap-shell-sidebar__item cap-shell-sidebar__theme" type="button" aria-label="Alternar modo oscuro" @click="toggleTheme">
            <i class="cap-shell-sidebar__icon mdi mdi-weather-night" aria-hidden="true" />
            <span class="cap-shell-sidebar__label">Modo oscuro</span>
            <span class="cap-shell-sidebar__switch" aria-hidden="true" />
        </button>

        <div class="cap-shell-sidebar__user">
            <CapAvatar :user="user" />
            <div class="cap-shell-sidebar__user-text">
                <strong class="cap-shell-sidebar__user-name">{{ user.name }}</strong>
                <small class="cap-shell-sidebar__user-mail">{{ user.email }}</small>
            </div>
            <button class="cap-shell-sidebar__user-menu" type="button" aria-label="Cerrar sesión" title="Cerrar sesión" @click="emit('logout')">
                <i class="mdi mdi-logout" aria-hidden="true" />
            </button>
        </div>
    </aside>
</template>
