<script setup>
// Estado vacío y «sin resultados».
// Props: variant ('empty': aún no hay datos, con borde punteado y el botón que crea el primero en el
//        slot `action`; 'no-results': hay filtro o búsqueda, sin borde y con «Limpiar filtros»),
//        title, description, icon (sin `mdi-`), compact (menos alto, para secciones dentro de una tarjeta).
// Eventos: clear (solo en 'no-results').
import { computed } from 'vue';
import AppButton from '@/Components/AppButton.vue';

const props = defineProps({
    variant: { type: String, default: 'empty' },
    title: { type: String, default: '' },
    description: { type: String, default: '' },
    icon: { type: String, default: '' },
    compact: { type: Boolean, default: false },
});

defineEmits(['clear']);

const isNoResults = computed(() => props.variant === 'no-results');
const shownTitle = computed(() => props.title || (isNoResults.value ? 'Ningún registro coincide con los filtros' : 'Aún no hay registros'));
const shownDescription = computed(() => props.description || (isNoResults.value ? 'Prueba con otra búsqueda o quita los filtros.' : ''));
const shownIcon = computed(() => props.icon || (isNoResults.value ? 'magnify-close' : 'inbox-outline'));
</script>

<template>
    <div class="flex flex-col items-center justify-center px-6 text-center" :class="[compact ? 'py-8' : 'py-12', isNoResults ? '' : 'rounded-cap-card border border-dashed border-cap-divider']">
        <i class="m-empty-icon mdi text-cap-icon text-gray-400" :class="`mdi-${shownIcon}`" aria-hidden="true" />
        <p class="mt-3 font-display text-base font-semibold text-gray-900 dark:text-gray-100">{{ shownTitle }}</p>
        <p v-if="shownDescription" class="mt-1 max-w-md text-sm text-cap-text-soft">{{ shownDescription }}</p>
        <div v-if="isNoResults || $slots.action" class="mt-4">
            <slot name="action">
                <AppButton @click="$emit('clear')">Limpiar filtros</AppButton>
            </slot>
        </div>
    </div>
</template>
