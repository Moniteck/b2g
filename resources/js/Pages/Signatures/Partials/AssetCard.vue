<script setup>
// Tarjeta de mi firma o mi sello: vista previa sobre papel y sobre un documento, crear (dibujar,
// escribir, generar el sello o subir una imagen, en `AssetCreatorModal`), cambiar y quitar.
// Props: type ('firma' | 'sello'), asset (del servidor: { url, name, width, height, size, updated_at } o null),
//        maxSizeMb, compact (dentro de la modal de firma: sin metadatos y vista previa más baja),
//        company ({ name, tax_id } de la empresa activa, para generar el sello).
// Eventos: saved (tras crear o reemplazar), removed.
// La subida va por Inertia (`useUpload`) y recarga solo la prop `assets`: la página que la contiene
// (y una modal abierta encima) conserva su estado.
import { computed, ref } from 'vue';
import AppButton from '@/Components/AppButton.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { useConfirm } from '@/composables/useConfirm.js';
import { visit } from '@/lib/http.js';
import { dateTime, fileSize } from '@/lib/format.js';
import { card } from '@/lib/ui.js';
import { endpoints } from '@/Pages/Signatures/Partials/api.js';
import AssetCreatorModal from '@/Pages/Signatures/Partials/AssetCreatorModal.vue';

const props = defineProps({
    type: { type: String, required: true },
    asset: { type: Object, default: null },
    maxSizeMb: { type: Number, default: 2 },
    compact: { type: Boolean, default: false },
    company: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['saved', 'removed']);

const LABELS = {
    firma: { title: 'Firma', icon: 'draw-pen', article: 'tu firma', noun: 'firma', create: 'Crear firma', createIcon: 'draw', firstTab: 'draw', empty: 'Dibújala con el dedo o el ratón, escríbela con un estilo manuscrito o sube una foto.' },
    sello: { title: 'Sello', icon: 'stamper', article: 'tu sello', noun: 'sello', create: 'Crear sello', createIcon: 'stamper', firstTab: 'stamp', empty: 'Genera un sello con el nombre y el RTN de la empresa o sube la imagen del tuyo.' },
};

const confirm = useConfirm();
const removing = ref(false);
const creatorOpen = ref(false);
const creatorTab = ref('');
const meta = computed(() => LABELS[props.type]);

function openCreator(tab) {
    creatorTab.value = tab;
    creatorOpen.value = true;
}

function remove() {
    confirm({
        verb: 'Quitar',
        object: meta.value.article,
        noun: meta.value.noun,
        icon: 'trash-can-outline',
        message: 'Ya no podrás usarla para firmar hasta que crees o subas otra. Los documentos que ya firmaste no cambian.',
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

        <!-- Siempre sobre fondos claros: la tinta azul o roja no se lee sobre oscuro (B-26). -->
        <div v-if="asset" class="grid grid-cols-2 overflow-hidden rounded-cap-control border border-cap-divider" :class="compact ? 'h-24' : 'h-40'">
            <div class="flex flex-col items-center justify-end gap-1 bg-white px-3 pb-2 pt-3 dark:bg-white">
                <img :src="asset.url" :alt="`${meta.title} sobre papel`" class="relative min-h-0 w-full flex-1 object-contain" :class="type === 'firma' ? '-mb-2' : ''" draggable="false">
                <span class="w-4/5 border-t border-gray-400 dark:border-gray-400" aria-hidden="true" />
                <span class="text-xs text-gray-600 dark:text-gray-600">Sobre papel</span>
            </div>
            <div class="flex flex-col gap-1 border-l border-gray-200 bg-gray-50 px-3 pb-2 pt-3 dark:border-gray-200 dark:bg-gray-50">
                <div class="relative min-h-0 flex-1">
                    <div class="absolute inset-0 flex flex-col justify-center gap-1.5 px-1" aria-hidden="true">
                        <span v-for="n in (compact ? 3 : 5)" :key="n" class="h-1.5 rounded-full bg-gray-200 dark:bg-gray-200" :class="n % 3 === 0 ? 'w-2/3' : 'w-full'" />
                    </div>
                    <img :src="asset.url" :alt="`${meta.title} sobre un documento`" class="relative h-full w-full object-contain" draggable="false">
                </div>
                <span class="text-center text-xs text-gray-600 dark:text-gray-600">Sobre un documento</span>
            </div>
        </div>

        <div v-else class="flex flex-col items-center gap-2 rounded-cap-control border border-dashed border-cap-input-line px-4 text-center" :class="compact ? 'py-3' : 'py-6'">
            <span class="flex h-11 w-11 items-center justify-center rounded-full bg-cap-card-2 text-cap-text-soft" aria-hidden="true">
                <i class="mdi text-2xl leading-none" :class="`mdi-${meta.createIcon}`" />
            </span>
            <p class="max-w-sm text-sm text-cap-text-soft">{{ meta.empty }}</p>
        </div>

        <p v-if="asset && !compact" class="truncate text-xs text-cap-text-soft" :title="asset.name">
            {{ asset.name }} · {{ asset.width }} × {{ asset.height }} px · {{ fileSize(asset.size) }} · {{ dateTime(asset.updated_at) }}
        </p>

        <div class="flex flex-wrap gap-2">
            <template v-if="!asset">
                <AppButton :icon="meta.createIcon" data-create-asset @click="openCreator(meta.firstTab)">{{ meta.create }}</AppButton>
                <AppButton icon="upload-outline" @click="openCreator('upload')">Subir imagen</AppButton>
            </template>
            <template v-else>
                <AppButton icon="pencil-outline" data-create-asset @click="openCreator(meta.firstTab)">Cambiar</AppButton>
                <AppButton variant="danger" icon="trash-can-outline" :loading="removing" @click="remove">Quitar</AppButton>
            </template>
        </div>

        <AssetCreatorModal v-model:open="creatorOpen" :type="type" :tab="creatorTab" :company="company" :max-size-mb="maxSizeMb" @saved="(saved) => emit('saved', saved)" />
    </section>
</template>
