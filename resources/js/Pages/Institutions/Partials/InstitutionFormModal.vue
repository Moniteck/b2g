<script setup>
// Crear o editar una institución. `institution` = {} para crear, un registro para editar, null cerrada.
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppButton from '@/Components/AppButton.vue';
import BaseModal from '@/Components/BaseModal.vue';
import FormField from '@/Components/FormField.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextArea from '@/Components/TextArea.vue';
import TextInput from '@/Components/TextInput.vue';
import { focusFirstError } from '@/lib/errors.js';

const props = defineProps({
    institution: { type: Object, default: null },
    options: { type: Object, default: () => ({ types: [], potentials: [], statuses: [] }) },
});
const emit = defineEmits(['close']);

const EMPTY = { name: '', acronym: '', type: '', potential: '', location: '', contact_name: '', phone: '', email: '', status: 'Activa', notes: '' };
const form = useForm({ ...EMPTY });
const body = ref(null);

const isNew = computed(() => !props.institution?.id);
const open = computed({
    get: () => props.institution !== null,
    set: (value) => {
        if (!value) emit('close');
    },
});

const POTENTIAL_LABELS = { ALTO: 'Alto', MEDIO: 'Medio', BAJO: 'Bajo' };

watch(
    () => props.institution,
    (institution) => {
        if (!institution) return;
        form.defaults(Object.fromEntries(Object.keys(EMPTY).map((key) => [key, institution[key] ?? EMPTY[key]])));
        form.reset();
        form.clearErrors();
    },
    { immediate: true },
);

function save() {
    const options = { preserveScroll: true, onSuccess: () => emit('close'), onError: () => focusFirstError(body.value) };
    if (isNew.value) form.post('/instituciones', options);
    else form.put(`/instituciones/${props.institution.id}`, options);
}
</script>

<template>
    <BaseModal v-model:open="open" as="form" size="lg" :title="isNew ? 'Nueva institución' : 'Editar institución'" :description="isNew ? 'Catálogo compartido por todas las empresas del grupo.' : institution?.name" :persistent="form.processing" @submit="save">
        <div ref="body" class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
            <FormField label="Nombre" required :error="form.errors.name" class="md:col-span-2"><TextInput v-model="form.name" maxlength="255" placeholder="Secretaría de Salud" /></FormField>
            <FormField label="Siglas" optional :error="form.errors.acronym"><TextInput v-model="form.acronym" maxlength="50" placeholder="SESAL" /></FormField>
            <FormField label="Tipo" optional :error="form.errors.type"><SelectInput v-model="form.type" :options="options.types" /></FormField>
            <FormField label="Potencial" optional :error="form.errors.potential">
                <SelectInput v-model="form.potential" :options="options.potentials.map((value) => ({ value, label: POTENTIAL_LABELS[value] ?? value }))" />
            </FormField>
            <FormField label="Estado" :error="form.errors.status"><SelectInput v-model="form.status" placeholder="" :options="options.statuses" /></FormField>
            <FormField label="Ubicación" optional :error="form.errors.location" class="md:col-span-2"><TextInput v-model="form.location" maxlength="255" placeholder="Tegucigalpa, M.D.C." /></FormField>
            <FormField label="Contacto general" optional :error="form.errors.contact_name"><TextInput v-model="form.contact_name" maxlength="255" /></FormField>
            <FormField label="Teléfono" optional :error="form.errors.phone"><TextInput v-model="form.phone" type="tel" maxlength="50" /></FormField>
            <FormField label="Correo" optional :error="form.errors.email" class="md:col-span-2"><TextInput v-model="form.email" type="email" maxlength="255" /></FormField>
            <FormField label="Notas" optional :error="form.errors.notes" class="md:col-span-2"><TextArea v-model="form.notes" :rows="3" maxlength="5000" /></FormField>
        </div>
        <template #footer>
            <AppButton :disabled="form.processing" @click="emit('close')">Cancelar</AppButton>
            <AppButton variant="primary" type="submit" :icon="isNew ? 'plus' : 'content-save-outline'" :loading="form.processing">{{ isNew ? 'Crear institución' : 'Guardar cambios' }}</AppButton>
        </template>
    </BaseModal>
</template>
