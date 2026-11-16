<script setup>
// Paleta de comandos y búsqueda global (Ctrl/⌘ K, botón de la cabecera, lupa del móvil).
// - Busca en el servidor (`GET /buscar?q=`, empresa activa, respeta permisos) licitaciones,
//   oportunidades, cotizaciones, clientes, contratos, documentos, instituciones y contactos.
// - También acciones («Nueva cotización», «Importar captura»…) y pantallas («Ir a Calendario»),
//   filtradas al instante en el navegador.
// - Teclado: ↑ ↓ recorren, Enter abre, Esc cierra. Recientes guardados en este navegador por usuario
//   (se borran al cerrar sesión).
// En móvil ocupa toda la pantalla.
// Props: open (v-model), auth. Eventos: create-company.
import { router } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import { trapFocus } from '@design/shell/js/focus-trap.js';
import { toggleTheme } from '@design/shell/js/theme.js';
import { endpoints, navigationTargets } from '@/config/navigation.js';
import { getJson } from '@/lib/http.js';
import { useShell } from '@/Layouts/shell.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    auth: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['update:open', 'create-company']);

const shell = useShell();
const listId = useId();
const panel = ref(null);
const input = ref(null);
const query = ref('');
const remote = ref([]);
const loading = ref(false);
const failed = ref(false);
const activeIndex = ref(0);
let releaseFocus = null;
let controller = null;
let timer = 0;

const can = computed(() => props.auth?.can ?? {});
const isRoot = computed(() => Boolean(props.auth?.user?.is_root));
const recentKey = computed(() => `crm:search-recent:${props.auth?.user?.id ?? 'anon'}`);

// ───────── Acciones ─────────
const ACTIONS = [
    { label: 'Nueva licitación', icon: 'plus', href: '/licitaciones', fab: true, can: 'create', keywords: 'crear proceso' },
    { label: 'Importar captura de HonduCompras', icon: 'text-recognition', href: '/licitaciones', press: 'Importar captura', can: 'create', keywords: 'ocr imagen licitacion' },
    { label: 'Nueva cotización', icon: 'file-document-plus-outline', href: '/cotizaciones', fab: true, can: 'create', keywords: 'crear oferta' },
    { label: 'Nueva oportunidad', icon: 'view-column-outline', href: '/pipeline', fab: true, can: 'create', keywords: 'crear pipeline' },
    { label: 'Nuevo evento en el calendario', icon: 'calendar-plus', href: '/calendario', fab: true, can: 'create', keywords: 'crear cita reunion' },
    { label: 'Nuevo contacto', icon: 'card-account-phone-outline', href: '/contactos', fab: true, can: 'create', keywords: 'crear persona' },
    { label: 'Nueva empresa', icon: 'domain-plus', company: true, root: true, keywords: 'crear empresa grupo' },
    { label: 'Cambiar entre modo claro y oscuro', icon: 'theme-light-dark', theme: true, keywords: 'tema oscuro claro' },
];

const actions = computed(() =>
    ACTIONS.filter((action) => (!action.root || isRoot.value) && (!action.can || isRoot.value || can.value[action.can] !== false)).map((action) => ({ ...action, kind: 'action' })),
);
const screens = computed(() => navigationTargets(props.auth).map((target) => ({ ...target, kind: 'screen', label: target.label, hint: target.group })));

const normalize = (value) => String(value ?? '').normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase();
const matches = (item, term) => normalize(`${item.label} ${item.keywords ?? ''} ${item.hint ?? ''}`).includes(term);

// ───────── Recientes ─────────
const recents = ref([]);
function loadRecents() {
    try {
        recents.value = JSON.parse(localStorage.getItem(recentKey.value) ?? '[]').slice(0, 6);
    } catch {
        recents.value = [];
    }
}
function remember(item) {
    if (!item.href || item.kind === 'action') return;
    const entry = { title: item.title ?? item.label, subtitle: item.subtitle ?? item.hint ?? '', href: item.href, icon: item.icon };
    const next = [entry, ...recents.value.filter((recent) => recent.href !== entry.href)].slice(0, 6);
    recents.value = next;
    try {
        localStorage.setItem(recentKey.value, JSON.stringify(next));
    } catch {
        // Recientes opcionales.
    }
}

// ───────── Secciones visibles ─────────
const sections = computed(() => {
    const term = normalize(query.value.trim());
    if (!term) {
        return [
            recents.value.length ? { label: 'Recientes', items: recents.value.map((recent) => ({ ...recent, kind: 'recent' })) } : null,
            { label: 'Acciones', items: actions.value.slice(0, 6) },
            { label: 'Ir a', items: screens.value.slice(0, 8) },
        ].filter(Boolean);
    }
    const found = [
        { label: 'Acciones', items: actions.value.filter((item) => matches(item, term)).slice(0, 4) },
        { label: 'Pantallas', items: screens.value.filter((item) => matches(item, term)).slice(0, 5) },
        ...remote.value.map((group) => ({ label: group.label, items: group.items.map((item) => ({ ...item, kind: 'record' })) })),
    ];
    return found.filter((section) => section.items.length);
});

const flat = computed(() => sections.value.flatMap((section) => section.items));
const isEmpty = computed(() => query.value.trim().length >= 2 && !loading.value && !failed.value && flat.value.length === 0);

watch(flat, () => {
    if (activeIndex.value >= flat.value.length) activeIndex.value = 0;
});

// ───────── Búsqueda remota ─────────
watch(query, (value) => {
    activeIndex.value = 0;
    clearTimeout(timer);
    controller?.abort();
    const term = value.trim();
    if (term.length < 2) {
        remote.value = [];
        loading.value = false;
        failed.value = false;
        return;
    }
    loading.value = true;
    timer = window.setTimeout(async () => {
        controller = new AbortController();
        try {
            const response = await getJson(endpoints.search, { q: term }, { signal: controller.signal });
            if (response.query === term || value.trim() === term) remote.value = response.groups ?? [];
            failed.value = false;
            loading.value = false;
        } catch (error) {
            if (error?.name === 'AbortError') return;
            failed.value = true;
            loading.value = false;
        }
    }, 140);
});

// ───────── Abrir y cerrar ─────────
function close() {
    emit('update:open', false);
}

watch(
    () => props.open,
    async (open) => {
        if (open) {
            loadRecents();
            query.value = '';
            remote.value = [];
            activeIndex.value = 0;
            await nextTick();
            if (panel.value) releaseFocus = trapFocus(panel.value, input.value);
        } else {
            releaseFocus?.();
            releaseFocus = null;
            controller?.abort();
        }
    },
    { immediate: true, flush: 'post' },
);

onBeforeUnmount(() => {
    releaseFocus?.();
    clearTimeout(timer);
    controller?.abort();
});

// ───────── Ejecutar ─────────
function pressInPage(text) {
    const button = [...document.querySelectorAll('#contenido button')].find((candidate) => candidate.textContent.trim().startsWith(text) && candidate.offsetParent !== null);
    button?.click();
}

function run(item) {
    if (!item) return;
    close();
    if (item.theme) return toggleTheme();
    if (item.company) return emit('create-company');
    remember(item);
    const after = item.fab ? () => shell?.onFab?.() : item.press ? () => pressInPage(item.press) : null;
    const here = window.location.pathname === item.href;
    if (here && after) return nextTick(after);
    router.visit(item.href, { onSuccess: () => after && setTimeout(after, 60) });
}

function onKeydown(event) {
    const count = flat.value.length;
    if (event.key === 'ArrowDown' && count) {
        activeIndex.value = (activeIndex.value + 1) % count;
    } else if (event.key === 'ArrowUp' && count) {
        activeIndex.value = (activeIndex.value - 1 + count) % count;
    } else if (event.key === 'Enter') {
        run(flat.value[activeIndex.value]);
    } else if (event.key === 'Escape') {
        close();
    } else {
        return;
    }
    event.preventDefault();
    nextTick(() => panel.value?.querySelector('[aria-selected="true"]')?.scrollIntoView({ block: 'nearest' }));
}

const indexOf = (item) => flat.value.indexOf(item);
const optionId = (index) => `${listId}-${index}`;
const titleOf = (item) => item.title ?? item.label;
const subtitleOf = (item) => item.subtitle ?? item.hint ?? '';
</script>

<template>
    <Teleport to="body">
        <Transition name="m-fade">
            <div v-if="open" class="app-palette" data-floating-root @keydown="onKeydown">
                <div class="app-palette__backdrop" aria-hidden="true" @click="close" />
                <div ref="panel" class="app-palette__panel m-modal-panel" role="dialog" aria-modal="true" aria-label="Buscar en el CRM">
                    <div class="app-palette__field">
                        <i class="mdi mdi-magnify text-xl leading-none text-cap-text-soft" aria-hidden="true" />
                        <input
                            ref="input"
                            v-model="query"
                            type="search"
                            class="app-palette__input"
                            placeholder="Buscar expedientes, cotizaciones, clientes… o una acción"
                            role="combobox"
                            aria-autocomplete="list"
                            :aria-expanded="flat.length ? 'true' : 'false'"
                            :aria-controls="listId"
                            :aria-activedescendant="flat.length ? optionId(activeIndex) : undefined"
                            autocomplete="off"
                            spellcheck="false"
                            enterkeyhint="go"
                        />
                        <span v-if="loading" class="app-palette__spinner mdi mdi-loading mdi-spin text-cap-text-soft" aria-hidden="true" />
                        <button type="button" class="app-palette__close" aria-label="Cerrar la búsqueda" @click="close">
                            <span class="max-md:hidden">Esc</span><i class="mdi mdi-close md:hidden" aria-hidden="true" />
                        </button>
                    </div>

                    <div :id="listId" class="app-palette__results" role="listbox" aria-label="Resultados">
                        <template v-for="section in sections" :key="section.label">
                            <p class="app-palette__group" role="presentation">{{ section.label }}</p>
                            <div
                                v-for="item in section.items"
                                :id="optionId(indexOf(item))"
                                :key="`${section.label}-${item.href ?? item.label}-${item.id ?? ''}`"
                                class="app-palette__option"
                                role="option"
                                :aria-selected="indexOf(item) === activeIndex ? 'true' : 'false'"
                                @pointermove="activeIndex = indexOf(item)"
                                @click="run(item)"
                            >
                                <i class="mdi app-palette__icon" :class="`mdi-${item.icon ?? 'arrow-right'}`" aria-hidden="true" />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-medium text-gray-900 dark:text-gray-100">{{ titleOf(item) }}</span>
                                    <span v-if="subtitleOf(item)" class="block truncate text-xs text-cap-text-soft">{{ subtitleOf(item) }}</span>
                                </span>
                                <i class="mdi mdi-keyboard-return app-palette__enter" aria-hidden="true" />
                            </div>
                        </template>
                        <p v-if="isEmpty" class="app-palette__empty">Sin resultados para «{{ query.trim() }}» en la empresa activa.</p>
                        <p v-if="failed" class="app-palette__empty">No se pudo buscar en el servidor. Las acciones y pantallas siguen disponibles.</p>
                        <p v-if="query.trim().length === 1" class="app-palette__empty">Escribe al menos 2 letras para buscar registros.</p>
                    </div>

                    <footer class="app-palette__footer max-md:hidden" aria-hidden="true">
                        <span><kbd>↑</kbd><kbd>↓</kbd> recorrer</span><span><kbd>Enter</kbd> abrir</span><span><kbd>Esc</kbd> cerrar</span>
                    </footer>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
