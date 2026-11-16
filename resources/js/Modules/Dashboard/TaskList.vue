<script setup>
// Mis tareas: recordatorios pendientes de los que el usuario es responsable (atrasados de la última
// semana y los de los próximos 14 días). Cada uno abre su registro.
// Props: items ([{ id, title, type, at, all_day, overdue, href }]).
import { Link } from '@inertiajs/vue3';
import EmptyState from '../../Components/EmptyState.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import { dateTime, relativeDays, time } from '../../lib/format.js';

defineProps({
    items: { type: Array, default: () => [] },
});

function label(item) {
    const day = relativeDays(item.at);
    const text = day.charAt(0).toUpperCase() + day.slice(1);
    return item.all_day ? text : `${text}, ${time(item.at)}`;
}
</script>

<template>
    <EmptyState v-if="!items.length" compact icon="checkbox-marked-circle-outline" title="No tienes tareas pendientes" description="Los plazos que te asignen como responsable aparecerán aquí." />
    <ul v-else v-stagger class="flex flex-col gap-2">
        <li v-for="item in items" :key="item.id">
            <Link :href="item.href" class="flex min-h-14 items-center gap-3 rounded-cap-control border px-3 py-2 transition-colors duration-cap-fast ease-cap hover:bg-cap-card-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome dark:focus-visible:ring-gray-100" :class="item.overdue ? 'border-cap-status-error-tint-border' : 'border-cap-divider'">
                <i class="mdi text-xl leading-none" :class="item.overdue ? 'mdi-alert-circle-outline text-cap-status-error-ink' : 'mdi-checkbox-blank-circle-outline text-cap-text-soft'" aria-hidden="true" />
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-semibold text-gray-900 dark:text-gray-100" :title="item.title">{{ item.title }}</span>
                    <span class="block truncate text-xs text-cap-text-soft">{{ item.type }}</span>
                </span>
                <StatusBadge :status="item.overdue ? 'error' : 'neutral'" :label="item.overdue ? 'Atrasada' : label(item)" :title="dateTime(item.at)" />
            </Link>
        </li>
    </ul>
</template>
