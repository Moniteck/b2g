<script setup>
// Enviar la cotización en PDF por correo (hasta 5 destinatarios). Props: open, quote (fila).
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import AppButton from '@/Components/AppButton.vue';
import BaseModal from '@/Components/BaseModal.vue';
import FormField from '@/Components/FormField.vue';
import TextArea from '@/Components/TextArea.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    quote: { type: Object, default: null },
});

const emit = defineEmits(['update:open']);

const form = useForm({ recipients: '', subject: '', message: '' });

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        form.defaults({
            recipients: props.quote?.contact_email ?? '',
            subject: '',
            message: `Estimados señores${props.quote?.institution?.name ? ' de ' + props.quote.institution.name : ''}:\n\nAdjuntamos nuestra cotización ${props.quote?.number ?? ''}. Quedamos atentos a sus comentarios.\n\nSaludos cordiales.`,
        });
        form.reset();
        form.clearErrors();
    },
);

const recipientError = computed(() => form.errors.recipients || Object.entries(form.errors).find(([key]) => key.startsWith('recipients.'))?.[1] || '');

function submit() {
    form.post(`/cotizaciones/${props.quote.id}/email`, { preserveScroll: true, onSuccess: () => emit('update:open', false) });
}
</script>

<template>
    <BaseModal :open="open" as="form" size="md" :title="`Enviar por correo · ${quote?.number ?? ''}`" description="Se adjunta el PDF de la cotización." :persistent="form.processing" @update:open="emit('update:open', $event)" @submit="submit">
        <div class="flex flex-col gap-4">
            <FormField label="Para" required :error="recipientError" help="Hasta 5 correos, separados por coma.">
                <TextInput v-model="form.recipients" type="text" inputmode="email" autocomplete="email" placeholder="compras@institucion.gob.hn" data-autofocus />
            </FormField>
            <FormField label="Asunto" optional :error="form.errors.subject">
                <TextInput v-model="form.subject" maxlength="150" :placeholder="`Cotización ${quote?.number ?? ''}`" />
            </FormField>
            <FormField label="Mensaje" optional :error="form.errors.message">
                <TextArea v-model="form.message" :rows="6" maxlength="3000" />
            </FormField>
        </div>
        <template #footer>
            <AppButton :disabled="form.processing" @click="emit('update:open', false)">Cancelar</AppButton>
            <AppButton variant="primary" type="submit" icon="email-fast-outline" :loading="form.processing" :disabled="!form.recipients.trim()">Enviar cotización</AppButton>
        </template>
    </BaseModal>
</template>
