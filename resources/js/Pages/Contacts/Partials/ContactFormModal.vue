<script setup>
// Crear o editar un contacto. `contact` = {} (o { institution_id, institution }) para crear, un registro
// para editar, null cerrada. `lockInstitution` fija la institución (desde la ficha de la institución).
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppButton from '@/Components/AppButton.vue';
import BaseModal from '@/Components/BaseModal.vue';
import FormField from '@/Components/FormField.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import TextInput from '@/Components/TextInput.vue';
import { focusFirstError } from '@/lib/errors.js';

const props = defineProps({
    contact: { type: Object, default: null },
    lockInstitution: { type: Boolean, default: false },
});
const emit = defineEmits(['close']);

const EMPTY = { institution_id: null, name: '', position: '', department: '', email: '', phone: '', whatsapp: '' };
const form = useForm({ ...EMPTY });
const body = ref(null);

const isNew = computed(() => !props.contact?.id);
const open = computed({
    get: () => props.contact !== null,
    set: (value) => {
        if (!value) emit('close');
    },
});

watch(
    () => props.contact,
    (contact) => {
        if (!contact) return;
        form.defaults(Object.fromEntries(Object.keys(EMPTY).map((key) => [key, contact[key] ?? EMPTY[key]])));
        form.reset();
        form.clearErrors();
    },
    { immediate: true },
);

function save() {
    const options = { preserveScroll: true, onSuccess: () => emit('close'), onError: () => focusFirstError(body.value) };
    if (isNew.value) form.post('/contactos', options);
    else form.put(`/contactos/${props.contact.id}`, options);
}
</script>

<template>
    <BaseModal v-model:open="open" as="form" size="lg" :title="isNew ? 'Nuevo contacto' : 'Editar contacto'" :description="isNew ? '' : contact?.name" :persistent="form.processing" @submit="save">
        <div ref="body" class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
            <FormField label="Institución" required :error="form.errors.institution_id" class="md:col-span-2">
                <TextInput v-if="lockInstitution" :model-value="contact?.institution?.name ?? ''" readonly />
                <SearchSelect
                    v-else
                    v-model="form.institution_id"
                    url="/instituciones/buscar"
                    option-value="id"
                    option-label="name"
                    option-description="description"
                    :selected="contact?.institution ?? null"
                    :min-chars="1"
                    placeholder="Buscar institución…"
                />
            </FormField>
            <FormField label="Nombre" required :error="form.errors.name" class="md:col-span-2"><TextInput v-model="form.name" maxlength="255" /></FormField>
            <FormField label="Cargo" optional :error="form.errors.position"><TextInput v-model="form.position" maxlength="255" placeholder="Jefe de Compras" /></FormField>
            <FormField label="Departamento" optional :error="form.errors.department"><TextInput v-model="form.department" maxlength="255" placeholder="Adquisiciones" /></FormField>
            <FormField label="Correo" optional :error="form.errors.email"><TextInput v-model="form.email" type="email" maxlength="255" /></FormField>
            <FormField label="Teléfono" optional :error="form.errors.phone"><TextInput v-model="form.phone" type="tel" maxlength="50" /></FormField>
            <FormField label="WhatsApp" optional :error="form.errors.whatsapp"><TextInput v-model="form.whatsapp" type="tel" maxlength="50" /></FormField>
        </div>
        <template #footer>
            <AppButton :disabled="form.processing" @click="emit('close')">Cancelar</AppButton>
            <AppButton variant="primary" type="submit" :icon="isNew ? 'plus' : 'content-save-outline'" :loading="form.processing">{{ isNew ? 'Crear contacto' : 'Guardar cambios' }}</AppButton>
        </template>
    </BaseModal>
</template>
