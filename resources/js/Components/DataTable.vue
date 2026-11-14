<script setup>
// Tabla de listados según la receta: cabecera de la guía, ordenación, fila clicable, los cinco estados
// y, por debajo de `lg`, tarjetas apiladas en lugar de filas.
// Props:
//   columns   [{ key, label, align? ('left' | 'right' | 'center'), sortable?, format? (valor, fila) → texto,
//               width? (clase: 'w-40'), wrap? (deja crecer la celda), primary? (enlace de la fila y título
//               de la tarjeta móvil), hideOnMobile? }]. Cifras y montos: align 'right' (lleva tabular-nums).
//   rows, rowKey ('id' o función), caption (nombre accesible de la tabla)
//   loading (esqueleto si aún no hay filas; con filas, las atenúa), skeletonRows
//   error (texto: pinta el estado de error con «Reintentar»)
//   filtered (hay búsqueda o filtro activo: el vacío pasa a «sin resultados»)
//   emptyTitle, emptyDescription, emptyIcon
//   sort (v-model:sort → { key, direction: 'asc' | 'desc' }), serverSort (no ordena aquí: solo emite)
//   clickable (emite `row-click` con clic, Enter o espacio), selectedKey (fila resaltada)
//   dense (36 px, reportes), stickyHeader (más de 20 filas)
// Slots: cell-<key> ({ row, value, index }), actions ({ row }: menú ⋮ de la fila),
//        card ({ row }: cuerpo propio de la tarjeta móvil), empty-action (botón primario del estado vacío).
// Eventos: row-click (fila), update:sort, retry, clear-filters.
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import CapSkeleton from '../../design/vue/CapSkeleton.vue';
import { EMPTY } from '../lib/format.js';
import EmptyState from './EmptyState.vue';
import ErrorState from './ErrorState.vue';

const props = defineProps({
    columns: { type: Array, required: true },
    rows: { type: Array, default: () => [] },
    rowKey: { type: [String, Function], default: 'id' },
    caption: { type: String, default: '' },
    loading: { type: Boolean, default: false },
    skeletonRows: { type: Number, default: 6 },
    error: { type: String, default: '' },
    filtered: { type: Boolean, default: false },
    emptyTitle: { type: String, default: '' },
    emptyDescription: { type: String, default: '' },
    emptyIcon: { type: String, default: '' },
    sort: { type: Object, default: null },
    serverSort: { type: Boolean, default: false },
    clickable: { type: Boolean, default: false },
    selectedKey: { type: [String, Number], default: null },
    dense: { type: Boolean, default: false },
    stickyHeader: { type: Boolean, default: false },
});

const emit = defineEmits(['row-click', 'update:sort', 'retry', 'clear-filters']);

const SKELETON_DELAY_MS = 120;
const ALIGN = { left: 'text-left', right: 'text-right', center: 'text-center' };

const localSort = ref(props.sort);
const staggered = ref(true);
const showSkeleton = ref(false);
let skeletonTimer = null;

const activeSort = computed(() => props.sort ?? localSort.value);
const keyOf = (row, index) => (typeof props.rowKey === 'function' ? props.rowKey(row) : (row[props.rowKey] ?? index));
const rawValue = (row, column) => column.key.split('.').reduce((value, part) => value?.[part], row);

function display(row, column) {
    const value = rawValue(row, column);
    if (column.format) return column.format(value, row);
    return value === null || value === undefined || value === '' ? EMPTY : value;
}

const collator = new Intl.Collator('es', { numeric: true, sensitivity: 'base' });

const sortedRows = computed(() => {
    const sort = activeSort.value;
    if (props.serverSort || !sort?.key) return props.rows;
    const column = props.columns.find((candidate) => candidate.key === sort.key);
    if (!column) return props.rows;
    const direction = sort.direction === 'desc' ? -1 : 1;
    return [...props.rows].sort((a, b) => {
        const left = rawValue(a, column);
        const right = rawValue(b, column);
        if (left == null || left === '') return 1;
        if (right == null || right === '') return -1;
        return (typeof left === 'number' && typeof right === 'number' ? left - right : collator.compare(String(left), String(right))) * direction;
    });
});

function toggleSort(column) {
    const current = activeSort.value;
    const next = { key: column.key, direction: current?.key === column.key && current.direction === 'asc' ? 'desc' : 'asc' };
    localSort.value = next;
    staggered.value = false;
    emit('update:sort', next);
}

function ariaSort(column) {
    if (!column.sortable) return undefined;
    if (activeSort.value?.key !== column.key) return 'none';
    return activeSort.value.direction === 'desc' ? 'descending' : 'ascending';
}

// Movimiento: las filas entran en cascada al montar y al cambiar de página (ninguna fila en común con
// las anteriores); al ordenar o filtrar se reemplazan al instante. Las filas que aparecen nuevas entre
// las que ya estaban (un registro recién creado) se resaltan un momento.
const staggerKey = ref(0);
const freshKeys = ref(new Set());
let freshTimer = null;
watch(
    () => props.rows,
    (rows, previous) => {
        const before = new Set((previous ?? []).map((row, index) => keyOf(row, index)));
        const now = rows.map((row, index) => keyOf(row, index));
        const added = now.filter((key) => !before.has(key));
        if (now.length && added.length === now.length) {
            staggered.value = true;
            if (before.size) staggerKey.value += 1;
        } else {
            staggered.value = false;
        }
        clearTimeout(freshTimer);
        freshKeys.value = before.size && added.length && added.length <= 3 && added.length < now.length ? new Set(added) : new Set();
        if (freshKeys.value.size) freshTimer = setTimeout(() => (freshKeys.value = new Set()), 1700);
    },
);
onBeforeUnmount(() => clearTimeout(freshTimer));

// El esqueleto espera 120 ms para no parpadear en cargas cortas.
watch(
    () => props.loading,
    (loading) => {
        clearTimeout(skeletonTimer);
        if (!loading) return (showSkeleton.value = false);
        skeletonTimer = setTimeout(() => {
            showSkeleton.value = true;
        }, SKELETON_DELAY_MS);
    },
    { immediate: true },
);
onBeforeUnmount(() => clearTimeout(skeletonTimer));

const primaryColumn = computed(() => props.columns.find((column) => column.primary) ?? props.columns[0]);
const mobileColumns = computed(() => props.columns.filter((column) => column !== primaryColumn.value && !column.hideOnMobile));
const state = computed(() => {
    if (props.error) return 'error';
    if (props.rows.length > 0) return 'rows';
    if (props.loading) return showSkeleton.value ? 'skeleton' : 'waiting';
    return props.filtered ? 'no-results' : 'empty';
});

function onRowKeydown(event, row) {
    if (event.target !== event.currentTarget) return;
    if (event.key !== 'Enter' && event.key !== ' ') return;
    event.preventDefault();
    emit('row-click', row);
}

const headClass = 'bg-cap-table-head font-display text-xs font-semibold text-cap-table-head-ink border-b border-cap-table-head-line whitespace-nowrap';
const rowFocus = 'focus:outline-none focus-visible:bg-cap-card-2';
</script>

<template>
    <div class="min-w-0">
        <ErrorState v-if="state === 'error'" class="rounded-cap-card border border-cap-divider bg-cap-card" :description="error" @retry="emit('retry')" />

        <CapSkeleton v-else-if="state === 'skeleton'" variant="table" :rows="skeletonRows" label="Cargando la lista" />
        <div v-else-if="state === 'waiting'" class="h-64" aria-hidden="true" />

        <EmptyState v-else-if="state === 'no-results'" class="rounded-cap-card border border-cap-divider bg-cap-card" variant="no-results" @clear="emit('clear-filters')" />

        <EmptyState v-else-if="state === 'empty'" :title="emptyTitle" :description="emptyDescription" :icon="emptyIcon">
            <template v-if="$slots['empty-action']" #action><slot name="empty-action" /></template>
        </EmptyState>

        <template v-else>
            <!-- Escritorio: tabla de la receta -->
            <div class="relative isolate hidden rounded-cap-card border border-cap-divider bg-cap-card lg:block" :class="[stickyHeader ? '' : 'overflow-x-auto', loading ? 'opacity-50' : '']" :aria-busy="loading ? 'true' : undefined">
                <table class="w-full border-separate border-spacing-0 text-gray-900 dark:text-gray-100" :class="dense ? 'text-cap-dense' : 'text-sm'">
                    <caption v-if="caption" class="sr-only">{{ caption }}</caption>
                    <thead>
                        <tr>
                            <th
                                v-for="(column, index) in columns"
                                :key="column.key"
                                scope="col"
                                :aria-sort="ariaSort(column)"
                                :class="[headClass, dense ? 'px-2 py-1.5' : 'px-3 py-2.5', ALIGN[column.align] ?? ALIGN.left, column.width, index === 0 ? 'rounded-tl-cap-card' : '', index === columns.length - 1 && !$slots.actions ? 'rounded-tr-cap-card' : '', stickyHeader ? 'lg:sticky lg:top-cap-below-header z-cap-header' : '']"
                            >
                                <button
                                    v-if="column.sortable"
                                    type="button"
                                    class="inline-flex items-center gap-1 rounded-md text-current focus:outline-none focus-visible:ring-2 focus-visible:ring-current"
                                    :class="column.align === 'right' ? 'flex-row-reverse' : ''"
                                    :title="`Ordenar por ${column.label}`"
                                    @click="toggleSort(column)"
                                >
                                    {{ column.label }}
                                    <i
                                        class="m-sort-arrow mdi mdi-arrow-up text-base leading-none text-current"
                                        :class="activeSort?.key === column.key ? '' : 'opacity-0'"
                                        :data-direction="activeSort?.key === column.key ? activeSort.direction : undefined"
                                        aria-hidden="true"
                                    />
                                </button>
                                <template v-else>{{ column.label }}</template>
                            </th>
                            <th v-if="$slots.actions" scope="col" :class="[headClass, 'sticky right-0 w-12 rounded-tr-cap-card px-1', stickyHeader ? 'lg:sticky lg:top-cap-below-header z-cap-header' : '']"><span class="sr-only">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody :key="staggerKey" class="[&>tr:last-child>td:first-child]:rounded-bl-cap-card [&>tr:last-child>td:last-child]:rounded-br-cap-card" :class="staggered ? 'cap-stagger-rows' : ''">
                        <tr
                            v-for="(row, index) in sortedRows"
                            :key="keyOf(row, index)"
                            class="group transition-colors duration-cap-fast ease-cap hover:bg-cap-card-2"
                            :class="[clickable ? `cursor-pointer ${rowFocus}` : '', selectedKey !== null && keyOf(row, index) === selectedKey ? 'bg-cap-card-2' : '', freshKeys.has(keyOf(row, index)) ? 'm-row-new' : '']"
                            :tabindex="clickable ? 0 : undefined"
                            :aria-selected="selectedKey !== null ? (keyOf(row, index) === selectedKey ? 'true' : 'false') : undefined"
                            @click="clickable && emit('row-click', row)"
                            @keydown="clickable && onRowKeydown($event, row)"
                        >
                            <td
                                v-for="(column, columnIndex) in columns"
                                :key="column.key"
                                class="relative border-t border-cap-divider"
                                :class="[dense ? 'h-9 px-2' : 'h-11 px-3', ALIGN[column.align] ?? ALIGN.left, column.align === 'right' ? 'tabular-nums' : '', column.wrap ? 'py-2' : 'max-w-72 truncate whitespace-nowrap']"
                                :title="!column.wrap && typeof display(row, column) === 'string' && display(row, column) !== EMPTY ? display(row, column) : undefined"
                            >
                                <span v-if="columnIndex === 0 && selectedKey !== null && keyOf(row, index) === selectedKey" class="absolute inset-y-0 left-0 w-[3px] bg-cap-brand dark:bg-cap-brand-soft" aria-hidden="true" />
                                <slot :name="`cell-${column.key}`" :row="row" :value="rawValue(row, column)" :index="index">
                                    <span v-if="column.primary" class="font-medium text-cap-brand dark:text-cap-brand-soft">{{ display(row, column) }}</span>
                                    <template v-else>{{ display(row, column) }}</template>
                                </slot>
                            </td>
                            <td v-if="$slots.actions" class="sticky right-0 w-12 border-t border-cap-divider px-1 text-right transition-colors duration-cap-fast ease-cap group-hover:bg-cap-card-2" :class="[dense ? 'h-9' : 'h-11', selectedKey !== null && keyOf(row, index) === selectedKey ? 'bg-cap-card-2' : 'bg-cap-card']" @click.stop>
                                <slot name="actions" :row="row" :index="index" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Móvil: tarjetas apiladas -->
            <ul :key="`cards-${staggerKey}`" class="flex flex-col gap-3 lg:hidden" :class="[loading ? 'opacity-50' : '', staggered ? 'm-stagger-cards' : '']" :aria-label="caption || undefined" :aria-busy="loading ? 'true' : undefined">
                <li
                    v-for="(row, index) in sortedRows"
                    :key="keyOf(row, index)"
                    class="rounded-cap-card border bg-cap-card p-4"
                    :class="[freshKeys.has(keyOf(row, index)) ? 'm-row-new' : '', selectedKey !== null && keyOf(row, index) === selectedKey ? 'border-cap-brand dark:border-cap-brand-soft' : 'border-cap-divider', clickable ? 'cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome focus-visible:ring-offset-2 focus-visible:ring-offset-cap-page dark:focus-visible:ring-gray-100' : '']"
                    :tabindex="clickable ? 0 : undefined"
                    @click="clickable && emit('row-click', row)"
                    @keydown="clickable && onRowKeydown($event, row)"
                >
                    <slot name="card" :row="row" :index="index">
                        <div class="flex items-start justify-between gap-3">
                            <p class="min-w-0 break-words text-sm font-semibold text-gray-900 dark:text-gray-100">
                                <slot :name="`cell-${primaryColumn.key}`" :row="row" :value="rawValue(row, primaryColumn)" :index="index">{{ display(row, primaryColumn) }}</slot>
                            </p>
                            <div v-if="$slots.actions" class="-mr-2 -mt-2 shrink-0" @click.stop><slot name="actions" :row="row" :index="index" /></div>
                        </div>
                        <dl v-if="mobileColumns.length" class="mt-3 grid grid-cols-2 gap-x-4 gap-y-3">
                            <div v-for="column in mobileColumns" :key="column.key" class="min-w-0" :class="column.wrap ? 'col-span-2' : ''">
                                <dt class="text-xs font-medium text-cap-text-soft">{{ column.label }}</dt>
                                <dd class="mt-0.5 break-words text-sm text-gray-900 dark:text-gray-100" :class="column.align === 'right' ? 'tabular-nums' : ''">
                                    <slot :name="`cell-${column.key}`" :row="row" :value="rawValue(row, column)" :index="index">{{ display(row, column) }}</slot>
                                </dd>
                            </div>
                        </dl>
                    </slot>
                </li>
            </ul>
        </template>
    </div>
</template>
