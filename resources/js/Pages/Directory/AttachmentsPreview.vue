<script setup>
// Vista de prueba del panel de adjuntos: la abre `tests/visual/documentos.mjs`
// en la ruta local `/directorio/adjuntos-prueba/{tipo}/{id}` para validar
// `AttachmentPanel` y `DocumentViewerModal` mientras ninguna pantalla los usa todavía.
import AppLayout from '@/Layouts/AppLayout.vue';
import AttachmentPanel from '@/Pages/Directory/Partials/AttachmentPanel.vue';

defineProps({
    entityType: { type: String, default: 'quote' },
    entityId: { type: [Number, String], required: true },
    canEdit: { type: Boolean, default: false },
});
</script>

<template>
    <AppLayout title="Adjuntos (vista de prueba)">
        <div class="max-w-3xl">
            <AttachmentPanel :entity-type="entityType" :entity-id="entityId" :can-edit="canEdit" :categories="['Ficha técnica', 'Catálogo', 'Otro']" />
        </div>
    </AppLayout>
</template>
