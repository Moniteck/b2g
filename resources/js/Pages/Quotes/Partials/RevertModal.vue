<script setup>
// Revertir el resultado (solo administrador, decisión 15). Props: open, subject ({ type, id, label, outcome }).
// Eventos: done (resultado, mensaje).
import { ref, watch } from 'vue';
import CapAlert from '@design/vue/CapAlert.vue';
import AppButton from '@/Components/AppButton.vue';
import BaseModal from '@/Components/BaseModal.vue';
import FormField from '@/Components/FormField.vue';
import TextArea from '@/Components/TextArea.vue';
import { flatErrors, revertOutcome } from '@/Pages/Quotes/Partials/api.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    subject: { type: Object, default: null },
});

const emit = defineEmits(['update:open', 'done']);

const reason = ref('');
const errors = ref({});
const sending = ref(false);

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        reason.value = '';
        errors.value = {};
    },
);

async function submit() {
    if (sending.value) return;
    sending.value = true;
    errors.value = {};
    try {
        const response = await revertOutcome(props.subject, reason.value);
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
    <BaseModal :open="open" as="form" size="md" :title="`Revertir resultado${subject?.label ? ' · ' + subject.label : ''}`" :persistent="sending" @update:open="emit('update:open', $event)" @submit="submit">
        <div class="flex flex-col gap-4">
            <CapAlert v-if="errors.outcome" status="error" title="No se puede revertir" :detail="errors.outcome" />
            <CapAlert status="warning" title="Qué pasa al revertir" :detail="subject?.outcome === 'Ganada' ? 'El contrato pasa a Cancelado (no se borra), el cliente se conserva con el monto descontado de su acumulado y la oferta vuelve a Presentada. No se puede si el contrato ya tiene documentos, entregas o seguimientos.' : 'La oferta y su oportunidad vuelven a Oferta Presentada y se quita la marca de ganador.'" />
            <FormField label="Motivo" required :error="errors.reason" help="Queda en la auditoría.">
                <TextArea v-model="reason" :rows="3" maxlength="2000" data-autofocus />
            </FormField>
        </div>
        <template #footer>
            <AppButton :disabled="sending" @click="emit('update:open', false)">Cancelar</AppButton>
            <AppButton variant="danger" type="submit" icon="undo-variant" :loading="sending" :disabled="sending || reason.trim().length < 5">Revertir resultado</AppButton>
        </template>
    </BaseModal>
</template>
