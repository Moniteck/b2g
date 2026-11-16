<script setup>
// Modal «Detalle de producto»: todos los datos, desglose de precio, historial de precio y última sincronización.
// Props: open (v-model:open), item (fila del listado; se usa mientras llega el detalle), quantity (la escrita en la fila).
import { computed, ref, watch } from 'vue';
import CapSkeleton from '@design/vue/CapSkeleton.vue';
import AppButton from '@/Components/AppButton.vue';
import BaseModal from '@/Components/BaseModal.vue';
import DescriptionList from '@/Components/DescriptionList.vue';
import ErrorState from '@/Components/ErrorState.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Timeline from '@/Components/Timeline.vue';
import { dateTime, money, percent, quantity as formatQuantity } from '@/lib/format.js';
import { getJson } from '@/lib/http.js';
import { itemLine } from '@/Modules/Inventory/pricing.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    item: { type: Object, default: null },
    quantity: { type: Number, default: null },
});

const emit = defineEmits(['update:open']);

const detail = ref(null);
const loading = ref(false);
const failed = ref('');

const product = computed(() => detail.value?.item ?? props.item);
const currency = computed(() => product.value?.currency ?? 'HNL');
const shownQuantity = computed(() => (props.quantity && props.quantity > 0 ? props.quantity : 1));
const line = computed(() => (product.value ? itemLine(product.value, shownQuantity.value) : null));

const items = computed(() => {
    const p = product.value;
    if (!p) return [];
    const list = [
        { label: 'SKU', value: p.sku },
        { label: 'Id en Blessing', value: p.blessing_id },
        { key: 'description', label: 'Descripción', value: p.description, wide: true },
        { label: 'Categoría', value: p.category },
        { label: 'Marca', value: p.brand },
        { label: 'Unidad', value: p.unit },
        { key: 'status', label: 'Estado', value: p.is_active ? 'Activo' : 'Inactivo' },
        { label: 'Existencia disponible', value: formatQuantity(p.stock) },
        { label: 'Existencia total', value: p.stock_total !== undefined ? formatQuantity(p.stock_total) : null },
        { label: 'Precio sugerido', value: `${money(p.suggested_price, currency.value)} ${p.tax_included ? '(incluye ISV)' : '(sin ISV)'}` },
        { label: 'Tasa de impuesto', value: percent(p.tax_rate) },
    ];
    if (p.cost !== null && p.cost !== undefined) list.push({ label: 'Costo', value: money(p.cost, currency.value) });
    list.push({ label: 'Sincronizado', value: dateTime(p.synced_at) });
    if (p.deactivated_at) list.push({ label: 'Dado de baja', value: dateTime(p.deactivated_at) });
    return list;
});

const history = computed(() =>
    (detail.value?.history ?? []).map((entry, index) => ({
        id: index,
        title: `${money(entry.suggested_price, currency.value)} ${entry.tax_included ? 'con ISV' : 'sin ISV'} · tasa ${percent(entry.tax_rate)}`,
        meta: dateTime(entry.recorded_at),
        description: entry.cost !== undefined ? `Costo ${money(entry.cost, currency.value)}` : '',
        icon: 'tag-outline',
    })),
);

async function load() {
    if (!props.item) return;
    loading.value = true;
    failed.value = '';
    try {
        detail.value = await getJson(`/inventario/${props.item.id}`);
    } catch (error) {
        failed.value = error.message || 'No se pudo cargar el detalle del producto.';
    } finally {
        loading.value = false;
    }
}

watch(
    () => [props.open, props.item?.id],
    ([open]) => {
        if (open) {
            detail.value = null;
            load();
        }
    },
    { immediate: true },
);
</script>

<template>
    <BaseModal :open="open" size="lg" :title="product ? product.sku : 'Producto'" :description="product?.description" @update:open="emit('update:open', $event)">
        <div v-if="product" class="flex flex-col gap-6">
            <DescriptionList :items="items">
                <template #status="{ item: entry }"><StatusBadge :value="entry.value" /></template>
            </DescriptionList>

            <section v-if="line" class="rounded-cap-control bg-cap-card-2 p-3" aria-labelledby="detalle-precio">
                <h3 id="detalle-precio" class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Precio de {{ formatQuantity(shownQuantity) }} {{ product.unit || 'unidades' }}</h3>
                <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-4">
                    <div>
                        <dt class="text-xs font-medium text-cap-text-soft">Unitario sin ISV</dt>
                        <dd class="tabular-nums text-gray-900 dark:text-gray-100">{{ money(line.unitPrice, currency) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-cap-text-soft">Subtotal</dt>
                        <dd class="tabular-nums text-gray-900 dark:text-gray-100">{{ money(line.subtotal, currency) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-cap-text-soft">ISV {{ percent(line.taxRate) }}</dt>
                        <dd class="tabular-nums text-gray-900 dark:text-gray-100">{{ money(line.tax, currency) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-cap-text-soft">Total</dt>
                        <dd class="font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ money(line.total, currency) }}</dd>
                    </div>
                </dl>
            </section>

            <section aria-labelledby="detalle-historial">
                <h3 id="detalle-historial" class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Historial de precio</h3>
                <div class="mt-3">
                    <CapSkeleton v-if="loading" variant="line" label="Cargando el historial" />
                    <ErrorState v-else-if="failed" compact title="No se pudo cargar el historial" :description="failed" @retry="load" />
                    <Timeline v-else :items="history" empty-text="Sin cambios de precio registrados." />
                </div>
            </section>

            <p v-if="detail?.last_sync" class="text-xs text-cap-text-soft">
                Última sincronización correcta: {{ dateTime(detail.last_sync.finished_at) }} ({{ detail.last_sync.trigger.toLowerCase() }}).
            </p>
        </div>

        <template #footer>
            <AppButton @click="emit('update:open', false)">Cerrar</AppButton>
        </template>
    </BaseModal>
</template>
