<script setup>
// Renovar un documento: nueva emisión y vencimiento y, si se adjunta, el archivo renovado
// (que crea una versión nueva). Sin archivo solo cambian las fechas.
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AppButton from '../../../Components/AppButton.vue';
import BaseModal from '../../../Components/BaseModal.vue';
import DateInput from '../../../Components/DateInput.vue';
import FileDropzone from '../../../Components/FileDropzone.vue';
import FormField from '../../../Components/FormField.vue';
import { date } from '../../../lib/format.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    document: { type: Object, default: null },
    limits: { type: Object, default: () => ({ pdfMb: 40, accept: '.pdf' }) },
});

const emit = defineEmits(['update:open']);

const files = ref([]);
const form = useForm({ issued_at: '', expires_at: '' });
const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'America/Tegucigalpa' });

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        files.value = [];
        form.defaults({ issued_at: today(), expires_at: '' });
        form.reset();
        form.clearErrors();
    },
);

function submit() {
    form
        .transform((data) => ({ ...data, _method: 'patch', ...(files.value[0] ? { file: files.value[0] } : {}) }))
        .post(`/documentos/${props.document.id}/renovar`, {
            forceFormData: true,
            preserveScroll: true,
            only: ['documents', 'stats', 'flash'],
            onSuccess: () => emit('update:open', false),
        });
}
</script>

<template>
    <BaseModal :open="open" as="form" size="md" title="Renovar documento" :description="document?.name" :persistent="form.processing" @update:open="emit('update:open', $event)" @submit="submit">
        <div class="flex flex-col gap-4">
            <p v-if="document?.expiresAt" class="text-sm text-cap-text-soft">Vencimiento actual: <span class="font-medium text-gray-900 dark:text-gray-100">{{ date(document.expiresAt, 'medium') }}</span></p>
            <div class="grid grid-cols-2 gap-x-4 gap-y-4">
                <FormField label="Nueva emisión" required :error="form.errors.issued_at">
                    <DateInput v-model="form.issued_at" />
                </FormField>
                <FormField label="Nuevo vencimiento" optional :error="form.errors.expires_at">
                    <DateInput v-model="form.expires_at" :min="form.issued_at || undefined" />
                </FormField>
            </div>
            <FormField label="Archivo renovado" optional :error="form.errors.file" help="Si subes el documento nuevo, queda como versión vigente y el anterior en el historial.">
                <FileDropzone v-model="files" :accept="limits.accept" :max-size-mb="limits.pdfMb" :errors="form.errors.file ? { 0: form.errors.file } : {}" :disabled="form.processing" />
            </FormField>
            <div v-if="form.progress" class="h-1 overflow-hidden rounded-full bg-cap-divider" role="progressbar" aria-label="Subiendo el archivo renovado" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="form.progress.percentage">
                <div class="h-full rounded-full bg-cap-brand transition-[width] duration-cap-slow ease-cap dark:bg-cap-brand-soft" :style="{ width: `${form.progress.percentage}%` }" />
            </div>
        </div>
        <template #footer>
            <AppButton :disabled="form.processing" @click="emit('update:open', false)">Cancelar</AppButton>
            <AppButton variant="primary" type="submit" icon="calendar-refresh-outline" :loading="form.processing">Renovar</AppButton>
        </template>
    </BaseModal>
</template>
