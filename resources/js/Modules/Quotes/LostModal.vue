<script setup>
// Registrar una oferta como Perdida: exige quién ganó (de los participantes o escrito) y el valor de su
// oferta; motivo opcional. Props: open (v-model:open), subject ({ type, id, label, quoteId? }). Eventos: done.
import { computed, ref, watch } from 'vue';
import CapAlert from '../../../design/vue/CapAlert.vue';
import CapSegmented from '../../../design/vue/CapSegmented.vue';
import AppButton from '../../Components/AppButton.vue';
import BaseModal from '../../Components/BaseModal.vue';
import DateInput from '../../Components/DateInput.vue';
import FormField from '../../Components/FormField.vue';
import MoneyInput from '../../Components/MoneyInput.vue';
import SelectInput from '../../Components/SelectInput.vue';
import TextArea from '../../Components/TextArea.vue';
import TextInput from '../../Components/TextInput.vue';
import { money } from '../../lib/format.js';
import { fetchParticipants, flatErrors, markLost } from './api.js';
import { today } from './quotes.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    subject: { type: Object, default: null },
});

const emit = defineEmits(['update:open', 'done']);

const mode = ref('write');
const rivals = ref([]);
const form = ref({});
const errors = ref({});
const sending = ref(false);

const rivalOptions = computed(() => rivals.value.map((row) => ({ value: row.id, label: `${row.name} · ${money(row.amount, row.currency)}` })));
const modes = computed(() => [
    { label: 'Elegir participante', value: 'choose', count: rivals.value.length },
    { label: 'Escribir', value: 'write' },
]);

watch(
    () => props.open,
    async (open) => {
        if (!open) return;
        form.value = { winner_participant_id: null, winner_name: '', winner_tax_id: '', winner_amount: null, lost_reason: '', awarded_at: today() };
        errors.value = {};
        rivals.value = [];
        mode.value = 'write';
        try {
            const response = await fetchParticipants(props.subject);
            rivals.value = response.data.filter((row) => !row.is_ours);
            if (rivals.value.length) mode.value = 'choose';
        } catch {
            rivals.value = [];
        }
    },
);

watch(
    () => form.value.winner_participant_id,
    (id) => {
        const rival = rivals.value.find((row) => row.id === Number(id));
        if (rival) form.value.winner_amount = rival.amount;
    },
);

async function submit() {
    if (sending.value) return;
    sending.value = true;
    errors.value = {};
    const data = { ...form.value, quote_id: props.subject?.quoteId ?? null };
    if (mode.value === 'choose') {
        data.winner_name = null;
        data.winner_tax_id = null;
    } else {
        data.winner_participant_id = null;
    }
    if (mode.value === 'choose' && !data.winner_participant_id) {
        errors.value = { winner_participant_id: 'Elija quién ganó la oferta.' };
        sending.value = false;
        return;
    }
    try {
        const response = await markLost(props.subject, data);
        emit('done', response.result, response.message);
        emit('update:open', false);
    } catch (failure) {
        errors.value = flatErrors(failure);
        if (!Object.keys(errors.value).length) errors.value = { outcome: failure.message };
    } finally {
        sending.value = false;
    }
}
</script>

<template>
    <BaseModal :open="open" as="form" size="md" :title="`Registrar como Perdida${subject?.label ? ' · ' + subject.label : ''}`" description="La oportunidad pasa a No Adjudicado y el ganador queda entre los participantes." :persistent="sending" @update:open="emit('update:open', $event)" @submit="submit">
        <div class="flex flex-col gap-4">
            <CapAlert v-if="errors.outcome" status="error" title="No se pudo registrar" :detail="errors.outcome" />
            <div v-if="rivals.length" class="max-w-full overflow-x-auto">
                <CapSegmented v-model="mode" :items="modes" label="Cómo indicar al ganador" />
            </div>
            <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                <FormField v-if="mode === 'choose'" label="Quién ganó" required class="sm:col-span-2" :error="errors.winner_participant_id">
                    <SelectInput v-model="form.winner_participant_id" :options="rivalOptions" placeholder="Elegir participante" />
                </FormField>
                <template v-else>
                    <FormField label="Quién ganó" required class="sm:col-span-2" :error="errors.winner_name">
                        <TextInput v-model="form.winner_name" maxlength="255" placeholder="Nombre de la empresa ganadora" data-autofocus />
                    </FormField>
                    <FormField label="RTN del ganador" optional :error="errors.winner_tax_id">
                        <TextInput v-model="form.winner_tax_id" maxlength="100" />
                    </FormField>
                </template>
                <FormField label="Valor de su oferta" required :error="errors.winner_amount">
                    <MoneyInput v-model="form.winner_amount" :min="0" />
                </FormField>
                <FormField label="Fecha del resultado" optional :error="errors.awarded_at">
                    <DateInput v-model="form.awarded_at" :max="today()" />
                </FormField>
                <FormField label="Motivo" optional class="sm:col-span-2" :error="errors.lost_reason">
                    <TextArea v-model="form.lost_reason" :rows="3" maxlength="2000" placeholder="Precio, requisito técnico, documentación…" />
                </FormField>
            </div>
        </div>
        <template #footer>
            <AppButton :disabled="sending" @click="emit('update:open', false)">Cancelar</AppButton>
            <AppButton variant="primary" type="submit" icon="close-circle-outline" :loading="sending" :disabled="sending">Registrar como Perdida</AppButton>
        </template>
    </BaseModal>
</template>
