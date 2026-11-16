<script setup>
// Crear o editar una cotización (modal xl). Editable solo en Borrador o Emitida (lo decide el servidor).
// Props: open (v-model:open), quoteId (null = nueva), prefill (líneas iniciales, p. ej. desde el
//        inventario), issuerCompanies ([{ id, name }]), activeCompanyId, taxRules ({ rate, prices_include_tax }),
//        tenders / opportunities (opciones locales [{ id, name, description? }]).
// Eventos: update:open, saved.
import { useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import CapAlert from '../../../design/vue/CapAlert.vue';
import CapSkeleton from '../../../design/vue/CapSkeleton.vue';
import AppButton from '../../Components/AppButton.vue';
import BaseModal from '../../Components/BaseModal.vue';
import DateInput from '../../Components/DateInput.vue';
import ErrorState from '../../Components/ErrorState.vue';
import FormField from '../../Components/FormField.vue';
import SearchSelect from '../../Components/SearchSelect.vue';
import SelectInput from '../../Components/SelectInput.vue';
import TextArea from '../../Components/TextArea.vue';
import TextInput from '../../Components/TextInput.vue';
import Toggle from '../../Components/Toggle.vue';
import { focusFirstError } from '../../lib/errors.js';
import { date, money, percent } from '../../lib/format.js';
import { fetchQuote } from './api.js';
import QuoteLinesEditor from './QuoteLinesEditor.vue';
import { addDays, linePayload, lineFromServer, quoteTotals, today } from './quotes.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    quoteId: { type: [Number, String], default: null },
    prefill: { type: Array, default: () => [] },
    issuerCompanies: { type: Array, default: () => [] },
    activeCompanyId: { type: [Number, String], default: null },
    taxRules: { type: Object, default: () => ({ rate: 15, prices_include_tax: false }) },
    tenders: { type: Array, default: () => [] },
    opportunities: { type: Array, default: () => [] },
    canSeeCost: { type: Boolean, default: false },
});

const emit = defineEmits(['update:open', 'saved']);

const blank = () => ({
    company_id: props.activeCompanyId ?? props.issuerCompanies[0]?.id ?? null,
    institution_id: null,
    contact_id: null,
    tender_id: null,
    opportunity_id: null,
    quoted_at: today(),
    validity_days: 30,
    purchase_type: 'Cotización',
    tax_included: Boolean(props.taxRules?.prices_include_tax),
    conditions: '',
    notes: '',
    items: [],
});

const form = useForm(blank());
const detail = ref(null);
const loading = ref(false);
const loadError = ref('');
const contactKey = ref(0);

const editing = computed(() => Boolean(props.quoteId));
const title = computed(() => (editing.value ? `Editar ${detail.value?.number ?? 'cotización'}` : 'Nueva cotización'));
const companyOptions = computed(() => props.issuerCompanies.map((company) => ({ value: company.id, label: company.name })));
const validUntil = computed(() => addDays(form.quoted_at, form.validity_days));
const totals = computed(() => quoteTotals(form.items, form.tax_included));
const opportunityOptions = computed(() => props.opportunities.filter((option) => !form.institution_id || !option.institution_id || option.institution_id === form.institution_id));
// Costo y margen por línea: solo operador y administrador (el detalle los trae si se pueden ver).
const showCost = computed(() => (editing.value ? Boolean(detail.value?.can_see_cost) : props.canSeeCost));
const readonly = computed(() => editing.value && detail.value && !detail.value.can?.update);

async function load() {
    loadError.value = '';
    form.clearErrors();
    if (!editing.value) {
        detail.value = null;
        const values = { ...blank(), items: props.prefill.map((line) => ({ ...line })) };
        form.defaults(values);
        form.reset();
        return;
    }
    loading.value = true;
    try {
        const quote = await fetchQuote(props.quoteId);
        detail.value = quote;
        form.defaults({
            company_id: quote.company_id,
            institution_id: quote.institution_id,
            contact_id: quote.contact_id,
            tender_id: quote.tender_id,
            opportunity_id: quote.opportunity_id,
            quoted_at: quote.quoted_at,
            validity_days: quote.validity_days,
            purchase_type: quote.purchase_type,
            tax_included: quote.tax_included,
            conditions: quote.conditions ?? '',
            notes: quote.notes ?? '',
            items: quote.items.map(lineFromServer),
        });
        form.reset();
    } catch (error) {
        loadError.value = error.message || 'No se pudo cargar la cotización.';
    } finally {
        loading.value = false;
    }
}

watch(() => props.open, (open) => open && load());

function onInstitution(option) {
    if (!option || option.id !== form.institution_id) {
        form.contact_id = null;
        contactKey.value += 1;
    }
}

const tenderOptions = computed(() => {
    const extra = detail.value?.tender_option && !props.tenders.some((t) => t.id === detail.value.tender_option.id) ? [detail.value.tender_option] : [];
    return [...extra, ...props.tenders];
});
const opportunityChoices = computed(() => {
    const extra = detail.value?.opportunity_option && !opportunityOptions.value.some((o) => o.id === detail.value.opportunity_option.id) ? [detail.value.opportunity_option] : [];
    return [...extra, ...opportunityOptions.value];
});

function onTender(option) {
    if (!option) return;
    const linked = props.opportunities.find((opportunity) => opportunity.tender_id === option.id);
    if (linked && !form.opportunity_id) form.opportunity_id = linked.id;
}

function close() {
    emit('update:open', false);
}

function submit() {
    if (readonly.value) return;
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            emit('saved');
            close();
        },
        onError: () => nextTick(() => focusFirstError(document.querySelector('[data-quote-form]'))),
    };
    const payload = form.transform((data) => ({ ...data, items: data.items.map((line) => linePayload(line, showCost.value)) }));
    if (editing.value) payload.put(`/cotizaciones/${props.quoteId}`, options);
    else payload.post('/cotizaciones', options);
}
</script>

<template>
    <BaseModal :open="open" as="form" size="xl" :title="title" :description="editing && detail ? `${detail.status} · ${detail.institution?.name ?? ''}` : 'Se crea en Borrador; podrás emitirla y presentarla después.'" :persistent="form.processing || form.isDirty" @update:open="emit('update:open', $event)" @submit="submit">
        <div v-if="loading" class="flex flex-col gap-4" aria-label="Cargando cotización">
            <CapSkeleton variant="line" />
            <CapSkeleton variant="line" />
            <CapSkeleton variant="table" />
        </div>
        <ErrorState v-else-if="loadError" compact title="No se pudo abrir la cotización" :description="loadError" @retry="load" />

        <div v-else class="flex flex-col gap-8" data-quote-form>
            <CapAlert v-if="readonly" status="warning" title="Esta cotización ya no se edita" :detail="`Está ${detail.status}${detail.outcome !== 'Pendiente' ? ' y ' + detail.outcome : ''}. Para cambiarla, crea una revisión nueva.`" />

            <section class="flex flex-col gap-4">
                <h3 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Datos de la oferta</h3>
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
                    <FormField label="Empresa emisora" required :error="form.errors.company_id" :help="editing ? 'La empresa emisora no cambia al editar.' : ''">
                        <SelectInput v-if="!editing && companyOptions.length > 1" v-model="form.company_id" :options="companyOptions" placeholder="" />
                        <TextInput v-else :model-value="editing ? detail?.company?.name : companyOptions[0]?.label" readonly />
                    </FormField>
                    <FormField label="Institución" required :error="form.errors.institution_id">
                        <SearchSelect v-model="form.institution_id" url="/instituciones/buscar" option-value="id" option-label="name" :selected="detail?.institution ?? null" placeholder="Buscar institución…" :disabled="readonly" @select="onInstitution" />
                    </FormField>
                    <FormField label="Contacto" optional :error="form.errors.contact_id" :help="form.institution_id ? '' : 'Elige primero la institución.'">
                        <SearchSelect :key="contactKey" v-model="form.contact_id" url="/contactos/buscar" :params="{ institution_id: form.institution_id }" :min-chars="0" option-value="id" option-label="name" option-description="position" :selected="detail?.contact_option ?? null" placeholder="Buscar contacto…" :disabled="readonly || !form.institution_id" />
                    </FormField>
                    <FormField label="Licitación" optional :error="form.errors.tender_id">
                        <SearchSelect v-model="form.tender_id" :options="tenderOptions" option-value="id" option-label="name" option-description="description" placeholder="Expediente…" :disabled="readonly" @select="onTender" />
                    </FormField>
                    <FormField label="Oportunidad" optional :error="form.errors.opportunity_id" help="El resultado (Ganada o Perdida) se registra por oportunidad.">
                        <SearchSelect v-model="form.opportunity_id" :options="opportunityChoices" option-value="id" option-label="name" placeholder="Oportunidad del kanban…" :disabled="readonly" />
                    </FormField>
                    <FormField label="Tipo de compra" optional :error="form.errors.purchase_type">
                        <TextInput v-model="form.purchase_type" maxlength="100" placeholder="Cotización, Licitación Pública…" :readonly="readonly" />
                    </FormField>
                    <FormField label="Fecha" required :error="form.errors.quoted_at">
                        <DateInput v-model="form.quoted_at" class="md:max-w-40" :readonly="readonly" />
                    </FormField>
                    <FormField label="Vigencia (días)" required :error="form.errors.validity_days" :help="validUntil ? `Válida hasta el ${date(validUntil, 'medium')}` : ''">
                        <TextInput v-model="form.validity_days" type="number" min="1" max="365" inputmode="numeric" class="md:max-w-28" :readonly="readonly" />
                    </FormField>
                </div>
            </section>

            <section class="flex flex-col gap-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h3 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Líneas</h3>
                    <Toggle v-model="form.tax_included" label="Los precios escritos a mano incluyen ISV" :disabled="readonly" />
                </div>
                <QuoteLinesEditor v-model="form.items" :tax-included="form.tax_included" :default-rate="Number(taxRules?.rate ?? 15)" :errors="form.errors" :disabled="readonly" :show-cost="showCost" />

                <dl class="ml-auto grid w-full max-w-sm grid-cols-[1fr_auto] gap-x-6 gap-y-1.5 rounded-cap-control bg-cap-card-2 p-4 text-sm" aria-live="polite">
                    <template v-if="totals.discount > 0">
                        <dt class="text-cap-text-soft">Descuentos</dt><dd class="text-right tabular-nums">− {{ money(totals.discount) }}</dd>
                    </template>
                    <dt class="text-cap-text-soft">Subtotal sin ISV</dt><dd class="text-right tabular-nums">{{ money(totals.subtotal) }}</dd>
                    <template v-for="tax in totals.taxes" :key="tax.rate">
                        <dt class="text-cap-text-soft">{{ tax.rate > 0 ? `ISV ${percent(tax.rate, 0)}` : 'Exento' }}</dt><dd class="text-right tabular-nums">{{ money(tax.tax) }}</dd>
                    </template>
                    <dt class="border-t border-cap-divider pt-2 font-display font-semibold text-gray-900 dark:text-gray-100">Total</dt>
                    <dd class="border-t border-cap-divider pt-2 text-right font-display text-base font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ money(totals.total) }}</dd>
                </dl>
            </section>

            <section class="flex flex-col gap-4">
                <h3 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Condiciones y notas</h3>
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
                    <FormField label="Condiciones" optional :error="form.errors.conditions" help="Entrega, forma de pago, garantía. Salen en el PDF.">
                        <TextArea v-model="form.conditions" :rows="4" maxlength="5000" :readonly="readonly" />
                    </FormField>
                    <FormField label="Notas" optional :error="form.errors.notes" help="También salen en el PDF.">
                        <TextArea v-model="form.notes" :rows="4" maxlength="5000" :readonly="readonly" />
                    </FormField>
                </div>
            </section>
        </div>

        <template #footer>
            <AppButton :disabled="form.processing" @click="close">{{ readonly ? 'Cerrar' : 'Cancelar' }}</AppButton>
            <AppButton v-if="!readonly && !loadError" variant="primary" type="submit" icon="content-save-outline" :loading="form.processing" :disabled="loading || !form.items.length">{{ editing ? 'Guardar cambios' : 'Crear cotización' }}</AppButton>
        </template>
    </BaseModal>
</template>
