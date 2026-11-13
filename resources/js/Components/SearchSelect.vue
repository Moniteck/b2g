<script setup>
// Combo con búsqueda, local o remota (instituciones, contactos, inventario, responsables).
// Props: modelValue (v-model: el valor elegido o null),
//        options (lista local: [{ value, label, description? }] u objetos con otras claves),
//        url (búsqueda remota: GET url?q=texto → lista u objeto { data: [...] }) o
//        search (función async (texto) → lista; manda sobre `url`),
//        optionValue ('value' u otra clave, p. ej. 'id'), optionLabel ('label' o 'name'),
//        optionDescription (clave de la segunda línea), selected (objeto de la opción ya elegida,
//        para mostrar su texto en modo remoto), placeholder, minChars (remoto: 2), params (extra del GET),
//        clearable, disabled, invalid, id, required.
// Slots: option ({ option, active, selected }); empty.
// Eventos: update:modelValue, select (opción completa o null).
// Teclado: ↓ ↑ recorren, Enter elige, Esc cierra, Inicio/Fin saltan.
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import { useField } from '../composables/useField.js';
import { onPointerOutside, useFloating } from '../composables/useFloating.js';
import { getJson } from '../lib/http.js';
import { floatingPanel, iconButton, inputClass } from '../lib/ui.js';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    modelValue: { type: [String, Number], default: null },
    options: { type: Array, default: () => [] },
    url: { type: String, default: '' },
    search: { type: Function, default: null },
    optionValue: { type: String, default: 'value' },
    optionLabel: { type: String, default: 'label' },
    optionDescription: { type: String, default: 'description' },
    selected: { type: Object, default: null },
    placeholder: { type: String, default: 'Buscar…' },
    minChars: { type: Number, default: 2 },
    params: { type: Object, default: () => ({}) },
    clearable: { type: Boolean, default: true },
    disabled: { type: Boolean, default: false },
    invalid: { type: Boolean, default: false },
    id: { type: String, default: '' },
    required: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'select']);

const DEBOUNCE_MS = 250;
const MAX_LOCAL_RESULTS = 50;

const { id: fieldId, invalid: isInvalid, aria } = useField(props);
const listId = useId();
const anchor = ref(null);
const input = ref(null);
const list = ref(null);
const open = ref(false);
const query = ref('');
const activeIndex = ref(-1);
const remoteOptions = ref([]);
const loading = ref(false);
const failed = ref('');
const chosen = ref(props.selected);
let debounceId = null;
let abortController = null;
let releaseOutside = null;

const isRemote = computed(() => Boolean(props.search || props.url));
const { style, teleportTarget } = useFloating(anchor, list, open, { matchWidth: true });

const valueOf = (option) => option?.[props.optionValue];
const labelOf = (option) => String(option?.[props.optionLabel] ?? '');
const descriptionOf = (option) => option?.[props.optionDescription] ?? '';
const normalize = (text) => String(text).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

const selectedOption = computed(() => {
    if (props.modelValue === null || props.modelValue === undefined || props.modelValue === '') return null;
    const pool = [...props.options, ...remoteOptions.value, chosen.value, props.selected].filter(Boolean);
    return pool.find((option) => String(valueOf(option)) === String(props.modelValue)) ?? null;
});

const visibleOptions = computed(() => {
    if (isRemote.value) return remoteOptions.value;
    const needle = normalize(query.value.trim());
    const matches = needle ? props.options.filter((option) => normalize(`${labelOf(option)} ${descriptionOf(option)}`).includes(needle)) : props.options;
    return matches.slice(0, MAX_LOCAL_RESULTS);
});

const needsMoreChars = computed(() => isRemote.value && query.value.trim().length < props.minChars);
const displayValue = computed(() => (open.value ? query.value : selectedOption.value ? labelOf(selectedOption.value) : ''));
const activeId = computed(() => (activeIndex.value >= 0 ? `${listId}-${activeIndex.value}` : undefined));

async function runRemoteSearch() {
    const term = query.value.trim();
    abortController?.abort();
    if (term.length < props.minChars) {
        remoteOptions.value = [];
        loading.value = false;
        return;
    }
    loading.value = true;
    failed.value = '';
    abortController = new AbortController();
    const { signal } = abortController;
    try {
        const result = props.search ? await props.search(term, { signal }) : await getJson(props.url, { ...props.params, q: term }, { signal });
        if (signal.aborted) return;
        remoteOptions.value = Array.isArray(result) ? result : (result?.data ?? []);
        activeIndex.value = remoteOptions.value.length ? 0 : -1;
    } catch (error) {
        if (signal.aborted || error?.name === 'AbortError') return;
        remoteOptions.value = [];
        failed.value = 'No se pudo buscar. Revisa tu conexión y vuelve a escribir.';
    } finally {
        if (!signal.aborted) loading.value = false;
    }
}

function onInput(event) {
    query.value = event.target.value;
    open.value = true;
    activeIndex.value = 0;
    if (!isRemote.value) return;
    clearTimeout(debounceId);
    loading.value = query.value.trim().length >= props.minChars;
    debounceId = setTimeout(runRemoteSearch, DEBOUNCE_MS);
}

function openList() {
    if (props.disabled || open.value) return;
    query.value = '';
    open.value = true;
    const index = visibleOptions.value.findIndex((option) => String(valueOf(option)) === String(props.modelValue));
    activeIndex.value = index >= 0 ? index : visibleOptions.value.length ? 0 : -1;
}

function closeList() {
    open.value = false;
    query.value = '';
    clearTimeout(debounceId);
}

function choose(option) {
    chosen.value = option;
    emit('update:modelValue', option ? valueOf(option) : null);
    emit('select', option);
    closeList();
}

function clear() {
    choose(null);
    input.value?.focus();
}

function move(step) {
    const count = visibleOptions.value.length;
    if (!count) return;
    activeIndex.value = (activeIndex.value + step + count) % count;
    nextTick(() => list.value?.querySelector('[aria-selected="true"]')?.scrollIntoView({ block: 'nearest' }));
}

function onKeydown(event) {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        if (!open.value) return openList();
        move(event.key === 'ArrowDown' ? 1 : -1);
    } else if (event.key === 'Enter') {
        if (!open.value) return;
        event.preventDefault();
        const option = visibleOptions.value[activeIndex.value];
        if (option) choose(option);
    } else if (event.key === 'Escape') {
        if (!open.value) return;
        event.preventDefault();
        event.stopPropagation();
        closeList();
    } else if (event.key === 'Home' && open.value) {
        event.preventDefault();
        activeIndex.value = 0;
    } else if (event.key === 'End' && open.value) {
        event.preventDefault();
        activeIndex.value = visibleOptions.value.length - 1;
    } else if (event.key === 'Tab') {
        closeList();
    }
}

watch(open, (isOpen) => {
    releaseOutside?.();
    releaseOutside = isOpen ? onPointerOutside(() => [anchor.value, list.value], closeList) : null;
});

watch(
    () => props.selected,
    (option) => {
        if (option) chosen.value = option;
    },
);

onBeforeUnmount(() => {
    releaseOutside?.();
    clearTimeout(debounceId);
    abortController?.abort();
});

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <div ref="anchor" class="relative" :class="$attrs.class">
        <i class="mdi mdi-magnify pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xl leading-none text-cap-text-soft" aria-hidden="true" />
        <input
            :id="fieldId"
            ref="input"
            v-bind="{ ...$attrs, ...aria, class: undefined }"
            type="text"
            role="combobox"
            autocomplete="off"
            aria-autocomplete="list"
            :aria-expanded="open ? 'true' : 'false'"
            :aria-controls="listId"
            :aria-activedescendant="open ? activeId : undefined"
            :value="displayValue"
            :placeholder="selectedOption && open ? labelOf(selectedOption) : placeholder"
            :disabled="disabled"
            :class="[inputClass({ invalid: isInvalid }), 'h-10 truncate pl-10', clearable && selectedOption ? 'pr-16' : 'pr-9']"
            @click="openList"
            @input="onInput"
            @keydown="onKeydown"
        >
        <div class="absolute right-0.5 top-1/2 flex -translate-y-1/2 items-center">
            <button v-if="clearable && selectedOption && !disabled" type="button" aria-label="Quitar selección" title="Quitar selección" :class="[iconButton, 'h-8 w-8']" @click="clear">
                <i class="mdi mdi-close text-base leading-none" aria-hidden="true" />
            </button>
            <span class="pointer-events-none flex h-8 w-8 items-center justify-center text-cap-text-soft" aria-hidden="true">
                <i class="mdi mdi-chevron-down text-xl leading-none" />
            </span>
        </div>
    </div>
    <Teleport :to="teleportTarget">
        <Transition
            enter-active-class="transition duration-cap-fast ease-cap-enter"
            enter-from-class="opacity-0 scale-[0.98]"
            leave-active-class="transition-opacity duration-cap-fast ease-cap-exit"
            leave-to-class="opacity-0"
        >
            <div v-if="open" :style="style" class="z-cap-dropdown origin-top overflow-hidden" :class="floatingPanel">
                <ul :id="listId" ref="list" role="listbox" class="max-h-64 overflow-y-auto py-1" @mousedown.prevent>
                    <li v-if="needsMoreChars" class="px-3 py-2 text-sm text-cap-text-soft" role="presentation">Escribe al menos {{ minChars }} letras para buscar.</li>
                    <li v-else-if="loading" class="space-y-2 px-3 py-2" role="presentation" aria-label="Buscando">
                        <span class="cap-skeleton block w-3/4" /><span class="cap-skeleton block w-1/2" />
                    </li>
                    <li v-else-if="failed" class="px-3 py-2 text-sm text-cap-status-error-ink" role="alert">{{ failed }}</li>
                    <li v-else-if="visibleOptions.length === 0" class="px-3 py-2 text-sm text-cap-text-soft" role="presentation">
                        <slot name="empty">Ninguna opción coincide con «{{ query }}».</slot>
                    </li>
                    <template v-else>
                        <li
                            v-for="(option, index) in visibleOptions"
                            :id="`${listId}-${index}`"
                            :key="String(valueOf(option))"
                            role="option"
                            :aria-selected="index === activeIndex ? 'true' : 'false'"
                            class="flex min-h-10 cursor-pointer items-center gap-2 px-3 py-2 text-sm text-gray-800 dark:text-gray-100"
                            :class="index === activeIndex ? 'bg-cap-card-2 dark:bg-cap-card' : ''"
                            @mousemove="activeIndex = index"
                            @click="choose(option)"
                        >
                            <slot name="option" :option="option" :active="index === activeIndex" :selected="String(valueOf(option)) === String(modelValue)">
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate">{{ labelOf(option) }}</span>
                                    <span v-if="descriptionOf(option)" class="block truncate text-xs text-cap-text-soft">{{ descriptionOf(option) }}</span>
                                </span>
                            </slot>
                            <i v-if="String(valueOf(option)) === String(modelValue)" class="mdi mdi-check shrink-0 text-base leading-none text-cap-brand dark:text-cap-brand-soft" aria-hidden="true" />
                        </li>
                    </template>
                </ul>
            </div>
        </Transition>
    </Teleport>
</template>
