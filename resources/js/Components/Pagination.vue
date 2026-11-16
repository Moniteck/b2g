<script setup>
// Paginación para los paginadores de Laravel, debajo de la tabla: contador «1–25 de 1,240» a la
// izquierda, tamaño de página y flechas a la derecha.
// Props: paginator (el JSON de `->paginate()`: { current_page, from, to, total, per_page, prev_page_url,
//        next_page_url }, o el de un API Resource con `meta` y `links`), perPageOptions ([25, 50, 100];
//        [] oculta el selector), perPageParam ('per_page'), only (props de Inertia a recargar),
//        preserveState (true: conserva filtros y estado de la página).
// Eventos: navigate (url) después de pedir la página. Sin páginas que mostrar no pinta nada.
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { number } from '@/lib/format.js';
import { iconButton, inputClass } from '@/lib/ui.js';

const props = defineProps({
    paginator: { type: Object, default: null },
    perPageOptions: { type: Array, default: () => [25, 50, 100] },
    perPageParam: { type: String, default: 'per_page' },
    only: { type: Array, default: () => [] },
    preserveState: { type: Boolean, default: true },
});

const emit = defineEmits(['navigate']);

const meta = computed(() => props.paginator?.meta ?? props.paginator ?? {});
const previousUrl = computed(() => props.paginator?.prev_page_url ?? props.paginator?.links?.prev ?? null);
const nextUrl = computed(() => props.paginator?.next_page_url ?? props.paginator?.links?.next ?? null);
const total = computed(() => Number(meta.value.total ?? 0));
const perPage = computed(() => Number(meta.value.per_page ?? 0));
const range = computed(() => (total.value === 0 ? '0 registros' : `${number(meta.value.from ?? 0)}–${number(meta.value.to ?? 0)} de ${number(total.value)}`));
const sizes = computed(() => [...new Set([...props.perPageOptions, perPage.value].filter(Boolean))].sort((a, b) => a - b));
const visible = computed(() => total.value > 0 && (Boolean(previousUrl.value) || Boolean(nextUrl.value) || total.value > Math.min(...sizes.value)));

function go(url) {
    if (!url) return;
    router.visit(url, { preserveScroll: true, preserveState: props.preserveState, ...(props.only.length ? { only: props.only } : {}) });
    emit('navigate', url);
}

function changeSize(event) {
    const url = new URL(window.location.href);
    url.searchParams.set(props.perPageParam, event.target.value);
    url.searchParams.delete('page');
    go(url.pathname + url.search);
}
</script>

<template>
    <nav v-if="visible" aria-label="Paginación" class="mt-3 flex flex-wrap items-center justify-between gap-3 text-sm text-cap-text-soft">
        <p class="tabular-nums">{{ range }}</p>
        <div class="flex items-center gap-2">
            <label v-if="perPageOptions.length" class="flex items-center gap-2">
                Por página
                <select :value="perPage" :class="[inputClass(), 'h-10 !w-20 pr-6 dark:[color-scheme:dark]']" @change="changeSize">
                    <option v-for="size in sizes" :key="size" :value="size">{{ size }}</option>
                </select>
            </label>
            <button type="button" aria-label="Página anterior" title="Página anterior" :disabled="!previousUrl" :class="iconButton" @click="go(previousUrl)">
                <i class="mdi mdi-chevron-left text-xl leading-none" aria-hidden="true" />
            </button>
            <span class="relative inline-flex overflow-hidden tabular-nums" aria-current="page">
                <Transition name="m-tick"><span :key="meta.current_page ?? 1">{{ meta.current_page ?? 1 }}</span></Transition>&nbsp;/ {{ meta.last_page ?? 1 }}
            </span>
            <button type="button" aria-label="Página siguiente" title="Página siguiente" :disabled="!nextUrl" :class="iconButton" @click="go(nextUrl)">
                <i class="mdi mdi-chevron-right text-xl leading-none" aria-hidden="true" />
            </button>
        </div>
    </nav>
</template>
