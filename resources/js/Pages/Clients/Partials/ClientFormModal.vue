<script setup>
// Modal para editar los datos y el contacto de un cliente. Se abre al recibir `client` y emite `close`.
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppButton from '@/Components/AppButton.vue';
import BaseModal from '@/Components/BaseModal.vue';
import FormField from '@/Components/FormField.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextArea from '@/Components/TextArea.vue';
import TextInput from '@/Components/TextInput.vue';
import { focusFirstError } from '@/lib/errors.js';

const props = defineProps({ client: { type: Object, default: null } });
const emit = defineEmits(['close']);

const form = useForm({ name: '', tax_id: '', sector: '', contact_name: '', phone: '', email: '', address: '', status: 'Activo', notes: '' });
const body = ref(null);

const open = computed({
    get: () => props.client !== null,
    set: (value) => {
        if (!value) emit('close');
    },
});

watch(
    () => props.client,
    (client) => {
        if (!client) return;
        form.defaults({
            name: client.name ?? '',
            tax_id: client.tax_id ?? '',
            sector: client.sector ?? '',
            contact_name: client.contact_name ?? '',
            phone: client.phone ?? '',
            email: client.email ?? '',
            address: client.address ?? '',
            status: client.status ?? 'Activo',
            notes: client.notes ?? '',
        });
        form.reset();
        form.clearErrors();
    },
    { immediate: true },
);

function save() {
    form.put(`/clientes/${props.client.id}`, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
        onError: () => focusFirstError(body.value),
    });
}
</script>

<template>
    <BaseModal v-model:open="open" as="form" size="lg" title="Editar cliente" :description="client?.name" :persistent="form.processing" @submit="save">
        <div ref="body" class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
            <FormField label="Nombre" required :error="form.errors.name" class="md:col-span-2"><TextInput v-model="form.name" maxlength="255" /></FormField>
            <FormField label="RTN" optional :error="form.errors.tax_id" help="Único por empresa."><TextInput v-model="form.tax_id" maxlength="50" inputmode="numeric" /></FormField>
            <FormField label="Sector" optional :error="form.errors.sector"><TextInput v-model="form.sector" maxlength="100" /></FormField>
            <FormField label="Persona de contacto" optional :error="form.errors.contact_name"><TextInput v-model="form.contact_name" maxlength="255" /></FormField>
            <FormField label="Teléfono" optional :error="form.errors.phone"><TextInput v-model="form.phone" type="tel" maxlength="50" /></FormField>
            <FormField label="Correo" optional :error="form.errors.email"><TextInput v-model="form.email" type="email" maxlength="255" /></FormField>
            <FormField label="Estado" :error="form.errors.status">
                <SelectInput v-model="form.status" placeholder="" :options="[{ value: 'Activo', label: 'Activo' }, { value: 'Inactivo', label: 'Inactivo' }]" />
            </FormField>
            <FormField label="Dirección" optional :error="form.errors.address" class="md:col-span-2"><TextArea v-model="form.address" :rows="2" maxlength="2000" /></FormField>
            <FormField label="Notas" optional :error="form.errors.notes" class="md:col-span-2"><TextArea v-model="form.notes" :rows="3" maxlength="5000" /></FormField>
        </div>
        <template #footer>
            <AppButton :disabled="form.processing" @click="emit('close')">Cancelar</AppButton>
            <AppButton variant="primary" type="submit" icon="content-save-outline" :loading="form.processing">Guardar cambios</AppButton>
        </template>
    </BaseModal>
</template>
