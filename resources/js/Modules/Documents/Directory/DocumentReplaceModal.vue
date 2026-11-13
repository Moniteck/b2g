<script setup>
// Reemplazar el archivo de un documento: crea una versión nueva; la actual queda en el historial.
import { ref, watch } from 'vue';
import CapAlert from '../../../../design/vue/CapAlert.vue';
import AppButton from '../../../Components/AppButton.vue';
import BaseModal from '../../../Components/BaseModal.vue';
import FileDropzone from '../../../Components/FileDropzone.vue';
import { useUpload } from '../../../composables/useUpload.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    document: { type: Object, default: null },
    limits: { type: Object, default: () => ({ pdfMb: 40, accept: '.pdf' }) },
});

const emit = defineEmits(['update:open']);

const uploader = useUpload();
const files = ref([]);

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        files.value = [];
        uploader.reset();
    },
);

async function submit() {
    if (!files.value.length) return;
    const ok = await uploader.upload(`/documentos/${props.document.id}/replace`, files.value, { only: ['documents', 'stats', 'flash'] });
    if (ok) emit('update:open', false);
}
</script>

<template>
    <BaseModal :open="open" as="form" size="md" title="Reemplazar archivo" :description="document?.name" :persistent="uploader.uploading.value" @update:open="emit('update:open', $event)" @submit="submit">
        <div class="flex flex-col gap-4">
            <CapAlert status="info" :title="`La versión ${document?.version ?? 1} se conserva`" detail="El archivo nuevo pasa a ser el vigente y el anterior queda en el historial de versiones. Las fechas no cambian; para una nueva vigencia usa «Renovar»." />
            <FileDropzone v-model="files" :uploader="uploader" :accept="limits.accept" :max-size-mb="limits.pdfMb" title="Arrastra el archivo nuevo aquí o elígelo" />
        </div>
        <template #footer>
            <AppButton :disabled="uploader.uploading.value" @click="emit('update:open', false)">Cancelar</AppButton>
            <AppButton variant="primary" type="submit" icon="file-replace-outline" :loading="uploader.uploading.value" :disabled="!files.length">Reemplazar archivo</AppButton>
        </template>
    </BaseModal>
</template>
