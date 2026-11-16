<script setup>
// Importar la captura de la ficha de HonduCompras, paso 1 de 3 (Subir → Leer → Revisar).
// La imagen se arrastra, se pega desde el portapapeles (Ctrl+V con la modal abierta), se elige o, en
// el celular, se toma con la cámara. Al subirla, el servidor la pone en cola para el OCR y abre la
// revisión; nada se guarda hasta confirmar allí.
// API estable (la usa también el Dashboard): props `open` (v-model:open) y `maxSizeMb`.
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import CapAlert from '../../../design/vue/CapAlert.vue';
import AppButton from '../../Components/AppButton.vue';
import BaseModal from '../../Components/BaseModal.vue';
import { useUpload } from '../../composables/useUpload.js';
import { fileSize } from '../../lib/format.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    maxSizeMb: { type: Number, default: 10 },
});

const emit = defineEmits(['update:open']);

const ACCEPT = ['image/png', 'image/jpeg', 'image/webp'];
const file = ref(null);
const preview = ref('');
const problem = ref('');
const dragging = ref(false);
const picker = ref(null);
const camera = ref(null);
const uploader = useUpload();
const uploadError = computed(() => Object.values(uploader.errors.value ?? {})[0] ?? '');

const steps = [
    { key: 'subir', label: 'Subir' },
    { key: 'leer', label: 'Leer' },
    { key: 'revisar', label: 'Revisar' },
];

function take(candidate, origin = 'archivo') {
    problem.value = '';
    if (!candidate) return;
    if (!ACCEPT.includes(candidate.type)) {
        problem.value = 'Ese archivo no es una imagen PNG, JPG o WEBP. Sube una captura de pantalla de la ficha.';
        return;
    }
    if (candidate.size > props.maxSizeMb * 1024 * 1024) {
        problem.value = `La imagen pesa ${fileSize(candidate.size)} y el máximo es ${props.maxSizeMb} MB.`;
        return;
    }
    // Lo pegado del portapapeles llega como «image.png»: se le da un nombre que se entienda.
    const named = origin === 'portapapeles' ? new File([candidate], `captura-pegada-${new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-')}.png`, { type: candidate.type }) : candidate;
    if (preview.value) URL.revokeObjectURL(preview.value);
    file.value = named;
    preview.value = URL.createObjectURL(named);
}

function onPaste(event) {
    if (!props.open || uploader.uploading.value) return;
    const item = [...(event.clipboardData?.items ?? [])].find((entry) => entry.kind === 'file' && entry.type.startsWith('image/'));
    if (!item) return;
    event.preventDefault();
    take(item.getAsFile(), 'portapapeles');
}

function onDrop(event) {
    dragging.value = false;
    take(event.dataTransfer?.files?.[0]);
}

function onPick(event) {
    take(event.target.files?.[0]);
    event.target.value = '';
}

function clear() {
    if (preview.value) URL.revokeObjectURL(preview.value);
    file.value = null;
    preview.value = '';
    problem.value = '';
}

function send() {
    if (!file.value) return;
    // Al terminar, el servidor redirige a la revisión y Inertia cambia de página.
    uploader.upload('/licitaciones/importar', [file.value], { field: 'file', preserveState: false });
}

watch(
    () => props.open,
    (open) => {
        if (open) {
            clear();
            uploader.reset();
            window.addEventListener('paste', onPaste);
        } else {
            window.removeEventListener('paste', onPaste);
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    window.removeEventListener('paste', onPaste);
    if (preview.value) URL.revokeObjectURL(preview.value);
});
</script>

<template>
    <BaseModal :open="open" size="md" title="Importar captura de HonduCompras" :persistent="uploader.uploading.value" @update:open="emit('update:open', $event)">
        <div class="flex flex-col gap-4" data-import-modal>
            <ol class="flex items-center gap-2 text-xs" aria-label="Pasos de la importación">
                <li v-for="(step, index) in steps" :key="step.key" class="flex items-center gap-2">
                    <span class="flex h-6 w-6 items-center justify-center rounded-full text-xs font-semibold" :class="index === 0 ? 'bg-cap-chrome text-white dark:bg-gray-100 dark:text-gray-900' : 'bg-cap-card-2 text-cap-text-soft'" :aria-current="index === 0 ? 'step' : undefined">{{ index + 1 }}</span>
                    <span :class="index === 0 ? 'font-semibold text-gray-900 dark:text-gray-100' : 'text-cap-text-soft'">{{ step.label }}</span>
                    <i v-if="index < steps.length - 1" class="mdi mdi-chevron-right text-base text-cap-text-soft" aria-hidden="true" />
                </li>
            </ol>

            <div
                v-if="!file"
                class="flex flex-col items-center gap-3 rounded-cap-card border-2 border-dashed px-4 py-8 text-center transition-colors duration-cap-fast ease-cap"
                :class="dragging ? 'border-cap-brand bg-cap-red-50 dark:border-cap-brand-soft dark:bg-cap-red-600/15' : 'border-cap-divider'"
                data-capture-drop
                @dragenter.prevent="dragging = true"
                @dragover.prevent="dragging = true"
                @dragleave.prevent="dragging = false"
                @drop.prevent="onDrop"
            >
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-cap-red-200 text-cap-red-800 dark:bg-cap-red-600/20 dark:text-cap-brand-soft" aria-hidden="true"><i class="mdi mdi-image-plus-outline text-2xl leading-none" /></span>
                <div>
                    <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">Arrastra la captura aquí</p>
                    <p class="mt-1 text-sm text-cap-text-soft">
                        o pégala con <kbd class="rounded-md border border-cap-divider bg-cap-card-2 px-1.5 py-0.5 text-xs font-medium">Ctrl</kbd> + <kbd class="rounded-md border border-cap-divider bg-cap-card-2 px-1.5 py-0.5 text-xs font-medium">V</kbd>
                    </p>
                </div>
                <div class="flex flex-wrap justify-center gap-2">
                    <AppButton icon="folder-image" @click="picker?.click()">Elegir imagen</AppButton>
                    <AppButton class="lg:hidden" icon="camera-outline" @click="camera?.click()">Tomar foto</AppButton>
                </div>
                <p class="text-xs text-cap-text-soft">PNG, JPG o WEBP · hasta {{ maxSizeMb }} MB</p>
                <input ref="picker" type="file" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" class="sr-only" tabindex="-1" aria-label="Elegir la imagen de la captura" data-capture-input @change="onPick" />
                <input ref="camera" type="file" accept="image/*" capture="environment" class="sr-only" tabindex="-1" aria-label="Tomar una foto de la ficha" @change="onPick" />
            </div>

            <figure v-else class="overflow-hidden rounded-cap-card border border-cap-divider" data-capture-preview>
                <img :src="preview" alt="Vista previa de la captura" class="max-h-64 w-full bg-cap-card-2 object-contain" />
                <figcaption class="flex items-center justify-between gap-3 border-t border-cap-divider px-3 py-2">
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-medium text-gray-900 dark:text-gray-100" :title="file.name">{{ file.name }}</span>
                        <span class="text-xs text-cap-text-soft">{{ fileSize(file.size) }}</span>
                    </span>
                    <AppButton v-if="!uploader.uploading.value" icon="swap-horizontal" @click="clear">Cambiar</AppButton>
                </figcaption>
                <div v-if="uploader.uploading.value" class="h-1 bg-cap-card-2" role="progressbar" :aria-valuenow="uploader.progress.value" aria-valuemin="0" aria-valuemax="100" aria-label="Progreso de la subida">
                    <div class="h-full bg-cap-chrome transition-[width] duration-cap-base ease-cap dark:bg-gray-100" :style="{ width: `${uploader.progress.value}%` }" />
                </div>
            </figure>

            <CapAlert v-if="problem" status="warning" title="No se puede usar esa imagen" :detail="problem" />
            <CapAlert v-else-if="uploadError" status="error" title="No se pudo subir la captura" :detail="uploadError" />
            <p v-else class="text-xs text-cap-text-soft">Captura la ficha del expediente (Expediente, Entidad, fechas, Modalidad…). Leemos el texto y te mostramos cada dato junto a la imagen para que lo revises antes de guardar.</p>
        </div>
        <template #footer>
            <AppButton :disabled="uploader.uploading.value" @click="emit('update:open', false)">Cancelar</AppButton>
            <AppButton variant="primary" icon="text-recognition" :disabled="!file" :loading="uploader.uploading.value" @click="send">Leer captura</AppButton>
        </template>
    </BaseModal>
</template>
