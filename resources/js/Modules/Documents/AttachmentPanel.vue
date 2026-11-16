<script setup>
// Adjuntos de cualquier entidad (cotización, licitación, oportunidad, contrato, cliente).
// Ver README.md de esta carpeta para ejemplos.
// Props: entityType ('quote' | 'tender' | 'opportunity' | 'contract' | 'client'), entityId,
//        canEdit (muestra «Agregar PDF» y «Eliminar»; el servidor vuelve a comprobarlo),
//        accept ('.pdf' por defecto), maxSizeMb (40), categories ([{ value, label }] o textos: pide
//        categoría al subir), title ('Adjuntos'), addLabel ('Agregar PDF'), emptyText, compact.
// Slots: actions ({ item, reload }) — acciones extra por fila (p. ej. «Firmar y sellar»).
// Eventos: view ({ id, name, previewUrl, downloadUrl, mime, fileName, signed }) — si la pantalla
//          lo escucha, ella muestra el visor; si no, el panel abre su propio `DocumentViewerModal`.
//          changed ({ action: 'uploaded' | 'deleted', items }), loaded (items).
// Expone: reload(), openUpload().
import { computed, getCurrentInstance, onMounted, ref, watch } from 'vue';
import CapSkeleton from '@design/vue/CapSkeleton.vue';
import { useToasts } from '@design/vue/composables/useToasts.js';
import AppButton from '@/Components/AppButton.vue';
import BaseModal from '@/Components/BaseModal.vue';
import EmptyState from '@/Components/EmptyState.vue';
import ErrorState from '@/Components/ErrorState.vue';
import FileDropzone from '@/Components/FileDropzone.vue';
import FormField from '@/Components/FormField.vue';
import IconButton from '@/Components/IconButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TextInput from '@/Components/TextInput.vue';
import { useConfirm } from '@/composables/useConfirm.js';
import { useUpload } from '@/composables/useUpload.js';
import { dateTime, fileSize } from '@/lib/format.js';
import { attachmentsUrl, deleteAttachment, fetchAttachments, fileIcon } from '@/Modules/Documents/api.js';
import DocumentViewerModal from '@/Modules/Documents/DocumentViewerModal.vue';

const props = defineProps({
    entityType: { type: String, required: true },
    entityId: { type: [Number, String], required: true },
    canEdit: { type: Boolean, default: false },
    accept: { type: String, default: '.pdf' },
    maxSizeMb: { type: Number, default: 40 },
    categories: { type: Array, default: () => [] },
    title: { type: String, default: 'Adjuntos' },
    addLabel: { type: String, default: 'Agregar PDF' },
    emptyText: { type: String, default: 'Aún no hay archivos adjuntos.' },
    compact: { type: Boolean, default: false },
});

const emit = defineEmits(['view', 'changed', 'loaded']);

const instance = getCurrentInstance();
const confirm = useConfirm();
const toasts = useToasts();
const uploader = useUpload();

const items = ref([]);
const serverCanEdit = ref(null);
const loading = ref(true);
const loadError = ref('');
const uploadOpen = ref(false);
const files = ref([]);
const form = ref({ category: '', description: '' });
const formErrors = ref({});
const viewing = ref(null);
const viewerOpen = ref(false);
const removing = ref(null);

// La pantalla decide si muestra la edición; si el servidor dice que no (p. ej. registro cerrado), manda el servidor.
const editable = computed(() => props.canEdit && serverCanEdit.value !== false);
const categoryOptions = computed(() => props.categories.map((entry) => (typeof entry === 'string' ? { value: entry, label: entry } : entry)));
const hostHandlesView = computed(() => Boolean(instance?.vnode.props?.onView));
const onlyPdf = computed(() => props.accept.trim().toLowerCase() === '.pdf');
const uploadTitle = computed(() => (onlyPdf.value ? 'Agregar PDF' : 'Agregar archivos'));

let request = 0;
async function reload() {
    const current = ++request;
    loadError.value = '';
    loading.value = true;
    try {
        const response = await fetchAttachments(props.entityType, props.entityId);
        if (current !== request) return;
        items.value = response.data ?? [];
        serverCanEdit.value = response.can?.edit ?? null;
        emit('loaded', items.value);
    } catch (error) {
        if (current === request) loadError.value = error.message || 'No se pudieron cargar los adjuntos.';
    } finally {
        if (current === request) loading.value = false;
    }
}

onMounted(reload);
watch(() => [props.entityType, props.entityId], reload);

function openUpload() {
    uploader.reset();
    files.value = [];
    form.value = { category: categoryOptions.value[0]?.value ?? '', description: '' };
    formErrors.value = {};
    uploadOpen.value = true;
}

async function upload() {
    if (!files.value.length) return;
    formErrors.value = {};
    const sent = [...files.value];
    // Un archivo por petición: cada uno con su progreso y su error.
    const ok = await uploader.upload(attachmentsUrl(props.entityType, props.entityId), sent, {
        field: 'file',
        data: { category: form.value.category || '', description: form.value.description || '' },
        only: ['flash'],
        onError: (errors) => {
            formErrors.value = { ...formErrors.value, ...errors };
        },
    });
    const done = sent.filter((file) => uploader.stateOf(file)?.status === 'done');
    files.value = files.value.filter((file) => !done.includes(file));
    if (done.length) {
        await reload();
        emit('changed', { action: 'uploaded', items: items.value });
    }
    if (ok) uploadOpen.value = false;
}

function payload(item) {
    return { id: item.id, name: item.name, previewUrl: item.previewUrl, downloadUrl: item.downloadUrl, mime: item.mime, fileName: item.fileName, signed: item.signed };
}

function view(item) {
    if (hostHandlesView.value) return emit('view', payload(item));
    viewing.value = payload(item);
    viewerOpen.value = true;
}

async function remove(item) {
    const accepted = await confirm({
        verb: 'Eliminar',
        object: `el adjunto «${item.name}»`,
        noun: 'adjunto',
        message: 'Se quitará de este registro y se borrará el archivo con todas sus versiones. No se puede deshacer.',
        action: async () => {
            removing.value = item.id;
            try {
                const response = await deleteAttachment(props.entityType, props.entityId, item.id);
                toasts.show({ status: 'ok', title: response?.message ?? 'Adjunto eliminado.' });
            } catch (error) {
                toasts.show({ status: 'error', title: 'No se pudo eliminar el adjunto', detail: error.message });
                throw error;
            } finally {
                removing.value = null;
            }
        },
    });
    if (!accepted) return;
    if (viewing.value?.id === item.id) viewerOpen.value = false;
    await reload();
    emit('changed', { action: 'deleted', items: items.value });
}

defineExpose({ reload, openUpload, items });
</script>

<template>
    <section class="rounded-cap-card border border-cap-divider bg-cap-card" :class="compact ? 'p-4' : 'p-4 md:p-5'" :aria-busy="loading ? 'true' : undefined">
        <header class="flex flex-wrap items-center justify-between gap-3">
            <h3 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">
                {{ title }}
                <span v-if="!loading && items.length" class="ml-1 text-sm font-normal tabular-nums text-cap-text-soft">({{ items.length }})</span>
            </h3>
            <AppButton v-if="editable && items.length" icon="tray-arrow-up" @click="openUpload">{{ addLabel }}</AppButton>
        </header>

        <div class="mt-4">
            <div v-if="loading && !items.length" class="flex flex-col gap-3" aria-label="Cargando adjuntos">
                <CapSkeleton variant="line" />
                <CapSkeleton variant="line" />
            </div>

            <ErrorState v-else-if="loadError" compact title="No se pudieron cargar los adjuntos" :description="loadError" @retry="reload" />

            <EmptyState v-else-if="!items.length" compact icon="paperclip" title="Sin adjuntos" :description="emptyText">
                <template v-if="editable" #action><AppButton icon="tray-arrow-up" @click="openUpload">{{ addLabel }}</AppButton></template>
            </EmptyState>

            <ul v-stagger v-else class="flex flex-col divide-y divide-cap-divider" :class="loading ? 'opacity-50' : ''">
                <li v-for="item in items" :key="item.id" class="flex flex-wrap items-center gap-x-3 gap-y-2 py-3 first:pt-0 last:pb-0">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-cap-control bg-cap-card-2 text-cap-text-soft" aria-hidden="true">
                        <i class="mdi text-xl leading-none" :class="`mdi-${fileIcon(item.mime, item.fileName)}`" />
                    </span>
                    <div class="min-w-0 flex-1 basis-40">
                        <button type="button" class="block max-w-full truncate text-left text-sm font-medium text-cap-brand hover:underline focus:outline-none focus-visible:underline dark:text-cap-brand-soft" :title="item.fileName || item.name" @click="view(item)">{{ item.name }}</button>
                        <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-cap-text-soft">
                            <span v-if="item.category" class="font-medium text-gray-700 dark:text-gray-300">{{ item.category }}</span>
                            <span>{{ [item.uploadedBy, dateTime(item.uploadedAt, 'medium')].filter(Boolean).join(', ') }}</span>
                            <span class="tabular-nums">{{ fileSize(item.size) }}</span>
                            <StatusBadge v-if="item.signed" status="ok" label="Firmado" :title="item.signedBy ? `Firmado por ${item.signedBy}, ${dateTime(item.signedAt, 'medium')}` : 'Firmado'" />
                        </p>
                        <p v-if="item.description" class="mt-0.5 truncate text-xs text-cap-text-soft" :title="item.description">{{ item.description }}</p>
                    </div>
                    <div class="ml-auto flex shrink-0 items-center gap-1">
                        <slot name="actions" :item="item" :reload="reload" />
                        <IconButton icon="eye-outline" :label="`Ver ${item.name}`" @click="view(item)" />
                        <IconButton icon="download-outline" :label="`Descargar ${item.name}`" :href="item.downloadUrl" external />
                        <IconButton v-if="editable" icon="trash-can-outline" :label="`Eliminar ${item.name}`" :disabled="removing === item.id" @click="remove(item)" />
                    </div>
                </li>
            </ul>
        </div>

        <BaseModal v-model:open="uploadOpen" as="form" size="md" :title="uploadTitle" :persistent="uploader.uploading.value" @submit="upload">
            <div class="flex flex-col gap-4">
                <FileDropzone v-model="files" :uploader="uploader" :accept="accept" :max-size-mb="maxSizeMb" multiple :max-files="20" :title="onlyPdf ? 'Arrastra los PDF aquí o elígelos' : ''" />
                <FormField v-if="categoryOptions.length" label="Categoría" :error="formErrors.category">
                    <SelectInput v-model="form.category" :options="categoryOptions" placeholder="" />
                </FormField>
                <FormField label="Descripción" optional :error="formErrors.description" help="Se aplica a todos los archivos de esta subida.">
                    <TextInput v-model="form.description" maxlength="500" placeholder="Ej.: ficha técnica del proveedor" />
                </FormField>
            </div>
            <template #footer>
                <AppButton :disabled="uploader.uploading.value" @click="uploadOpen = false">Cancelar</AppButton>
                <AppButton variant="primary" type="submit" icon="tray-arrow-up" :loading="uploader.uploading.value" :disabled="!files.length">
                    {{ files.length > 1 ? `Subir ${files.length} archivos` : 'Subir' }}
                </AppButton>
            </template>
        </BaseModal>

        <DocumentViewerModal v-if="!hostHandlesView" v-model:open="viewerOpen" :document="viewing" :can-delete="editable" :deleting="removing === viewing?.id" @delete="remove(items.find((item) => item.id === $event.id) ?? $event)" />
    </section>
</template>
