<script setup>
// Anfitrión de las confirmaciones de `useConfirm()`, sobre `CapConfirmDialog`. Va una sola vez por
// aplicación y ya está montado en `AppLayout`: las páginas no lo incluyen, solo llaman a `useConfirm()`.
// Sin props. Foco inicial en «Cancelar»; Esc y × cancelan; clic fuera no cierra.
import { watch } from 'vue';
import CapConfirmDialog from '../../design/vue/CapConfirmDialog.vue';
import { confirmHost } from '../composables/useConfirm.js';
import { useLayer } from '../composables/useLayers.js';

const { state } = confirmHost;
const layer = useLayer();

watch(
    () => state.open,
    (open) => (open ? layer.push() : layer.pop()),
    { immediate: true },
);
</script>

<template>
    <!-- Se monta al abrir para quedar encima de cualquier modal que ya esté abierta. -->
    <Teleport v-if="state.open" to="body">
        <CapConfirmDialog
            open
            :title="state.options.title"
            :message="state.options.message"
            :confirm-label="state.options.confirmLabel"
            :icon="state.options.icon"
            :acknowledge-label="state.options.acknowledgeLabel"
            :loading="state.loading"
            @confirm="confirmHost.accept"
            @cancel="confirmHost.cancel"
        />
    </Teleport>
</template>
