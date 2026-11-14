<script setup>
// Tarjeta de una gráfica: título, línea de contexto, acción opcional y estado vacío propio
// (una gráfica sin datos no se dibuja: se explica).
// Props: title, description, empty (no hay datos que dibujar), emptyText.
import EmptyState from '../../../Components/EmptyState.vue';

defineProps({
    title: { type: String, required: true },
    description: { type: String, default: '' },
    empty: { type: Boolean, default: false },
    emptyText: { type: String, default: 'No hay datos en el período elegido.' },
});
</script>

<template>
    <section class="flex min-w-0 flex-col gap-4 rounded-cap-card border border-cap-divider bg-cap-card p-4 sm:p-5">
        <header class="flex flex-wrap items-start justify-between gap-2">
            <div class="min-w-0">
                <h2 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">{{ title }}</h2>
                <p v-if="description" class="mt-0.5 text-xs text-cap-text-soft">{{ description }}</p>
            </div>
            <slot name="actions" />
        </header>
        <EmptyState v-if="empty" compact icon="chart-box-outline" title="Sin datos para graficar" :description="emptyText" />
        <slot v-else />
    </section>
</template>
