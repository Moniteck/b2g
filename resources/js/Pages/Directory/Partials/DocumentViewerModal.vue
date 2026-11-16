<script setup>
// Modal lista para ver un documento: visor (PDF o imagen) y barra con Descargar, acciones extra,
// Eliminar opcional y Cerrar. Ver README.md de esta carpeta.
// Props: open (v-model:open), document ({ id, name, previewUrl, downloadUrl, mime, fileName? }),
//        title (por defecto el nombre), description, canDelete, deleteLabel ('Eliminar'), deleting.
// Slots: actions ({ document }) — botones extra en el pie, p. ej. «Firmar y sellar»;
//        aside ({ document }) — panel lateral en escritorio (lista de adjuntos, datos).
// Eventos: update:open, delete (document), closed.
import AppButton from '@/Components/AppButton.vue';
import BaseModal from '@/Components/BaseModal.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PdfViewer from '@/Components/PdfViewer.vue';
import { canPreview, fileIcon } from '@/Pages/Directory/Partials/api.js';

defineProps({
    open: { type: Boolean, default: false },
    document: { type: Object, default: null },
    title: { type: String, default: '' },
    description: { type: String, default: '' },
    canDelete: { type: Boolean, default: false },
    deleteLabel: { type: String, default: 'Eliminar' },
    deleting: { type: Boolean, default: false },
});

const emit = defineEmits(['update:open', 'delete', 'closed']);
</script>

<template>
    <BaseModal :open="open" size="xl" flush :title="title || document?.name || 'Documento'" :description="description" :persistent="deleting" @update:open="emit('update:open', $event)" @closed="emit('closed')">
        <div v-if="document" class="flex h-full min-h-0 flex-col lg:flex-row">
            <div class="min-w-0 flex-1">
                <PdfViewer v-if="canPreview(document.mime)" :key="document.previewUrl" :src="document.previewUrl" :name="document.fileName || document.name" download-url="" height="h-[calc(100dvh-10rem)] md:h-[70vh]" />
                <EmptyState v-else class="m-4 md:m-6" :icon="fileIcon(document.mime, document.fileName)" title="Este tipo de archivo no tiene vista previa" description="Descárgalo para abrirlo en tu equipo." />
            </div>
            <aside v-if="$slots.aside" class="shrink-0 border-t border-cap-divider lg:w-80 lg:border-l lg:border-t-0">
                <slot name="aside" :document="document" />
            </aside>
        </div>
        <template #footer>
            <div v-if="canDelete && document" class="flex max-md:w-full md:mr-auto [&>.cap-button]:max-md:flex-1">
                <AppButton variant="danger" icon="trash-can-outline" :loading="deleting" @click="emit('delete', document)">{{ deleteLabel }}</AppButton>
            </div>
            <slot name="actions" :document="document" />
            <AppButton v-if="document?.downloadUrl" external :href="document.downloadUrl" icon="download-outline">Descargar</AppButton>
            <AppButton @click="emit('update:open', false)">Cerrar</AppButton>
        </template>
    </BaseModal>
</template>
