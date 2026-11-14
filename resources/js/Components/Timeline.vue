<script setup>
// Historial de un registro, lo más reciente arriba. Va dentro de una tarjeta.
// Props: items ([{ id?, title, meta?, description?, icon?, status? }]; `meta` es «quién, cuándo» ya
//        formateado; `status` tiñe el círculo: 'ok' | 'error' | 'warning' | 'info'),
//        emptyText. Slot `item` ({ item }) para el cuerpo de cada entrada.
defineProps({
    items: { type: Array, default: () => [] },
    emptyText: { type: String, default: 'Aún no hay movimientos.' },
});

const TONES = {
    ok: 'bg-cap-status-ok-tint text-cap-status-ok-ink',
    error: 'bg-cap-status-error-tint text-cap-status-error-ink',
    warning: 'bg-cap-status-warning-tint text-cap-status-warning-ink',
    info: 'bg-cap-status-info-tint text-cap-status-info-ink',
};
</script>

<template>
    <p v-if="items.length === 0" class="text-sm text-cap-text-soft">{{ emptyText }}</p>
    <ol v-else v-stagger class="space-y-4">
        <li v-for="(item, index) in items" :key="item.id ?? index" class="flex gap-3">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full" :class="TONES[item.status] ?? 'bg-cap-card-2 text-cap-text-soft'" aria-hidden="true">
                <i class="mdi text-base leading-none" :class="`mdi-${item.icon ?? 'circle-small'}`" />
            </span>
            <div class="min-w-0 flex-1">
                <slot name="item" :item="item">
                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ item.title }}</p>
                    <p v-if="item.description" class="mt-0.5 text-sm text-gray-700 dark:text-gray-300">{{ item.description }}</p>
                    <p v-if="item.meta" class="text-xs text-cap-text-soft">{{ item.meta }}</p>
                </slot>
            </div>
        </li>
    </ol>
</template>
