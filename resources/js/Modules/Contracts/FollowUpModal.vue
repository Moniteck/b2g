<script setup>
// Registrar un seguimiento del contrato. La próxima acción con fecha va al calendario y avisa ese día.
import { useForm } from '@inertiajs/vue3';
import { nextTick, ref, watch } from 'vue';
import AppButton from '../../Components/AppButton.vue';
import BaseModal from '../../Components/BaseModal.vue';
import DateInput from '../../Components/DateInput.vue';
import FormField from '../../Components/FormField.vue';
import SelectInput from '../../Components/SelectInput.vue';
import TextArea from '../../Components/TextArea.vue';
import TextInput from '../../Components/TextInput.vue';
import { focusFirstError } from '../../lib/errors.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    contractId: { type: Number, required: true },
    types: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:open']);
const root = ref(null);
const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'America/Tegucigalpa' });
const blank = () => ({ type: 'Seguimiento', description: '', occurred_at: today(), next_action: '', next_action_at: '' });
const form = useForm(blank());

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        form.defaults(blank());
        form.reset();
        form.clearErrors();
    },
);

function submit() {
    form.post(`/post-adjudicacion/${props.contractId}/seguimientos`, {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
        onError: () => nextTick(() => focusFirstError(root.value)),
    });
}
</script>

<template>
    <BaseModal :open="open" as="form" size="lg" title="Nuevo seguimiento" description="La próxima acción aparece en el calendario y avisa el mismo día." :persistent="form.processing || form.isDirty" @update:open="emit('update:open', $event)" @submit="submit">
        <div ref="root" class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
            <FormField label="Tipo" required :error="form.errors.type"><SelectInput v-model="form.type" :options="types" placeholder="" /></FormField>
            <FormField label="Fecha" required :error="form.errors.occurred_at"><DateInput v-model="form.occurred_at" /></FormField>
            <FormField label="Nota" required class="md:col-span-2" :error="form.errors.description"><TextArea v-model="form.description" :rows="3" maxlength="2000" /></FormField>
            <FormField label="Próxima acción" optional :error="form.errors.next_action"><TextInput v-model="form.next_action" maxlength="255" placeholder="Ej.: Llamar al administrador del contrato" /></FormField>
            <FormField label="Fecha de la próxima acción" optional :error="form.errors.next_action_at"><DateInput v-model="form.next_action_at" :min="today()" /></FormField>
        </div>
        <template #footer>
            <AppButton :disabled="form.processing" @click="emit('update:open', false)">Cancelar</AppButton>
            <AppButton variant="primary" type="submit" icon="content-save-outline" :loading="form.processing">Registrar seguimiento</AppButton>
        </template>
    </BaseModal>
</template>
