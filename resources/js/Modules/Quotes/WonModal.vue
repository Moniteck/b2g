<script setup>
// Registrar una oferta como Ganada (cotización u oportunidad sin cotización: decisión 13).
// Props: open (v-model:open), subject ({ type: 'quote' | 'opportunity', id, label, amount, quoteId? }).
// Eventos: done (resultado del servidor: { outcome, already_applied, client, contract, … }).
// Crea o reutiliza el cliente y abre el contrato en «Pendiente de contrato»; repetir no duplica.
import { Link } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import CapAlert from '../../../design/vue/CapAlert.vue';
import AppButton from '../../Components/AppButton.vue';
import BaseModal from '../../Components/BaseModal.vue';
import DateInput from '../../Components/DateInput.vue';
import FormField from '../../Components/FormField.vue';
import MoneyInput from '../../Components/MoneyInput.vue';
import { money } from '../../lib/format.js';
import { textLink } from '../../lib/ui.js';
import { flatErrors, markWon } from './api.js';
import { today } from './quotes.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    subject: { type: Object, default: null },
});

const emit = defineEmits(['update:open', 'done']);

const form = ref({ awarded_amount: null, awarded_at: today() });
const errors = ref({});
const sending = ref(false);
const result = ref(null);
const message = ref('');

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        form.value = { awarded_amount: props.subject?.amount ?? null, awarded_at: today() };
        errors.value = {};
        result.value = null;
        message.value = '';
    },
);

async function submit() {
    if (sending.value || result.value) return;
    sending.value = true;
    errors.value = {};
    try {
        const response = await markWon(props.subject, { ...form.value, quote_id: props.subject?.quoteId ?? null });
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
            <CapAlert v-if="errors.outcome || errors.quote_id" status="error" title="No se pudo registrar" :detail="errors.outcome || errors.quote_id" />
            <p class="text-sm text-cap-text-soft">Se registra el cliente (o se reutiliza si ya existe), se abre el contrato en «Pendiente de contrato», la oportunidad pasa a Adjudicado y las demás cotizaciones de la oportunidad quedan Sustituidas.</p>
            <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
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
                <AppButton variant="primary" type="submit" icon="trophy-outline" :loading="sending" :disabled="sending">Registrar como Ganada</AppButton>
            </template>
        </template>
    </BaseModal>
</template>
