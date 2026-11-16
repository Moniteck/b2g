<script setup>
// Esqueleto genérico de una pantalla mientras llega la siguiente (visita de más de 300 ms).
// Cabecera de página, una fila de cifras y una lista; el título real ya está en la cabecera.
import CapSkeleton from '@design/vue/CapSkeleton.vue';

defineProps({
    label: { type: String, default: 'Cargando la pantalla' },
});
</script>

<template>
    <div class="app-page-skeleton flex min-w-0 flex-col gap-6" role="status" :aria-label="label" aria-live="polite">
        <div class="flex items-center justify-between gap-4" aria-hidden="true">
            <span class="cap-skeleton app-page-skeleton__line app-page-skeleton__line--title" />
            <span class="cap-skeleton app-page-skeleton__line app-page-skeleton__line--button max-md:hidden" />
        </div>
        <CapSkeleton variant="stats" :label="label" />
        <CapSkeleton variant="table" :rows="6" :label="label" />
    </div>
</template>
