<script setup>
// Barra de la lista: el único buscador de la pantalla, el filtro segmentado y las acciones.
// Props: search (v-model:search; se emite con 300 ms de espera), searchPlaceholder, searchLabel,
//        hideSearch, filters ([{ label, value, count? }], de 2 a 5: pinta `CapSegmented`),
//        filter (v-model:filter), filterLabel (nombre accesible del segmentado).
// Slots: filters (selectores extra: fechas, `SelectInput` para más de 5 opciones), actions.
import { onBeforeUnmount, ref, watch } from 'vue';
import CapSegmented from '@design/vue/CapSegmented.vue';
import { iconButton, inputClass } from '@/lib/ui.js';

const props = defineProps({
    search: { type: String, default: '' },
    searchPlaceholder: { type: String, default: 'Buscar…' },
    searchLabel: { type: String, default: 'Buscar en la lista' },
    hideSearch: { type: Boolean, default: false },
    filters: { type: Array, default: () => [] },
    filter: { type: [String, Number], default: null },
    filterLabel: { type: String, default: 'Filtrar' },
});

const emit = defineEmits(['update:search', 'update:filter']);

const DEBOUNCE_MS = 300;

const term = ref(props.search);
let timer = null;

watch(
    () => props.search,
    (value) => {
        if (value !== term.value) term.value = value;
    },
);

function onInput(event) {
    term.value = event.target.value;
    clearTimeout(timer);
    timer = setTimeout(() => emit('update:search', term.value), DEBOUNCE_MS);
}

function clear() {
    clearTimeout(timer);
    term.value = '';
    emit('update:search', '');
}

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <div class="flex flex-wrap items-center gap-3">
        <div v-if="!hideSearch" class="relative w-full sm:w-72">
            <i class="mdi mdi-magnify pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xl leading-none text-cap-text-soft" aria-hidden="true" />
            <input type="search" :value="term" :placeholder="searchPlaceholder" :aria-label="searchLabel" :class="[inputClass(), 'h-10 pl-10 pr-10 [&::-webkit-search-cancel-button]:hidden']" @input="onInput" @keydown.esc="clear">
            <button v-if="term" type="button" aria-label="Limpiar búsqueda" title="Limpiar búsqueda" :class="[iconButton, 'absolute right-0.5 top-1/2 -translate-y-1/2']" @click="clear">
                <i class="mdi mdi-close text-xl leading-none" aria-hidden="true" />
            </button>
        </div>
        <div v-if="filters.length" class="max-w-full overflow-x-auto">
            <CapSegmented :items="filters" :model-value="filter" :label="filterLabel" @update:model-value="emit('update:filter', $event)" />
        </div>
        <slot name="filters" />
        <div v-if="$slots.actions" class="ml-auto flex flex-wrap items-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
