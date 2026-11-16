<script setup>
// Registrar una oferta como Ganada (cotización u oportunidad sin cotización: decisión 13).
// Props: open (v-model:open), subject ({ type: 'quote' | 'opportunity', id, label, amount, quoteId?,
//        quotesCount? }).
// Eventos: done (resultado del servidor: { outcome, already_applied, client, contract, … }).
// Crea o reutiliza el cliente y abre el contrato en «Pendiente de contrato»; repetir no duplica.
// Si el sujeto es una oportunidad con varias cotizaciones vivas, pide cuál se ganó (`quote_id`):
// las lee de `GET /oportunidades/{id}/cotizaciones-vivas` (una consulta). Si el sujeto trae
// `quotesCount` (cotizaciones no cerradas de la tarjeta del tablero) y es 0 o 1, no pide nada.
import { Link } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import CapAlert from '@design/vue/CapAlert.vue';
import AppButton from '@/Components/AppButton.vue';
import BaseModal from '@/Components/BaseModal.vue';
import DateInput from '@/Components/DateInput.vue';
import FormField from '@/Components/FormField.vue';
import MoneyInput from '@/Components/MoneyInput.vue';
import SelectInput from '@/Components/SelectInput.vue';
import { getJson } from '@/lib/http.js';
import { money } from '@/lib/format.js';
import { textLink } from '@/lib/ui.js';
import { flatErrors, markWon } from '@/Pages/Quotes/Partials/api.js';
import { today } from '@/Pages/Quotes/Partials/quotes.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    subject: { type: Object, default: null },
});

const emit = defineEmits(['update:open', 'done']);

const form = ref({ awarded_amount: null, awarded_at: today(), quote_id: null });
const liveQuotes = ref([]);
const loadingQuotes = ref(false);
const quoteOptions = computed(() => liveQuotes.value.map((quote) => ({ value: quote.id, label: `${quote.number} · ${quote.status} · ${money(quote.total)}` })));
const needsQuote = computed(() => liveQuotes.value.length > 1);
const errors = ref({});
const sending = ref(false);
const result = ref(null);
const message = ref('');

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        form.value = { awarded_amount: props.subject?.amount ?? null, awarded_at: today(), quote_id: props.subject?.quoteId ?? null };
        errors.value = {};
        result.value = null;
        message.value = '';
        liveQuotes.value = [];
        loadLiveQuotes();
    },
);

async function loadLiveQuotes() {
    if (props.subject?.type !== 'opportunity' || props.subject?.quoteId) return;
    // Con 0 o 1 cotizaciones abiertas no hay nada que elegir: el servidor usa la única (o ninguna).
    if (typeof props.subject.quotesCount === 'number' && props.subject.quotesCount <= 1) return;
    const subjectId = props.subject.id;
    loadingQuotes.value = true;
    try {
        const response = await getJson(`/oportunidades/${subjectId}/cotizaciones-vivas`);
        if (!props.open || props.subject?.id !== subjectId) return;
        liveQuotes.value = response?.data ?? [];
    } catch {
        // Sin el detalle se intenta igual: el servidor pedirá la cotización si hace falta.
        liveQuotes.value = [];
    } finally {
        loadingQuotes.value = false;
    }
}

// Al elegir la cotización, el monto propuesto pasa a ser su total (si el usuario no lo cambió).
watch(
    () => form.value.quote_id,
    (quoteId, previous) => {
        const chosen = liveQuotes.value.find((quote) => quote.id === quoteId);
        const before = liveQuotes.value.find((quote) => quote.id === previous);
        const untouched = form.value.awarded_amount === null || form.value.awarded_amount === (before?.total ?? props.subject?.amount ?? null);
        if (chosen && untouched) form.value.awarded_amount = chosen.total;
    },
);

async function submit() {
    if (sending.value || result.value) return;
    if (needsQuote.value && !form.value.quote_id) {
        errors.value = { quote_id: 'Elija la cotización que se ganó.' };
        return;
    }
    sending.value = true;
    errors.value = {};
    try {
        const response = await markWon(props.subject, { ...form.value, quote_id: form.value.quote_id ?? props.subject?.quoteId ?? null });
        result.value = response.result;
        message.value = response.message;
        emit('done', response.result);
    } catch (failure) {
        errors.value = flatErrors(failure);
        if (!Object.keys(errors.value).length) errors.value = { outcome: failure.message };
    } finally {
        sending.value = false;
    }
}
</script>

<template>
    <BaseModal :open="open" as="form" size="md" :title="result ? 'Oferta ganada' : `Registrar como Ganada${subject?.label ? ' · ' + subject.label : ''}`" :persistent="sending" @update:open="emit('update:open', $event)" @submit="submit">
        <div v-if="result" class="flex flex-col gap-4">
            <CapAlert :status="result.already_applied ? 'info' : 'ok'" :title="result.already_applied ? 'Ya estaba registrada como ganada' : '¡Oferta ganada!'" :detail="message" />
            <ul class="flex flex-col gap-2 text-sm">
                <li v-if="result.client" class="flex items-center gap-2">
                    <i class="mdi mdi-domain text-xl leading-none text-cap-text-soft" aria-hidden="true" />
                    <span>Cliente: <Link :href="result.client.url" :class="textLink">{{ result.client.name }}</Link></span>
                </li>
                <li v-if="result.contract" class="flex items-center gap-2">
                    <i class="mdi mdi-file-sign text-xl leading-none text-cap-text-soft" aria-hidden="true" />
                    <span>Contrato: <Link :href="result.contract.url" :class="textLink">{{ result.contract.number }}</Link> · {{ result.contract.status }}</span>
                </li>
            </ul>
        </div>
        <div v-else class="flex flex-col gap-4">
            <CapAlert v-if="errors.outcome || errors.stage || (errors.quote_id && !needsQuote)" status="error" title="No se pudo registrar" :detail="errors.outcome || errors.stage || errors.quote_id" />
            <p class="text-sm text-cap-text-soft">Se registra el cliente (o se reutiliza si ya existe), se abre el contrato en «Pendiente de contrato», la oportunidad pasa a Adjudicado y las demás cotizaciones de la oportunidad quedan Sustituidas.</p>
            <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                <FormField v-if="needsQuote" label="Cotización ganada" required class="sm:col-span-2" :error="errors.quote_id" help="La oportunidad tiene varias cotizaciones vivas; las demás quedarán Sustituidas.">
                    <SelectInput v-model="form.quote_id" :options="quoteOptions" placeholder="Elegir cotización" required />
                </FormField>
                <FormField label="Monto adjudicado" required :error="errors.awarded_amount" :help="subject?.amount ? `Total cotizado: ${money(subject.amount)}` : ''">
                    <MoneyInput v-model="form.awarded_amount" :min="0" data-autofocus />
                </FormField>
                <FormField label="Fecha de adjudicación" required :error="errors.awarded_at">
                    <DateInput v-model="form.awarded_at" :max="today()" />
                </FormField>
            </div>
        </div>
        <template #footer>
            <template v-if="result">
                <AppButton variant="primary" @click="emit('update:open', false)">Listo</AppButton>
            </template>
            <template v-else>
                <AppButton :disabled="sending" @click="emit('update:open', false)">Cancelar</AppButton>
                <AppButton variant="primary" type="submit" icon="trophy-outline" :loading="sending" :disabled="sending || loadingQuotes">Registrar como Ganada</AppButton>
            </template>
        </template>
    </BaseModal>
</template>
