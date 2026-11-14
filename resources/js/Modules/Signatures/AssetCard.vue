<script setup>
// Tarjeta de mi firma o mi sello: vista previa sobre fondo claro y oscuro, subir, reemplazar y quitar.
// Props: type ('firma' | 'sello'), asset (del servidor: { url, name, width, height, size, updated_at } o null),
//        maxSizeMb, compact (dentro de la modal de firma: sin metadatos y vista previa más baja).
// Eventos: saved (tras subir o reemplazar), removed.
// La subida va por Inertia (`useUpload`) y recarga solo la prop `assets`: la página que la contiene
// (y una modal abierta encima) conserva su estado.
import { computed, ref, watch } from 'vue';
import AppButton from '../../Components/AppButton.vue';
import FileDropzone from '../../Components/FileDropzone.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import { useConfirm } from '../../composables/useConfirm.js';
import { useUpload } from '../../composables/useUpload.js';
import { visit } from '../../lib/http.js';
import { dateTime, fileSize } from '../../lib/format.js';
import { card } from '../../lib/ui.js';
import { endpoints } from './api.js';

const props = defineProps({
    type: { type: String, required: true },
    asset: { type: Object, default: null },
    maxSizeMb: { type: Number, default: 2 },
    compact: { type: Boolean, default: false },
});

const emit = defineEmits(['saved', 'removed']);

const LABELS = {
    firma: { title: 'Firma', icon: 'draw-pen', article: 'tu firma', noun: 'firma' },
    sello: { title: 'Sello', icon: 'stamper', article: 'tu sello', noun: 'sello' },
};

const confirm = useConfirm();
const uploader = useUpload();
const files = ref([]);
const replacing = ref(false);
const removing = ref(false);
const meta = computed(() => LABELS[props.type]);
const showDropzone = computed(() => !props.asset || replacing.value);

watch(files, async (selected) => {
    if (!selected.length) return;
    const done = await uploader.upload(endpoints.assets, selected, { field: 'file', data: { type: props.type }, only: ['assets', 'flash'] });
    if (done) {
        files.value = [];
        uploader.reset();
        replacing.value = false;
        emit('saved', props.type);
    }
});

function remove() {
    confirm({
        verb: 'Quitar',
        object: meta.value.article,
        noun: meta.value.noun,
        icon: 'trash-can-outline',
        message: 'Ya no podrás usarla para firmar hasta que subas otra. Los documentos que ya firmaste no cambian.',
        action: async () => {
            removing.value = true;
            try {
                await visit('delete', endpoints.asset(props.type), {}, { only: ['assets', 'flash'] });
                emit('removed', props.type);
            } finally {
                removing.value = false;
            }
        },
    });
}
</script>

<template>
    <section :class="[card, 'flex min-w-0 flex-col gap-4 p-4 md:p-5']" :aria-labelledby="`asset-${type}`">
        <header class="flex items-center justify-between gap-3">
            <h3 :id="`asset-${type}`" class="flex items-center gap-2 font-display text-base font-semibold text-gray-900 dark:text-gray-100">
                <i class="mdi text-xl leading-none text-cap-text-soft" :class="`mdi-${meta.icon}`" aria-hidden="true" />
                {{ meta.title }}
            </h3>
            <StatusBadge :status="asset ? 'ok' : 'neutral'" :label="asset ? 'Lista para firmar' : 'Sin cargar'" />
        </header>

        <div v-if="asset" class="grid grid-cols-2 grid-rows-1 overflow-hidden rounded-cap-control border border-cap-divider" :class="compact ? 'h-24' : 'h-36'">
            <div class="flex flex-col items-center justify-center gap-1 bg-white p-3 dark:bg-white">
                <img :src="asset.url" :alt="`${meta.title} sobre fondo claro`" class="min-h-0 w-full flex-1 object-contain" draggable="false">
                <span class="text-xs text-gray-600 dark:text-gray-600">Fondo claro</span>
            </div>
            <div class="flex flex-col items-center justify-center gap-1 bg-gray-900 p-3 dark:bg-gray-900">
                <img :src="asset.url" :alt="`${meta.title} sobre fondo oscuro`" class="min-h-0 w-full flex-1 object-contain" draggable="false">
                <span class="text-xs text-gray-300 dark:text-gray-300">Fondo oscuro</span>
            </div>
        </div>

        <p v-if="asset && !compact" class="truncate text-xs text-cap-text-soft" :title="asset.name">
            {{ asset.name }} · {{ asset.width }} × {{ asset.height }} px · {{ fileSize(asset.size) }} · {{ dateTime(asset.updated_at) }}
        </p>

        <FileDropzone
            v-if="showDropzone"
            v-model="files"
            :uploader="uploader"
            accept=".png,.jpg,.jpeg"
            :max-size-mb="maxSizeMb"
            :title="asset ? `Arrastra la nueva imagen de ${meta.article}` : `Sube ${meta.article}`"
            hint="Mejor en PNG con fondo transparente, recortada al borde."
        />

        <div v-if="asset" class="flex flex-wrap gap-2">
            <AppButton v-if="!replacing" icon="swap-horizontal" :disabled="uploader.uploading.value" @click="replacing = true">Reemplazar</AppButton>
            <AppButton v-else icon="close" :disabled="uploader.uploading.value" @click="replacing = false; files = []">Cancelar</AppButton>
            <AppButton variant="danger" icon="trash-can-outline" :loading="removing" :disabled="uploader.uploading.value" @click="remove">Quitar</AppButton>
        </div>
    </section>
</template>
