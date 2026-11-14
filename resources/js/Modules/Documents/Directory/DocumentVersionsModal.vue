<script setup>
// Historial de versiones de un documento: ver y descargar cada una.
// Eventos: view ({ id, name, previewUrl, downloadUrl, mime, fileName }) para abrir el visor encima.
import { ref, watch } from 'vue';
import CapSkeleton from '../../../../design/vue/CapSkeleton.vue';
import AppButton from '../../../Components/AppButton.vue';
import BaseModal from '../../../Components/BaseModal.vue';
import ErrorState from '../../../Components/ErrorState.vue';
import IconButton from '../../../Components/IconButton.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import { date, dateTime, fileSize } from '../../../lib/format.js';
import { fetchVersions, fileIcon } from '../api.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    document: { type: Object, default: null },
});

const emit = defineEmits(['update:open', 'view']);

const versions = ref([]);
const loading = ref(false);
const loadError = ref('');

async function load() {
    if (!props.document) return;
    loading.value = true;
    loadError.value = '';
    try {
        versions.value = (await fetchVersions(props.document.id)).data ?? [];
    } catch (error) {
        loadError.value = error.message;
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        versions.value = [];
        load();
    },
);

function view(version) {
    emit('view', {
        id: props.document.id,
        name: `${props.document.name} · versión ${version.version}`,
        fileName: version.fileName,
        previewUrl: version.previewUrl,
        downloadUrl: version.downloadUrl,
        mime: version.mime,
    });
}

function meta(version) {
    return [version.uploadedBy, dateTime(version.uploadedAt, 'medium')].filter(Boolean).join(', ');
}
</script>

<template>
    <BaseModal :open="open" size="lg" title="Historial de versiones" :description="document?.name" @update:open="emit('update:open', $event)">
        <div v-if="loading && !versions.length" class="flex flex-col gap-3" aria-label="Cargando versiones">
            <CapSkeleton variant="line" />
            <CapSkeleton variant="line" />
            <CapSkeleton variant="line" />
        </div>
        <ErrorState v-else-if="loadError" compact title="No se pudo cargar el historial" :description="loadError" @retry="load" />
        <ol v-stagger v-else class="flex flex-col divide-y divide-cap-divider" aria-label="Versiones, la más reciente primero">
            <li v-for="version in versions" :key="version.id" class="flex flex-wrap items-center gap-x-3 gap-y-2 py-3 first:pt-0 last:pb-0">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-cap-control bg-cap-card-2 font-display text-sm font-semibold tabular-nums text-gray-900 dark:text-gray-100" :aria-label="`Versión ${version.version}`">v{{ version.version }}</span>
                <div class="min-w-0 flex-1 basis-48">
                    <p class="flex min-w-0 items-center gap-2">
                        <i class="mdi shrink-0 text-base leading-none text-cap-text-soft" :class="`mdi-${fileIcon(version.mime, version.fileName)}`" aria-hidden="true" />
                        <span class="truncate text-sm font-medium text-gray-900 dark:text-gray-100" :title="version.fileName">{{ version.fileName }}</span>
                    </p>
                    <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-cap-text-soft">
                        <span>{{ meta(version) }}</span>
                        <span class="tabular-nums">{{ fileSize(version.size) }}</span>
                        <span v-if="version.expiresAt">Vence {{ date(version.expiresAt) }}</span>
                        <StatusBadge v-if="version.current" status="info" label="Versión actual" />
                        <StatusBadge v-if="version.signed" status="ok" label="Firmado" />
                        <StatusBadge v-if="version.note && !version.signed" status="neutral" :label="version.note" />
                    </p>
                </div>
                <div class="ml-auto flex shrink-0 items-center gap-1">
                    <IconButton icon="eye-outline" :label="`Ver la versión ${version.version}`" @click="view(version)" />
                    <IconButton icon="download-outline" :label="`Descargar la versión ${version.version}`" :href="version.downloadUrl" external />
                </div>
            </li>
        </ol>
        <template #footer>
            <AppButton @click="emit('update:open', false)">Cerrar</AppButton>
        </template>
    </BaseModal>
</template>
