<script setup>
// Marcar una entrega como entregada, con la fecha real y la evidencia (acta, guía de remisión, foto).
// La evidencia queda en los documentos del contrato con la categoría «Acta de recepción».
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AppButton from '@/Components/AppButton.vue';
import BaseModal from '@/Components/BaseModal.vue';
import DateInput from '@/Components/DateInput.vue';
import FileDropzone from '@/Components/FileDropzone.vue';
import FormField from '@/Components/FormField.vue';
import TextArea from '@/Components/TextArea.vue';
import { quantity } from '@/lib/format.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    contractId: { type: Number, required: true },
    delivery: { type: Object, default: null },
    maxSizeMb: { type: Number, default: 40 },
});

const emit = defineEmits(['update:open']);
const files = ref([]);
const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'America/Tegucigalpa' });
const form = useForm({ delivered_at: today(), notes: '', evidence: null });

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        files.value = [];
        form.defaults({ delivered_at: today(), notes: props.delivery?.notes ?? '', evidence: null });
        form.reset();
        form.clearErrors();
    },
);

function submit() {
    form.evidence = files.value[0] ?? null;
    form.post(`/post-adjudicacion/${props.contractId}/entregas/${props.delivery.id}/entregada`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <BaseModal :open="open" as="form" size="md" title="Marcar como entregada" :description="delivery ? `${delivery.description} · ${quantity(delivery.quantity)} ${delivery.unit ?? ''}` : ''" :persistent="form.processing" @update:open="emit('update:open', $event)" @submit="submit">
        <div class="flex flex-col gap-4">
            <FormField label="Fecha real de entrega" required :error="form.errors.delivered_at"><DateInput v-model="form.delivered_at" :max="today()" /></FormField>
            <FormField label="Evidencia" optional :error="form.errors.evidence" help="PDF o imagen del acta de recepción. Se guarda en los documentos del contrato.">
                <FileDropzone v-model="files" accept=".pdf,.jpg,.jpeg,.png" :max-size-mb="maxSizeMb" :invalid="Boolean(form.errors.evidence)" title="Arrastra el acta o haz clic para elegirla" />
            </FormField>
            <FormField label="Notas" optional :error="form.errors.notes"><TextArea v-model="form.notes" :rows="2" maxlength="2000" /></FormField>
        </div>
        <template #footer>
            <AppButton :disabled="form.processing" @click="emit('update:open', false)">Cancelar</AppButton>
            <AppButton variant="primary" type="submit" icon="truck-check-outline" :loading="form.processing">Marcar entregada</AppButton>
        </template>
    </BaseModal>
</template>
