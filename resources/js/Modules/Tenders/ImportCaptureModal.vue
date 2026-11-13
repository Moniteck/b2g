<script setup>
// Importar la captura de la ficha de HonduCompras: se sube la imagen, el servidor la lee con OCR y
// abre la pantalla de revisión. Nada se guarda hasta confirmar allí.
import { ref, watch } from 'vue';
import CapAlert from '../../../design/vue/CapAlert.vue';
import AppButton from '../../Components/AppButton.vue';
import BaseModal from '../../Components/BaseModal.vue';
import FileDropzone from '../../Components/FileDropzone.vue';
import { useUpload } from '../../composables/useUpload.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    maxSizeMb: { type: Number, default: 10 },
});

const emit = defineEmits(['update:open']);

const files = ref([]);
const uploader = useUpload();

watch(
    () => props.open,
    (open) => {
        if (open) {
            files.value = [];
            uploader.reset();
        }
    },
);

function send() {
    // Al terminar, el servidor redirige a la revisión y Inertia cambia de página.
    uploader.upload('/licitaciones/importar', files.value, { field: 'file', preserveState: false });
}
</script>

<template>
    <BaseModal :open="open" size="md" title="Importar captura de HonduCompras" :persistent="uploader.uploading.value" @update:open="emit('update:open', $event)">
        <div class="flex flex-col gap-4">
            <p class="text-sm text-cap-text-soft">Sube una captura de pantalla de la ficha del expediente (tabla con Expediente, Entidad, fechas, Modalidad…). La leeremos y podrás revisar cada dato antes de guardarlo.</p>
            <FileDropzone v-model="files" :uploader="uploader" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" :max-size-mb="maxSizeMb" title="Arrastra la captura o elígela" hint="PNG, JPG o WebP" />
            <CapAlert v-if="uploader.uploading.value" status="info" title="Leyendo la captura…" detail="El reconocimiento de texto tarda unos segundos." />
        </div>
        <template #footer>
            <AppButton :disabled="uploader.uploading.value" @click="emit('update:open', false)">Cancelar</AppButton>
            <AppButton variant="primary" icon="text-recognition" :disabled="!files.length" :loading="uploader.uploading.value" @click="send">Leer captura</AppButton>
        </template>
    </BaseModal>
</template>
