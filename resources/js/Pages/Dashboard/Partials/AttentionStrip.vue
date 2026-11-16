<script setup>
// Franja «Requiere atención hoy»: lo que vence hoy o mañana, cada ítem con su acción.
// Sin ítems muestra una línea tranquila «Nada urgente hoy». En móvil enseña 3 y el resto con «Ver todo».
// Props: items ([{ id, tone: 'error'|'warning'|'info', icon, title, detail, due_at, all_day, action: { label, href } }]).
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { dateTime, relativeDays, time } from '@/lib/format.js';
import { todayKey } from '@/Pages/Dashboard/Partials/dashboard.js';

const props = defineProps({
    items: { type: Array, default: () => [] },
});

const expanded = ref(false);
const MOBILE_LIMIT = 3;
const urgent = computed(() => props.items.filter((item) => item.tone === 'error').length);

const TONES = {
    error: { icon: 'bg-cap-status-error-tint text-cap-status-error-ink', bar: 'bg-cap-status-error dark:bg-cap-status-dark-error' },
    warning: { icon: 'bg-cap-status-warning-tint text-cap-status-warning-ink', bar: 'bg-cap-status-warning dark:bg-cap-status-dark-warning' },
    info: { icon: 'bg-cap-status-info-tint text-cap-status-info-ink', bar: 'bg-cap-status-info dark:bg-cap-status-dark-info' },
};

function when(item) {
    if (!item.due_at) return '';
    const isToday = String(item.due_at).slice(0, 10) === todayKey();
    if (item.all_day) return relativeDays(item.due_at);
    return isToday ? `Hoy, ${time(item.due_at)}` : `${relativeDays(item.due_at)}, ${time(item.due_at)}`;
}
</script>

<template>
    <section v-if="items.length" class="overflow-hidden rounded-cap-card border border-cap-divider bg-cap-card" aria-labelledby="dashboard-attention">
        <header class="flex items-center justify-between gap-3 border-b border-cap-divider px-4 py-3 sm:px-5">
            <h2 id="dashboard-attention" class="flex items-center gap-2 font-display text-base font-semibold text-gray-900 dark:text-gray-100">
                <span class="flex h-2 w-2" aria-hidden="true">
                    <span class="inline-flex h-2 w-2 rounded-full bg-cap-status-error dark:bg-cap-status-dark-error" />
                </span>
                Requiere atención hoy
            </h2>
            <span class="hidden text-xs text-cap-text-soft sm:inline">{{ items.length }} {{ items.length === 1 ? 'pendiente' : 'pendientes' }}<template v-if="urgent"> · {{ urgent }} {{ urgent === 1 ? 'urgente' : 'urgentes' }}</template></span>
        </header>
        <ul v-stagger class="grid grid-cols-1 divide-y divide-cap-divider lg:grid-cols-2 lg:divide-y-0">
            <li v-for="(item, index) in items" :key="item.id" class="relative lg:border-b lg:border-cap-divider lg:odd:border-r" :class="{ 'hidden lg:block': !expanded && index >= MOBILE_LIMIT }">
                <span class="absolute inset-y-3 left-0 w-1 rounded-r-full" :class="(TONES[item.tone] ?? TONES.warning).bar" aria-hidden="true" />
                <div class="flex items-center gap-3 py-3 pl-4 pr-3 sm:pl-5 sm:pr-4">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full" :class="(TONES[item.tone] ?? TONES.warning).icon" aria-hidden="true">
                        <i class="mdi text-xl leading-none" :class="`mdi-${item.icon}`" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-gray-900 dark:text-gray-100" :title="item.title">{{ item.title }}</p>
                        <p class="truncate text-xs text-cap-text-soft" :title="item.detail || undefined">
                            <span v-if="item.due_at" class="font-medium" :class="item.tone === 'error' ? 'text-cap-status-error-ink' : 'text-gray-900 dark:text-gray-100'" :title="dateTime(item.due_at)">{{ when(item) }}</span>
                            <template v-if="item.due_at && item.detail"> · </template>{{ item.detail }}
                        </p>
                    </div>
                    <Link :href="item.action.href" class="inline-flex min-h-10 shrink-0 items-center gap-1 rounded-full border border-cap-input-line px-3 text-xs font-medium text-gray-900 transition-colors duration-cap-fast ease-cap hover:bg-cap-card-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome focus-visible:ring-offset-2 focus-visible:ring-offset-cap-card dark:text-gray-100 dark:focus-visible:ring-gray-100 sm:min-h-9">
                        <span class="hidden sm:inline">{{ item.action.label }}</span>
                        <span class="sr-only sm:hidden">{{ item.action.label }}: {{ item.title }}</span>
                        <i class="mdi mdi-arrow-right text-base leading-none" aria-hidden="true" />
                    </Link>
                </div>
            </li>
        </ul>
        <button v-if="items.length > MOBILE_LIMIT" type="button" class="flex min-h-10 w-full items-center justify-center gap-1 border-t border-cap-divider text-sm text-gray-900 transition-colors duration-cap-fast ease-cap hover:bg-cap-card-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-cap-chrome dark:text-gray-100 dark:focus-visible:ring-gray-100 lg:hidden" :aria-expanded="expanded ? 'true' : 'false'" @click="expanded = !expanded">
            {{ expanded ? 'Ver menos' : `Ver los ${items.length - MOBILE_LIMIT} restantes` }}
            <i class="mdi text-xl leading-none" :class="expanded ? 'mdi-chevron-up' : 'mdi-chevron-down'" aria-hidden="true" />
        </button>
    </section>
    <p v-else class="flex items-center gap-2 rounded-cap-card border border-cap-divider bg-cap-card px-4 py-3 text-sm text-gray-900 dark:text-gray-100 sm:px-5">
        <i class="mdi mdi-check-circle text-xl leading-none text-cap-status-ok-ink" aria-hidden="true" />
        <span><strong class="font-semibold">Nada urgente hoy.</strong> <span class="text-cap-text-soft">Ningún plazo, documento o cobro vence hoy ni mañana.</span></span>
    </p>
</template>
