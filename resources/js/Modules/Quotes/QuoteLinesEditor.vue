<script setup>
// Líneas de la cotización: buscador de inventario o texto libre, con subtotal, ISV y total en vivo
// (mismo cálculo que el servidor, `Modules/Inventory/pricing.js`).
// Props: modelValue (v-model: líneas de `quotes.js › emptyLine`), taxIncluded (base de las líneas
//        escritas a mano), defaultRate, errors (errores del formulario: `items.0.quantity`…), disabled,
//        showCost (muestra costo unitario y margen por línea; solo operador y administrador).
import { computed, ref } from 'vue';
import AppButton from '@/Components/AppButton.vue';
import EmptyState from '@/Components/EmptyState.vue';
import IconButton from '@/Components/IconButton.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import TextInput from '@/Components/TextInput.vue';
import { money } from '@/lib/format.js';
import { emptyLine, lineFromProduct, priced } from '@/Modules/Quotes/quotes.js';

const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    taxIncluded: { type: Boolean, default: false },
    defaultRate: { type: Number, default: 15 },
    errors: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
    showCost: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const picker = ref(null);
const pickerKey = ref(0);

const rows = computed(() => props.modelValue.map((line) => ({ line, calc: priced(line, props.taxIncluded) })));

function update(index, patch) {
    const next = [...props.modelValue];
    next[index] = { ...next[index], ...patch };
    emit('update:modelValue', next);
}

function addFree() {
    emit('update:modelValue', [...props.modelValue, emptyLine({ tax_rate: props.defaultRate })]);
}

function addProduct(product) {
    if (!product) return;
    const existing = props.modelValue.findIndex((line) => line.inventory_item_id && Number(line.inventory_item_id) === Number(product.id));
    if (existing >= 0) {
        update(existing, { quantity: Number(props.modelValue[existing].quantity || 0) + 1 });
    } else {
        emit('update:modelValue', [...props.modelValue, lineFromProduct(product)]);
    }
    picker.value = null;
    pickerKey.value += 1;
}

function remove(index) {
    emit('update:modelValue', props.modelValue.filter((_, position) => position !== index));
}

const error = (index, field) => {
    const value = props.errors[`items.${index}.${field}`];
    return Array.isArray(value) ? value[0] : value;
};

const baseLabel = (line) => ((line.tax_included ?? props.taxIncluded) ? 'con ISV' : 'sin ISV');
const numberClass = 'text-right tabular-nums';
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-start">
            <div class="min-w-0 flex-1">
                <label class="mb-1 block text-xs font-semibold text-gray-900 dark:text-gray-100" for="quote-product-picker">Agregar producto del inventario</label>
                <SearchSelect
                    id="quote-product-picker"
                    :key="pickerKey"
                    v-model="picker"
                    url="/inventario/buscar"
                    option-value="id"
                    option-label="label"
                    option-description="unit"
                    placeholder="Buscar por SKU o descripción…"
                    :disabled="disabled"
                    @select="addProduct"
                />
            </div>
            <div class="sm:pt-5">
                <AppButton icon="plus" :disabled="disabled" class="w-full sm:w-auto" @click="addFree">Línea libre</AppButton>
            </div>
        </div>

        <p v-if="errors.items" class="text-xs text-cap-status-error dark:text-cap-status-dark-error" role="alert">{{ Array.isArray(errors.items) ? errors.items[0] : errors.items }}</p>

        <EmptyState v-if="!modelValue.length" compact icon="format-list-bulleted" title="Sin líneas" description="Busca un producto del inventario o agrega una línea libre." />

        <div v-else class="flex flex-col gap-3">
            <!-- Cabecera de columnas (solo escritorio) -->
            <div class="hidden grid-cols-[minmax(0,1fr)_5.5rem_6.5rem_8.5rem_4.5rem_4.5rem_8rem_2.5rem] gap-2 rounded-cap-control bg-cap-table-head px-2 py-2 font-display text-xs font-semibold text-cap-table-head-ink lg:grid" aria-hidden="true">
                <span>Descripción</span><span class="text-right">Cantidad</span><span>Unidad</span><span class="text-right">P. unitario</span><span class="text-right">ISV %</span><span class="text-right">Desc. %</span><span class="text-right">Total</span><span />
            </div>

            <div
                v-for="({ line, calc }, index) in rows"
                :key="line.key"
                class="grid grid-cols-2 gap-x-3 gap-y-2 rounded-cap-control border border-cap-divider p-3 lg:grid-cols-[minmax(0,1fr)_5.5rem_6.5rem_8.5rem_4.5rem_4.5rem_8rem_2.5rem] lg:items-start lg:gap-2 lg:border-0 lg:border-b lg:rounded-none lg:p-2"
                :data-line="index"
            >
                <div class="col-span-2 min-w-0 lg:col-span-1">
                    <label :for="`line-${line.key}-description`" class="mb-1 block text-xs font-semibold text-gray-900 lg:sr-only dark:text-gray-100">Descripción de la línea {{ index + 1 }}</label>
                    <TextInput :id="`line-${line.key}-description`" :model-value="line.description" maxlength="255" :invalid="Boolean(error(index, 'description'))" :disabled="disabled" @update:model-value="update(index, { description: $event })" />
                    <p v-if="line.sku" class="mt-1 truncate text-xs text-cap-text-soft" :title="line.sku">
                        <i class="mdi mdi-package-variant-closed" aria-hidden="true" /> {{ line.sku }} · inventario
                    </p>
                    <p v-if="error(index, 'description')" class="mt-1 text-xs text-cap-status-error dark:text-cap-status-dark-error">{{ error(index, 'description') }}</p>
                </div>
                <div>
                    <label :for="`line-${line.key}-quantity`" class="mb-1 block text-xs font-semibold text-gray-900 lg:sr-only dark:text-gray-100">Cantidad</label>
                    <TextInput :id="`line-${line.key}-quantity`" :model-value="line.quantity" type="number" min="0.01" step="0.01" inputmode="decimal" :class="numberClass" :invalid="Boolean(error(index, 'quantity'))" :disabled="disabled" @update:model-value="update(index, { quantity: $event })" />
                    <p v-if="error(index, 'quantity')" class="mt-1 text-xs text-cap-status-error dark:text-cap-status-dark-error">{{ error(index, 'quantity') }}</p>
                </div>
                <div>
                    <label :for="`line-${line.key}-unit`" class="mb-1 block text-xs font-semibold text-gray-900 lg:sr-only dark:text-gray-100">Unidad</label>
                    <TextInput :id="`line-${line.key}-unit`" :model-value="line.unit" maxlength="50" :invalid="Boolean(error(index, 'unit'))" :disabled="disabled" @update:model-value="update(index, { unit: $event })" />
                </div>
                <div>
                    <label :for="`line-${line.key}-price`" class="mb-1 block text-xs font-semibold text-gray-900 lg:sr-only dark:text-gray-100">Precio unitario ({{ baseLabel(line) }})</label>
                    <TextInput :id="`line-${line.key}-price`" :model-value="line.unit_price" type="number" min="0" step="0.01" inputmode="decimal" :class="numberClass" :invalid="Boolean(error(index, 'unit_price'))" :disabled="disabled" @update:model-value="update(index, { unit_price: $event })" />
                    <p class="mt-1 text-xs text-cap-text-soft lg:text-right">{{ baseLabel(line) }}</p>
                    <p v-if="error(index, 'unit_price')" class="mt-1 text-xs text-cap-status-error dark:text-cap-status-dark-error">{{ error(index, 'unit_price') }}</p>
                </div>
                <div>
                    <label :for="`line-${line.key}-rate`" class="mb-1 block text-xs font-semibold text-gray-900 lg:sr-only dark:text-gray-100">ISV %</label>
                    <TextInput :id="`line-${line.key}-rate`" :model-value="line.tax_rate" type="number" min="0" max="100" step="0.01" inputmode="decimal" :class="numberClass" :invalid="Boolean(error(index, 'tax_rate'))" :disabled="disabled" @update:model-value="update(index, { tax_rate: $event })" />
                </div>
                <div>
                    <label :for="`line-${line.key}-discount`" class="mb-1 block text-xs font-semibold text-gray-900 lg:sr-only dark:text-gray-100">Descuento %</label>
                    <TextInput :id="`line-${line.key}-discount`" :model-value="line.discount_percent" type="number" min="0" max="100" step="0.01" inputmode="decimal" :class="numberClass" :invalid="Boolean(error(index, 'discount_percent'))" :disabled="disabled" @update:model-value="update(index, { discount_percent: $event })" />
                </div>
                <div class="flex flex-col justify-center lg:h-10 lg:items-end">
                    <span class="text-xs font-semibold text-cap-text-soft lg:sr-only">Total</span>
                    <span class="text-sm font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ money(calc.total) }}</span>
                    <span class="text-xs tabular-nums text-cap-text-soft">ISV {{ money(calc.tax) }}</span>
                </div>
                <div v-if="showCost" class="order-last col-span-2 flex flex-wrap items-start gap-3 lg:col-span-8" :data-line-cost="index">
                    <div class="w-full sm:w-40">
                        <label :for="`line-${line.key}-cost`" class="mb-1 block text-xs font-semibold text-gray-900 dark:text-gray-100">Costo unitario (interno)</label>
                        <TextInput :id="`line-${line.key}-cost`" :model-value="line.unit_cost" type="number" min="0" step="0.01" inputmode="decimal" :class="numberClass" :invalid="Boolean(error(index, 'unit_cost'))" :disabled="disabled" @update:model-value="update(index, { unit_cost: $event })" />
                        <p v-if="error(index, 'unit_cost')" class="mt-1 text-xs text-cap-status-error dark:text-cap-status-dark-error">{{ error(index, 'unit_cost') }}</p>
                    </div>
                    <div class="w-full sm:w-32">
                        <label :for="`line-${line.key}-margin`" class="mb-1 block text-xs font-semibold text-gray-900 dark:text-gray-100">Margen %</label>
                        <TextInput :id="`line-${line.key}-margin`" :model-value="line.margin" type="number" min="0" max="95" step="0.01" inputmode="decimal" :class="numberClass" :invalid="Boolean(error(index, 'margin'))" :disabled="disabled" @update:model-value="update(index, { margin: $event })" />
                        <p v-if="error(index, 'margin')" class="mt-1 text-xs text-cap-status-error dark:text-cap-status-dark-error">{{ error(index, 'margin') }}</p>
                    </div>
                    <p class="text-xs text-cap-text-soft sm:pt-7">No aparecen en el PDF ni los ve el rol de solo lectura.</p>
                </div>
                <div class="col-span-2 flex justify-end lg:col-span-1">
                    <IconButton icon="trash-can-outline" :label="`Quitar la línea ${index + 1}`" :disabled="disabled" @click="remove(index)" />
                </div>
            </div>
        </div>
    </div>
</template>
