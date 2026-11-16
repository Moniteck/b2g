<script setup>
// Calculadora de oferta: de costo y margen sobre la venta a precio, ISV y total, con escenarios de
// margen. Todo el cálculo lo hace el servidor (calculador único de precios); la pantalla solo envía
// las líneas y muestra el resultado.
import { router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import CapAlert from '@design/vue/CapAlert.vue';
import CapSegmented from '@design/vue/CapSegmented.vue';
import { useToasts } from '@design/vue/composables/useToasts.js';
import AppButton from '@/Components/AppButton.vue';
import Checkbox from '@/Components/Checkbox.vue';
import FormField from '@/Components/FormField.vue';
import IconButton from '@/Components/IconButton.vue';
import PageHeader from '@/Components/PageHeader.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { fieldError } from '@/lib/errors.js';
import { money, percent, quantity as formatQuantity } from '@/lib/format.js';
import { postJson } from '@/lib/http.js';
import { QUOTE_URL, saveQuoteSelection } from '@/Pages/Inventory/Partials/selection.js';

const props = defineProps({
    tax: { type: Object, default: () => ({ rate: 15 }) },
    defaultScenarios: { type: Array, default: () => [10, 15, 20, 25, 30] },
    can: { type: Object, default: () => ({}) },
});

const page = usePage();
const toasts = useToasts();

const TAX_OPTIONS = [
    { value: 15, label: 'ISV 15 %' },
    { value: 18, label: 'ISV 18 %' },
    { value: 0, label: 'Exento (0 %)' },
];

let nextKey = 1;
const blankLine = () => ({ key: nextKey++, inventory_item_id: null, item: null, sku: null, description: '', unit: 'Unidad', quantity: 1, cost: null, margin: 20, tax_rate: props.tax.rate ?? 15, exempt: false });

const form = reactive({
    title: '',
    currency: 'HNL',
    scenarios: props.defaultScenarios.join(', '),
    lines: [blankLine()],
});

const result = ref(null);
const errors = ref({});
const calculating = ref(false);
const failure = ref('');
const exporting = ref(false);

const scenarioValues = computed(() =>
    form.scenarios
        .split(/[,;\s]+/)
        .map((value) => Number(value.replace('%', '')))
        .filter((value) => Number.isFinite(value) && value >= 0 && value < 100)
        .slice(0, 6),
);

// Solo se calcula con todas las líneas completas y en rango; el servidor valida igual.
const filled = (value) => value !== null && value !== '' && Number.isFinite(Number(value));
const ready = computed(() =>
    form.lines.every((line) => line.description.trim() && filled(line.cost) && Number(line.cost) >= 0 && Number(line.quantity) > 0 && filled(line.margin) && Number(line.margin) >= 0 && Number(line.margin) < 100),
);

function payload() {
    return {
        title: form.title || null,
        currency: form.currency,
        scenarios: scenarioValues.value,
        lines: form.lines.map((line) => ({
            description: line.description,
            inventory_item_id: line.inventory_item_id,
            sku: line.sku,
            unit: line.unit,
            quantity: line.quantity === '' ? null : line.quantity,
            cost: line.cost === '' ? null : line.cost,
            margin: line.margin === '' ? null : line.margin,
            tax_rate: line.tax_rate,
            exempt: line.exempt,
        })),
    };
}

let timer = null;
let sequence = 0;

async function calculate() {
    if (!ready.value) return;
    const current = ++sequence;
    calculating.value = true;
    failure.value = '';
    try {
        const response = await postJson('/calculadora-oferta/calcular', payload());
        if (current !== sequence) return;
        result.value = response;
        errors.value = {};
    } catch (error) {
        if (current !== sequence) return;
        if (error.status === 422) {
            errors.value = error.errors;
        } else {
            failure.value = error.message || 'No se pudo calcular la oferta.';
        }
    } finally {
        if (current === sequence) calculating.value = false;
    }
}

watch(
    () => JSON.stringify(payload()),
    () => {
        clearTimeout(timer);
        timer = setTimeout(calculate, 350);
    },
);
onBeforeUnmount(() => clearTimeout(timer));

function lineError(index, field) {
    return fieldError(errors.value, `lines.${index}.${field}`);
}

function lineResult(index) {
    return errors.value && Object.keys(errors.value).length ? null : result.value?.lines?.[index] ?? null;
}

function addLine() {
    form.lines.push(blankLine());
}

function removeLine(index) {
    form.lines.splice(index, 1);
    if (!form.lines.length) form.lines.push(blankLine());
}

function pickProduct(line, option) {
    line.item = option;
    if (!option) {
        line.inventory_item_id = null;
        line.sku = null;
        return;
    }
    line.inventory_item_id = option.id;
    line.sku = option.sku;
    line.description = option.description;
    line.unit = option.unit || 'Unidad';
    line.tax_rate = Number(option.tax_rate ?? line.tax_rate);
    if (option.cost !== undefined && option.cost !== null) line.cost = option.cost;
}

const totals = computed(() => result.value?.totals ?? null);

function takeToQuote() {
    if (!result.value) return;
    saveQuoteSelection({
        source: 'calculadora',
        companyId: page.props.activeCompany?.id ?? null,
        currency: form.currency,
        items: result.value.lines.map((line, index) => ({
            inventory_item_id: form.lines[index]?.inventory_item_id ?? null,
            sku: line.sku,
            description: line.description,
            unit: line.unit,
            quantity: line.quantity,
            unit_price: line.unit_price,
            tax_included: false,
            tax_rate: line.exempt ? 0 : line.nominal_rate,
            unit_cost: line.cost,
            margin: line.margin,
            pricing_source: 'calculadora',
        })),
        totals: { subtotal: totals.value.subtotal, tax: totals.value.tax, total: totals.value.total },
    });
    router.visit(QUOTE_URL);
}

function xsrfToken() {
    const cookie = document.cookie.split('; ').find((entry) => entry.startsWith('XSRF-TOKEN='));
    return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
}

async function exportPdf() {
    exporting.value = true;
    try {
        const response = await fetch('/calculadora-oferta/pdf', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/pdf, application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrfToken() },
            body: JSON.stringify(payload()),
        });
        if (!response.ok) throw new Error((await response.json().catch(() => null))?.message || 'No se pudo generar el PDF.');
        const url = URL.createObjectURL(await response.blob());
        const link = document.createElement('a');
        link.href = url;
        link.download = 'calculo-de-oferta.pdf';
        link.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (error) {
        toasts.show({ status: 'error', title: 'No se pudo exportar el PDF', detail: error.message });
    } finally {
        exporting.value = false;
    }
}

const hasResult = computed(() => Boolean(ready.value && result.value && !Object.keys(errors.value).length));
const bestScenario = computed(() => (totals.value ? totals.value.margin : null));
</script>

<template>
    <AppLayout title="Calculadora de oferta">
        <PageHeader description="Precio = costo ÷ (1 − margen): el margen es sobre la venta. Los precios calculados no incluyen ISV; el impuesto se suma por línea.">
            <template #actions>
                <AppButton icon="file-pdf-box" :loading="exporting" :disabled="!hasResult" @click="exportPdf">Exportar PDF</AppButton>
                <AppButton variant="primary" icon="file-document-arrow-right-outline" :disabled="!hasResult" @click="takeToQuote">Llevar a una cotización</AppButton>
            </template>
        </PageHeader>

        <section class="rounded-cap-card border border-cap-divider bg-cap-card p-5" aria-labelledby="calc-datos">
            <h2 id="calc-datos" class="sr-only">Datos del cálculo</h2>
            <div class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-3">
                <FormField label="Nombre del cálculo" optional class="md:col-span-1">
                    <TextInput v-model="form.title" maxlength="200" placeholder="LPN-007 lubricantes, lote 1" />
                </FormField>
                <FormField label="Moneda">
                    <CapSegmented v-model="form.currency" label="Moneda" :items="[{ label: 'Lempiras', value: 'HNL' }, { label: 'Dólares', value: 'USD' }]" />
                </FormField>
                <FormField label="Márgenes a comparar (%)" help="Hasta seis, separados por coma." :error="fieldError(errors, 'scenarios') || fieldError(errors, 'scenarios.0')">
                    <TextInput v-model="form.scenarios" inputmode="decimal" placeholder="10, 15, 20, 25, 30" />
                </FormField>
            </div>
        </section>

        <section class="rounded-cap-card border border-cap-divider bg-cap-card p-5" aria-labelledby="calc-lineas">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="calc-lineas" class="font-display text-lg font-semibold text-gray-900 dark:text-gray-100">Líneas</h2>
                <AppButton icon="plus" @click="addLine">Agregar línea</AppButton>
            </div>

            <ol v-stagger class="mt-4 flex flex-col gap-4">
                <li v-for="(line, index) in form.lines" :key="line.key" class="rounded-cap-control bg-cap-card-2 p-3" :data-testid="`linea-${index}`">
                    <div class="grid grid-cols-2 gap-x-4 gap-y-3 lg:grid-cols-12">
                        <FormField label="Producto del inventario" optional class="col-span-2 lg:col-span-4">
                            <SearchSelect
                                :model-value="line.inventory_item_id"
                                url="/inventario/buscar"
                                option-value="id"
                                option-label="label"
                                option-description="brand"
                                :selected="line.item"
                                placeholder="Buscar por SKU o descripción"
                                @select="pickProduct(line, $event)"
                            />
                        </FormField>
                        <FormField label="Descripción" required :error="lineError(index, 'description')" class="col-span-2 lg:col-span-8">
                            <TextInput v-model="line.description" maxlength="500" />
                        </FormField>
                        <FormField label="Cantidad" required :error="lineError(index, 'quantity')" class="lg:col-span-2">
                            <TextInput v-model="line.quantity" type="number" min="0" step="any" class="text-right" />
                        </FormField>
                        <FormField :label="`Costo unitario (${form.currency === 'USD' ? 'US$' : 'L'})`" required :error="lineError(index, 'cost')" class="lg:col-span-2">
                            <TextInput v-model="line.cost" type="number" min="0" step="any" class="text-right" />
                        </FormField>
                        <FormField label="Margen (%)" required :error="lineError(index, 'margin')" class="lg:col-span-2">
                            <TextInput v-model="line.margin" type="number" min="0" max="99.99" step="any" class="text-right" />
                        </FormField>
                        <FormField label="Impuesto" :error="lineError(index, 'tax_rate')" class="lg:col-span-2">
                            <SelectInput v-model="line.tax_rate" :options="TAX_OPTIONS" placeholder="" />
                        </FormField>
                        <div class="col-span-2 flex items-end justify-between gap-3 lg:col-span-4">
                            <Checkbox v-model="line.exempt" label="Exonerado" />
                            <div class="text-right">
                                <p class="whitespace-nowrap text-xs text-cap-text-soft">Precio sin ISV · Total</p>
                                <p class="whitespace-nowrap text-sm font-semibold tabular-nums text-gray-900 dark:text-gray-100" :data-testid="`linea-${index}-total`">
                                    <template v-if="lineResult(index)">{{ money(lineResult(index).unit_price, form.currency) }} · {{ money(lineResult(index).total, form.currency) }}</template>
                                    <template v-else>—</template>
                                </p>
                            </div>
                            <IconButton icon="trash-can-outline" :label="`Quitar la línea ${index + 1}`" @click="removeLine(index)" />
                        </div>
                    </div>
                </li>
            </ol>
        </section>

        <CapAlert v-if="failure" status="error" title="No se pudo calcular la oferta" :detail="failure" />

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-2" :class="calculating ? 'opacity-50 transition-opacity duration-cap-fast ease-cap' : ''" :aria-busy="calculating ? 'true' : undefined">
            <section class="rounded-cap-card border border-cap-divider bg-cap-card p-5" aria-labelledby="calc-totales">
                <h2 id="calc-totales" class="font-display text-lg font-semibold text-gray-900 dark:text-gray-100">Totales</h2>
                <p v-if="!hasResult" class="mt-3 text-sm text-cap-text-soft">Completa la descripción, la cantidad, el costo y el margen (menor que 100 %) de cada línea para ver los totales.</p>
                <dl v-else class="mt-4 flex flex-col gap-2 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-cap-text-soft">Costo total</dt><dd class="tabular-nums text-gray-900 dark:text-gray-100">{{ money(totals.cost_total, form.currency) }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-cap-text-soft">Subtotal sin ISV</dt><dd class="tabular-nums text-gray-900 dark:text-gray-100" data-testid="calc-subtotal">{{ money(totals.subtotal, form.currency) }}</dd></div>
                    <div v-for="entry in totals.taxes" :key="entry.rate" class="flex justify-between gap-3">
                        <dt class="text-cap-text-soft">{{ entry.rate > 0 ? `ISV ${percent(entry.rate)} sobre ${money(entry.base, form.currency)}` : `Exento o exonerado: ${money(entry.base, form.currency)}` }}</dt>
                        <dd class="tabular-nums text-gray-900 dark:text-gray-100">{{ money(entry.tax, form.currency) }}</dd>
                    </div>
                    <div class="flex justify-between gap-3"><dt class="text-cap-text-soft">Ganancia ({{ percent(totals.margin) }} sobre la venta)</dt><dd class="tabular-nums text-gray-900 dark:text-gray-100">{{ money(totals.profit, form.currency) }}</dd></div>
                    <div class="mt-2 flex justify-between gap-3 border-t border-cap-divider pt-3">
                        <dt class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Total con ISV</dt>
                        <dd class="font-display text-2xl font-semibold tabular-nums text-gray-900 dark:text-gray-100" data-testid="calc-total">{{ money(totals.total, form.currency) }}</dd>
                    </div>
                </dl>
            </section>

            <section class="rounded-cap-card border border-cap-divider bg-cap-card p-5" aria-labelledby="calc-escenarios">
                <h2 id="calc-escenarios" class="font-display text-lg font-semibold text-gray-900 dark:text-gray-100">Escenarios de margen</h2>
                <p class="mt-1 text-sm text-cap-text-soft">Las mismas líneas con un margen uniforme.</p>
                <p v-if="!hasResult || !result.scenarios.length" class="mt-3 text-sm text-cap-text-soft">Sin escenarios que comparar todavía.</p>
                <div v-else class="mt-4 overflow-x-auto rounded-cap-control border border-cap-divider">
                    <table class="w-full border-separate border-spacing-0 text-sm text-gray-900 dark:text-gray-100">
                        <thead>
                            <tr>
                                <th class="bg-cap-table-head px-3 py-2.5 text-right font-display text-xs font-semibold text-cap-table-head-ink border-b border-cap-table-head-line">Margen</th>
                                <th class="bg-cap-table-head px-3 py-2.5 text-right font-display text-xs font-semibold text-cap-table-head-ink border-b border-cap-table-head-line">Subtotal</th>
                                <th class="bg-cap-table-head px-3 py-2.5 text-right font-display text-xs font-semibold text-cap-table-head-ink border-b border-cap-table-head-line">Total con ISV</th>
                                <th class="bg-cap-table-head px-3 py-2.5 text-right font-display text-xs font-semibold text-cap-table-head-ink border-b border-cap-table-head-line">Ganancia</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="scenario in result.scenarios" :key="scenario.margin" class="transition-colors duration-cap-fast ease-cap hover:bg-cap-card-2" :class="Math.abs(scenario.margin - bestScenario) < 0.05 ? 'bg-cap-card-2' : ''">
                                <td class="h-11 whitespace-nowrap border-t border-cap-divider px-3 text-right tabular-nums">{{ percent(scenario.margin) }}</td>
                                <td class="h-11 whitespace-nowrap border-t border-cap-divider px-3 text-right tabular-nums">{{ money(scenario.subtotal, form.currency, { symbol: false }) }}</td>
                                <td class="h-11 whitespace-nowrap border-t border-cap-divider px-3 text-right tabular-nums">{{ money(scenario.total, form.currency, { symbol: false }) }}</td>
                                <td class="h-11 whitespace-nowrap border-t border-cap-divider px-3 text-right tabular-nums">{{ money(scenario.profit, form.currency, { symbol: false }) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="hasResult" class="mt-3 text-xs text-cap-text-soft">{{ formatQuantity(result.lines.length) }} {{ result.lines.length === 1 ? 'línea' : 'líneas' }} · el margen actual de la oferta es {{ percent(totals.margin) }}.</p>
            </section>
        </div>
    </AppLayout>
</template>
