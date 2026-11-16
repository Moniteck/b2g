<script setup>
// Subir varios documentos al Directorio Institucional, cada uno con su categoría y sus fechas.
// Un archivo por petición: progreso y errores por archivo; los que fallan quedan en la lista.
import { computed, ref, watch } from 'vue';
import { useToasts } from '@design/vue/composables/useToasts.js';
import AppButton from '@/Components/AppButton.vue';
import BaseModal from '@/Components/BaseModal.vue';
import DateInput from '@/Components/DateInput.vue';
import FileDropzone from '@/Components/FileDropzone.vue';
import FormField from '@/Components/FormField.vue';
import IconButton from '@/Components/IconButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import { useUpload } from '@/composables/useUpload.js';
import { fieldError } from '@/lib/errors.js';
import { fileSize } from '@/lib/format.js';
import { fileIcon } from '@/Modules/Documents/api.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    categories: { type: Array, default: () => [] },
    limits: { type: Object, default: () => ({ pdfMb: 40, imageMb: 10, accept: '.pdf', types: 'PDF' }) },
});

const emit = defineEmits(['update:open', 'uploaded']);

const toasts = useToasts();
const uploader = useUpload();
const picked = ref([]);
const entries = ref([]);
let sequence = 0;

const busy = computed(() => uploader.uploading.value);

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        entries.value = [];
        picked.value = [];
        uploader.reset();
    },
);

// La zona solo recibe archivos: cada uno pasa a su propia tarjeta con sus datos.
watch(picked, (files) => {
    if (!files.length) return;
    files.forEach((file) => {
        if (entries.value.some((entry) => entry.file.name === file.name && entry.file.size === file.size)) return;
        entries.value.push({ key: ++sequence, file, category: '', name: '', reference_number: '', issued_at: '', expires_at: '', errors: {} });
    });
    picked.value = [];
});

function stateOf(entry) {
    return uploader.stateOf(entry.file) ?? { status: 'ready', progress: 0, error: '' };
}

function fileError(entry) {
    const state = stateOf(entry);
    return fieldError(entry.errors, 'file') || (state.status === 'error' && !Object.keys(entry.errors).length ? state.error : '');
}

function remove(entry) {
    entries.value = entries.value.filter((item) => item !== entry);
}

async function submit() {
    if (!entries.value.length || busy.value) return;
    let missing = false;
    entries.value.forEach((entry) => {
        entry.errors = entry.category ? {} : { category: 'Elige la categoría del documento.' };
        missing ||= !entry.category;
    });
    if (missing) return;

    const uploaded = [];
    for (const entry of [...entries.value]) {
        const ok = await uploader.upload('/documentos', [entry.file], {
            field: 'file',
            data: { kind: 'empresa', category: entry.category, name: entry.name, reference_number: entry.reference_number, issued_at: entry.issued_at, expires_at: entry.expires_at },
            only: ['documents', 'stats'],
            onError: (errors) => {
                entry.errors = errors;
            },
        });
        if (ok) uploaded.push(entry);
    }

    entries.value = entries.value.filter((entry) => !uploaded.includes(entry));
    if (uploaded.length) {
        toasts.show({ status: 'ok', title: uploaded.length === 1 ? 'Se subió 1 documento.' : `Se subieron ${uploaded.length} documentos.` });
        emit('uploaded', uploaded.length);
    }
    if (!entries.value.length) emit('update:open', false);
}
</script>

<template>
    <BaseModal :open="open" as="form" size="lg" title="Subir documentos" description="Cada archivo lleva su categoría y, si vence, su fecha de vencimiento." :persistent="busy" @update:open="emit('update:open', $event)" @submit="submit">
        <div class="flex flex-col gap-4">
            <FileDropzone v-model="picked" :accept="limits.accept" :max-size-mb="limits.pdfMb" multiple :max-files="20" :disabled="busy" :hint="`Imágenes hasta ${limits.imageMb} MB.`" />

            <ol v-if="entries.length" class="flex flex-col gap-3" aria-label="Documentos por subir">
                <li v-for="entry in entries" :key="entry.key" class="rounded-cap-control bg-cap-card-2 p-3 md:p-4">
                    <div class="flex items-center gap-3">
                        <i class="mdi shrink-0 text-xl leading-none text-cap-text-soft" :class="`mdi-${fileIcon(entry.file.type, entry.file.name)}`" aria-hidden="true" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100" :title="entry.file.name">{{ entry.file.name }}</p>
                            <p v-if="fileError(entry)" class="text-xs text-cap-status-error-ink" role="alert">{{ fileError(entry) }}</p>
                            <p v-else class="text-xs tabular-nums text-cap-text-soft">
                                {{ fileSize(entry.file.size) }}
                                <template v-if="stateOf(entry).status === 'uploading'"> · Subiendo {{ stateOf(entry).progress }} %</template>
                                <template v-else-if="stateOf(entry).status === 'pending'"> · En espera</template>
                                <template v-else-if="stateOf(entry).status === 'error'"> · Revisa los datos marcados</template>
                            </p>
                        </div>
                        <IconButton v-if="!busy" icon="close" :label="`Quitar ${entry.file.name}`" @click="remove(entry)" />
                    </div>
                    <div v-if="stateOf(entry).status === 'uploading'" class="mt-2 h-1 overflow-hidden rounded-full bg-cap-divider" role="progressbar" :aria-label="`Subiendo ${entry.file.name}`" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="stateOf(entry).progress">
                        <div class="h-full rounded-full bg-cap-brand transition-[width] duration-cap-slow ease-cap dark:bg-cap-brand-soft" :style="{ width: `${stateOf(entry).progress}%` }" />
                    </div>
                    <div class="mt-3 grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
                        <FormField label="Categoría" required :error="fieldError(entry.errors, 'category')">
                            <SelectInput v-model="entry.category" :options="categories" placeholder="Elige la categoría" :disabled="busy" />
                        </FormField>
                        <FormField label="Nombre" optional :error="fieldError(entry.errors, 'name')" help="Si lo dejas vacío, se usa el nombre del archivo.">
                            <TextInput v-model="entry.name" maxlength="255" :disabled="busy" />
                        </FormField>
                        <FormField label="Número o referencia" optional :error="fieldError(entry.errors, 'reference_number')">
                            <TextInput v-model="entry.reference_number" maxlength="100" :disabled="busy" />
                        </FormField>
                        <div class="grid grid-cols-2 gap-x-4">
                            <FormField label="Emisión" optional :error="fieldError(entry.errors, 'issued_at')">
                                <DateInput v-model="entry.issued_at" :disabled="busy" />
                            </FormField>
                            <FormField label="Vencimiento" optional :error="fieldError(entry.errors, 'expires_at')">
                                <DateInput v-model="entry.expires_at" :min="entry.issued_at || undefined" :disabled="busy" />
                            </FormField>
                        </div>
                    </div>
                </li>
            </ol>
        </div>
        <template #footer>
            <AppButton :disabled="busy" @click="emit('update:open', false)">Cancelar</AppButton>
            <AppButton variant="primary" type="submit" icon="tray-arrow-up" :loading="busy" :disabled="!entries.length">
                {{ entries.length > 1 ? `Subir ${entries.length} documentos` : 'Subir documento' }}
            </AppButton>
        </template>
    </BaseModal>
</template>
