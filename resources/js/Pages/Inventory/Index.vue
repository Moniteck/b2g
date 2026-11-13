<script setup>
// Inventario Blessing de la empresa activa: buscador, filtros, precio sugerido y, por cantidad,
// subtotal, ISV y total en vivo; resumen flotante de la selección para llevarla a una cotización.
// Los importes se calculan con `Modules/Inventory/pricing.js`, espejo del calculador del servidor.
import { router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import CapAlert from '../../../design/vue/CapAlert.vue';
import { useToasts } from '../../../design/vue/composables/useToasts.js';
import AppButton from '../../Components/AppButton.vue';
import DataTable from '../../Components/DataTable.vue';
import EmptyState from '../../Components/EmptyState.vue';
import IconButton from '../../Components/IconButton.vue';
import PageHeader from '../../Components/PageHeader.vue';
import Pagination from '../../Components/Pagination.vue';
import SelectInput from '../../Components/SelectInput.vue';
import StatCard from '../../Components/StatCard.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import Toolbar from '../../Components/Toolbar.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { dateTime, money, number, percent, quantity as formatQuantity, relativeTime } from '../../lib/format.js';
import { postJson } from '../../lib/http.js';
import { tableLink } from '../../lib/ui.js';
import { documentTotals, itemLine } from '../../Modules/Inventory/pricing.js';
import ProductDetailModal from '../../Modules/Inventory/ProductDetailModal.vue';
import QuantityInput from '../../Modules/Inventory/QuantityInput.vue';
import { QUOTE_URL, readDraft, saveQuoteSelection, writeDraft } from '../../Modules/Inventory/selection.js';
import SelectionSummary from '../../Modules/Inventory/SelectionSummary.vue';
import SyncHistoryModal from '../../Modules/Inventory/SyncHistoryModal.vue';
import SyncResultModal from '../../Modules/Inventory/SyncResultModal.vue';

const props = defineProps({
    items: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    categories: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
    connection: { type: Object, default: () => ({}) },
    tax: { type: Object, default: () => ({ rate: 15, prices_include_tax: false }) },
    can: { type: Object, default: () => ({}) },
});

const page = usePage();
const toasts = useToasts();
const companyId = computed(() => page.props.activeCompany?.id ?? null);

/* ── Filtros (en la URL, paginación en servidor) ─────────────────────────── */

const DEFAULTS = { q: '', category: '', stock: 'all', per_page: 25 };
const filters = reactive({ ...DEFAULTS, ...props.filters });
const loading = ref(false);
const loadError = ref('');
const filtered = computed(() => Boolean(filters.q || filters.category || filters.stock !== 'all'));

function reload() {
    loadError.value = '';
    const query = Object.fromEntries(Object.entries(filters).filter(([key, value]) => value !== '' && value !== null && value !== DEFAULTS[key]));
    router.get('/inventario', query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['items', 'filters', 'stats'],
        onStart: () => (loading.value = true),
        onFinish: () => (loading.value = false),
        onError: () => (loadError.value = 'No se pudo cargar el inventario.'),
        onException: () => {
            loadError.value = 'No se pudo cargar el inventario. Revisa tu conexión.';
            return false;
        },
    });
}

watch(() => [filters.q, filters.category, filters.stock], reload);

function resetFilters() {
    Object.assign(filters, { q: '', category: '', stock: 'all' });
}

const stockFilters = computed(() => [
    { label: 'Activos', value: 'all' },
    { label: 'Con existencia', value: 'in' },
    { label: 'Sin existencia', value: 'out' },
    { label: 'Inactivos', value: 'inactive' },
]);

const categoryOptions = computed(() => props.categories.map((category) => ({ value: category, label: category })));

/* ── Selección (cantidades por producto) ─────────────────────────────────── */

const selection = reactive({});

onMounted(() => Object.assign(selection, readDraft(companyId.value)));
watch(selection, () => writeDraft(companyId.value, { ...selection }), { deep: true });

function quantityOf(row) {
    return selection[row.id]?.quantity ?? null;
}

function setQuantity(row, value) {
    if (value && value > 0) selection[row.id] = { item: row, quantity: value };
    else delete selection[row.id];
}

function lineOf(row) {
    const qty = quantityOf(row);
    return qty ? itemLine(row, qty) : null;
}

const selected = computed(() => Object.values(selection));
const selectedLines = computed(() => selected.value.map(({ item, quantity }) => ({ item, quantity, line: itemLine(item, quantity) })));
const totals = computed(() => documentTotals(selectedLines.value.map((entry) => entry.line)));
const currencies = computed(() => [...new Set(selected.value.map(({ item }) => item.currency || 'HNL'))]);
const selectionCurrency = computed(() => currencies.value[0] ?? 'HNL');
const blockedReason = computed(() => (currencies.value.length > 1 ? 'La selección mezcla lempiras y dólares: llévalas por separado.' : ''));

function clearSelection() {
    Object.keys(selection).forEach((key) => delete selection[key]);
}

function takeToQuote() {
    const saved = saveQuoteSelection({
        source: 'inventario',
        companyId: companyId.value,
        currency: selectionCurrency.value,
        items: selectedLines.value.map(({ item, quantity }) => ({
            inventory_item_id: item.id,
            sku: item.sku,
            description: item.description,
            unit: item.unit,
            quantity,
            unit_price: item.suggested_price,
            tax_included: item.tax_included,
            tax_rate: item.tax_rate,
            pricing_source: 'blessing',
        })),
        totals: totals.value,
    });

    if (!saved) {
        toasts.show({ status: 'error', title: 'No se pudo guardar la selección', detail: 'El navegador no permite guardar datos de la sesión.' });
        return;
    }

    clearSelection();
    router.visit(QUOTE_URL);
}

/* ── Sincronización ──────────────────────────────────────────────────────── */

const syncing = ref(false);
const resultRun = ref(null);
const resultOpen = ref(false);
const historyOpen = ref(false);

async function syncNow() {
    if (syncing.value) return;
    syncing.value = true;
    try {
        const { run } = await postJson('/inventario/sincronizar');
        showRun(run);
        router.reload({ only: ['items', 'stats', 'connection', 'categories'] });
    } catch (error) {
        toasts.show({ status: error.status === 409 ? 'warning' : 'error', title: 'No se pudo sincronizar', detail: error.message });
    } finally {
        syncing.value = false;
    }
}

function showRun(run) {
    resultRun.value = run;
    resultOpen.value = true;
}

/* ── Estado de la conexión y antigüedad ──────────────────────────────────── */

const lastRun = computed(() => props.connection.last_run);
const neverSynced = computed(() => !props.stats.total);

const freshness = computed(() => {
    if (!props.connection.last_success_at) return 'Sin sincronizar';
    return relativeTime(props.connection.last_success_at);
});

const notice = computed(() => {
    if (!props.connection.configured && !neverSynced.value) {
        return { status: 'warning', title: 'Conexión con Blessing pendiente de configurar', detail: `Se muestra el último inventario guardado (${freshness.value}). No se actualizará hasta configurar la conexión.` };
    }
    if (lastRun.value?.status === 'Fallida' && !neverSynced.value) {
        return { status: 'warning', title: `La última sincronización falló ${relativeTime(lastRun.value.started_at)}`, detail: `${lastRun.value.error ?? ''} Datos de ${freshness.value}.`.trim() };
    }
    if (props.connection.stale) {
        return { status: 'warning', title: `El inventario no se actualiza desde ${freshness.value}`, detail: `La sincronización corre cada hora; revisa el historial si pasan más de ${props.connection.stale_after_hours} horas.` };
    }
    if (props.connection.mode === 'demo') {
        return { status: 'info', title: 'Inventario de demostración', detail: 'Son productos simulados para probar la pantalla mientras se conecta la API real de Blessing.' };
    }
    return null;
});

const priceNote = computed(() =>
    props.tax.prices_include_tax ? 'Los precios sugeridos ya incluyen el ISV.' : `Los precios sugeridos no incluyen ISV; se suma el ${percent(props.tax.rate)}.`,
);

/* ── Tabla ───────────────────────────────────────────────────────────────── */

const columns = [
    { key: 'sku', label: 'SKU', primary: true, width: 'w-36' },
    { key: 'description', label: 'Descripción', wrap: true },
    { key: 'brand', label: 'Marca' },
    { key: 'unit', label: 'Unidad' },
    { key: 'stock', label: 'Existencia', align: 'right', format: (value) => formatQuantity(value) },
    { key: 'suggested_price', label: 'Precio sugerido (L)', align: 'right', format: (value, row) => money(value, row.currency, { symbol: row.currency !== 'HNL' }) },
    { key: 'quantity', label: 'Cantidad', align: 'right' },
    { key: 'subtotal', label: 'Subtotal', align: 'right' },
    { key: 'tax', label: 'ISV', align: 'right' },
    { key: 'total', label: 'Total', align: 'right' },
];

const detailItem = ref(null);
const detailOpen = ref(false);

function openDetail(row) {
    detailItem.value = row;
    detailOpen.value = true;
}

const exceedsStock = (row) => (quantityOf(row) ?? 0) > Number(row.stock ?? 0);
</script>

<template>
    <AppLayout title="Inventario Blessing">
        <div class="flex flex-col gap-6" :class="selected.length ? 'pb-40 lg:pb-20' : ''">
            <PageHeader :description="`${priceNote} Escribe una cantidad para ver el subtotal, el ISV y el total.`">
                <template #actions>
                    <AppButton icon="history" @click="historyOpen = true">Historial</AppButton>
                    <AppButton v-if="can.sync && connection.configured && !neverSynced" variant="primary" icon="sync" :loading="syncing" @click="syncNow">Sincronizar ahora</AppButton>
                </template>
            </PageHeader>

            <EmptyState
                v-if="neverSynced && !connection.configured"
                icon="connection"
                title="Conexión con Blessing pendiente de configurar"
                description="Cuando un administrador registre la URL, el token y la lista de precios de la empresa, el inventario se sincronizará solo cada hora y aparecerá aquí con su precio sugerido."
            />

            <EmptyState
                v-else-if="neverSynced"
                icon="package-variant-closed"
                title="Aún no hay inventario sincronizado"
                :description="can.sync ? 'Trae el catálogo de Blessing ahora; después se actualizará cada hora.' : 'Un administrador debe hacer la primera sincronización.'"
            >
                <template v-if="can.sync" #action>
                    <AppButton variant="primary" icon="sync" :loading="syncing" @click="syncNow">Sincronizar ahora</AppButton>
                </template>
            </EmptyState>

            <template v-else>
                <CapAlert v-if="notice" :status="notice.status" :title="notice.title" :detail="notice.detail" />

                <div class="cap-stagger grid grid-cols-2 gap-4 sm:grid-cols-3">
                    <StatCard variant="featured" label="Productos activos" :count="stats.active" :hint="stats.total > stats.active ? `${number(stats.total - stats.active)} inactivos` : 'Todos activos'" />
                    <StatCard label="Con existencia" :count="stats.in_stock" :hint="`${number(Math.max(stats.active - stats.in_stock, 0))} sin existencia`" />
                    <StatCard label="Última sincronización" :value="freshness" :hint="connection.last_success_at ? dateTime(connection.last_success_at) : 'Aún no hay una sincronización correcta'" :chip="lastRun?.status ?? ''" class="col-span-2 sm:col-span-1" />
                </div>

                <Toolbar
                    v-model:search="filters.q"
                    v-model:filter="filters.stock"
                    search-placeholder="Buscar por SKU, descripción o marca"
                    search-label="Buscar en el inventario"
                    filter-label="Existencia"
                    :filters="stockFilters"
                >
                    <template #filters>
                        <div class="w-full sm:w-56">
                            <SelectInput v-model="filters.category" :options="categoryOptions" placeholder="Todas las categorías" aria-label="Categoría" />
                        </div>
                    </template>
                </Toolbar>

                <div>
                    <DataTable
                        :columns="columns"
                        :rows="items.data"
                        :loading="loading"
                        :error="loadError"
                        :filtered="filtered"
                        caption="Inventario Blessing"
                        empty-icon="package-variant-closed"
                        empty-title="No hay productos en esta vista"
                        @retry="reload"
                        @clear-filters="resetFilters"
                    >
                        <template #cell-sku="{ row }">
                            <button type="button" :class="[tableLink, 'rounded-md text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome dark:focus-visible:ring-gray-100']" :title="`Ver detalle de ${row.sku}`" @click="openDetail(row)">{{ row.sku }}</button>
                        </template>
                        <template #cell-description="{ row }">
                            <span>{{ row.description }}</span>
                            <StatusBadge v-if="!row.is_active" class="ml-2" status="neutral" label="Inactivo" />
                        </template>
                        <template #cell-stock="{ row }">
                            <span :class="Number(row.stock) > 0 ? '' : 'text-cap-text-soft'">{{ formatQuantity(row.stock) }}</span>
                        </template>
                        <template #cell-quantity="{ row }">
                            <div class="flex flex-col items-end">
                                <QuantityInput :model-value="quantityOf(row)" :label="`Cantidad de ${row.sku}`" :invalid="exceedsStock(row)" @update:model-value="setQuantity(row, $event)" />
                                <span v-if="exceedsStock(row)" class="mt-0.5 text-xs text-cap-status-warning-ink">Supera la existencia</span>
                            </div>
                        </template>
                        <template #cell-subtotal="{ row }">{{ lineOf(row) ? money(lineOf(row).subtotal, row.currency, { symbol: false }) : '—' }}</template>
                        <template #cell-tax="{ row }">{{ lineOf(row) ? money(lineOf(row).tax, row.currency, { symbol: false }) : '—' }}</template>
                        <template #cell-total="{ row }">
                            <span :class="lineOf(row) ? 'font-semibold' : 'text-cap-text-soft'">{{ lineOf(row) ? money(lineOf(row).total, row.currency, { symbol: false }) : '—' }}</span>
                        </template>
                        <template #actions="{ row }">
                            <IconButton icon="eye-outline" :label="`Ver detalle de ${row.sku}`" @click="openDetail(row)" />
                        </template>

                        <template #card="{ row }">
                            <div class="flex items-start justify-between gap-3">
                                <button type="button" class="min-w-0 text-left" @click="openDetail(row)">
                                    <span class="block text-sm font-semibold text-cap-brand dark:text-cap-brand-soft">{{ row.sku }}</span>
                                    <span class="block break-words text-sm text-gray-900 dark:text-gray-100">{{ row.description }}</span>
                                    <span class="block text-xs text-cap-text-soft">{{ row.brand || '—' }} · {{ row.unit }} · Existencia {{ formatQuantity(row.stock) }}</span>
                                </button>
                                <span class="shrink-0 text-right">
                                    <span class="block text-sm font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ money(row.suggested_price, row.currency) }}</span>
                                    <span class="block text-xs text-cap-text-soft">{{ row.tax_included ? 'con ISV' : 'sin ISV' }}</span>
                                </span>
                            </div>
                            <div class="mt-3 flex items-end justify-between gap-3">
                                <div class="flex flex-col">
                                    <span class="mb-1 text-xs font-semibold text-gray-700 dark:text-gray-300">Cantidad</span>
                                    <QuantityInput :model-value="quantityOf(row)" :label="`Cantidad de ${row.sku}`" :invalid="exceedsStock(row)" @update:model-value="setQuantity(row, $event)" />
                                </div>
                                <dl v-if="lineOf(row)" class="grid grid-cols-3 gap-3 text-right text-xs">
                                    <div><dt class="text-cap-text-soft">Subtotal</dt><dd class="text-sm tabular-nums text-gray-900 dark:text-gray-100">{{ money(lineOf(row).subtotal, row.currency, { symbol: false }) }}</dd></div>
                                    <div><dt class="text-cap-text-soft">ISV</dt><dd class="text-sm tabular-nums text-gray-900 dark:text-gray-100">{{ money(lineOf(row).tax, row.currency, { symbol: false }) }}</dd></div>
                                    <div><dt class="text-cap-text-soft">Total</dt><dd class="text-sm font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ money(lineOf(row).total, row.currency, { symbol: false }) }}</dd></div>
                                </dl>
                                <p v-else class="text-xs text-cap-text-soft">Escribe una cantidad</p>
                            </div>
                            <p v-if="exceedsStock(row)" class="mt-1 text-xs text-cap-status-warning-ink">Supera la existencia disponible.</p>
                        </template>
                    </DataTable>
                    <Pagination :paginator="items" :only="['items', 'filters']" />
                </div>
            </template>
        </div>

        <SelectionSummary
            :count="selected.length"
            :totals="totals"
            :currency="selectionCurrency"
            :can-quote="Boolean(can.price)"
            :blocked-reason="blockedReason"
            @clear="clearSelection"
            @quote="takeToQuote"
        />

        <ProductDetailModal v-model:open="detailOpen" :item="detailItem" :quantity="detailItem ? quantityOf(detailItem) : null" />
        <SyncResultModal v-model:open="resultOpen" :run="resultRun" />
        <SyncHistoryModal v-model:open="historyOpen" @select="showRun" />
    </AppLayout>
</template>
