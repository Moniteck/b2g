<script setup>
// Editar los datos de un documento del directorio (no cambia el archivo).
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import AppButton from '@/Components/AppButton.vue';
import BaseModal from '@/Components/BaseModal.vue';
import DateInput from '@/Components/DateInput.vue';
import FormField from '@/Components/FormField.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextArea from '@/Components/TextArea.vue';
import TextInput from '@/Components/TextInput.vue';
import { focusFirstError } from '@/lib/errors.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    document: { type: Object, default: null },
    categories: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:open']);

const form = useForm({ name: '', category: '', reference_number: '', owner_name: '', issued_at: '', expires_at: '', description: '' });

watch(
    () => props.open,
    (open) => {
        if (!open || !props.document) return;
        form.defaults({
            name: props.document.name ?? '',
            category: props.document.category ?? '',
            reference_number: props.document.referenceNumber ?? '',
            owner_name: props.document.ownerName ?? '',
            issued_at: props.document.issuedAt ?? '',
            expires_at: props.document.expiresAt ?? '',
            description: props.document.description ?? '',
        });
        form.reset();
        form.clearErrors();
    },
);

function submit() {
    form.put(`/documentos/${props.document.id}`, {
        preserveScroll: true,
        only: ['documents', 'stats', 'flash'],
        onSuccess: () => emit('update:open', false),
        onError: () => focusFirstError(),
    });
}
</script>

<template>
    <BaseModal :open="open" as="form" size="lg" title="Editar datos del documento" :persistent="form.processing" @update:open="emit('update:open', $event)" @submit="submit">
        <div class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
            <FormField label="Nombre" required :error="form.errors.name" class="md:col-span-2">
                <TextInput v-model="form.name" maxlength="255" />
            </FormField>
            <FormField label="Categoría" required :error="form.errors.category">
                <SelectInput v-model="form.category" :options="categories" placeholder="Elige la categoría" />
            </FormField>
            <FormField label="Número o referencia" optional :error="form.errors.reference_number">
                <TextInput v-model="form.reference_number" maxlength="100" />
            </FormField>
            <FormField label="Titular" optional :error="form.errors.owner_name" class="md:col-span-2">
                <TextInput v-model="form.owner_name" maxlength="255" placeholder="Ej.: nombre del representante legal" />
            </FormField>
            <FormField label="Emisión" optional :error="form.errors.issued_at">
                <DateInput v-model="form.issued_at" />
            </FormField>
            <FormField label="Vencimiento" optional :error="form.errors.expires_at" help="Vacío si el documento no vence.">
                <DateInput v-model="form.expires_at" :min="form.issued_at || undefined" />
            </FormField>
            <FormField label="Notas" optional :error="form.errors.description" class="md:col-span-2">
                <TextArea v-model="form.description" rows="3" maxlength="500" />
            </FormField>
        </div>
        <template #footer>
            <AppButton :disabled="form.processing" @click="emit('update:open', false)">Cancelar</AppButton>
            <AppButton variant="primary" type="submit" icon="content-save-outline" :loading="form.processing">Guardar cambios</AppButton>
        </template>
    </BaseModal>
</template>
