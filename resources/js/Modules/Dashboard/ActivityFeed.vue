<script setup>
// Actividad reciente del equipo (de la auditoría, solo registros del negocio): quién hizo qué y cuándo.
// Props: items ([{ id, actor, phrase, subject, icon, at, href }]).
import { Link } from '@inertiajs/vue3';
import Avatar from '@/Components/Avatar.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { dateTime, relativeTime } from '@/lib/format.js';

defineProps({
    items: { type: Array, default: () => [] },
});
</script>

<template>
    <EmptyState v-if="!items.length" compact icon="history" title="Aún no hay actividad" description="Los cambios del equipo en licitaciones, cotizaciones y contratos aparecerán aquí." />
    <ol v-else v-stagger class="flex flex-col">
        <li v-for="(item, index) in items" :key="item.id" class="relative flex gap-3 pb-4 last:pb-0">
            <span v-if="index < items.length - 1" class="absolute bottom-0 left-4 top-9 w-px bg-cap-divider" aria-hidden="true" />
            <Avatar :name="item.actor" size="sm" class="shrink-0" />
            <div class="min-w-0 flex-1 pt-0.5">
                <p class="text-sm text-gray-900 dark:text-gray-100">
                    <span class="font-semibold">{{ item.actor }}</span>
                    {{ item.phrase }}
                    <component :is="item.href ? Link : 'span'" v-if="item.subject" :href="item.href || undefined" class="font-medium [overflow-wrap:anywhere]" :class="item.href ? 'underline decoration-gray-400 underline-offset-2 hover:decoration-current' : ''">{{ item.subject }}</component>
                </p>
                <p class="mt-0.5 flex items-center gap-1 text-xs text-cap-text-soft" :title="dateTime(item.at)">
                    <i class="mdi text-base leading-none" :class="`mdi-${item.icon}`" aria-hidden="true" />{{ relativeTime(item.at) }}
                </p>
            </div>
        </li>
    </ol>
</template>
