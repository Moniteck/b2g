<script setup>
// Pipeline por etapa: una barra apilada con el monto de las etapas activas y, debajo, cada etapa con su
// cantidad, monto y barra proporcional. Cada etapa lleva al kanban (`/pipeline?etapa=…`).
// Props: stages ([{ stage, label, count, amount, weighted, active, href }]), palette, openTenders.
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import { money, number } from '@/lib/format.js';
import { compactMoney } from '@/Modules/Dashboard/dashboard.js';

const props = defineProps({
    stages: { type: Array, default: () => [] },
    palette: { type: Object, required: true },
    openTenders: { type: Number, default: 0 },
});

const active = computed(() => props.stages.filter((stage) => stage.active));
const activeTotal = computed(() => active.value.reduce((sum, stage) => sum + (Number(stage.amount) || 0), 0));
const weightedTotal = computed(() => active.value.reduce((sum, stage) => sum + (Number(stage.weighted) || 0), 0));
const maxCount = computed(() => Math.max(1, ...props.stages.map((stage) => Number(stage.count) || 0)));
const empty = computed(() => props.stages.every((stage) => !Number(stage.count)));

// Las tres etapas activas con las tres primeras series; resultados con su color de estado.
function color(stage) {
    if (stage.stage === 'adjudicado') return props.palette.status.ok;
    if (stage.stage === 'no_adjudicado') return props.palette.status.error;
    const index = active.value.findIndex((item) => item.stage === stage.stage);
    return props.palette.series[[0, 2, 3][index] ?? 2];
}

const share = (stage) => (activeTotal.value > 0 ? ((Number(stage.amount) || 0) / activeTotal.value) * 100 : 0);
</script>

<template>
    <EmptyState v-if="empty" compact icon="view-column-outline" title="Sin oportunidades en el período" description="Las oportunidades que crees en el kanban aparecerán aquí por etapa." />
    <div v-else class="flex flex-col gap-5">
        <div class="grid grid-cols-3 gap-3">
            <div class="min-w-0">
                <p class="text-xs text-cap-text-soft">En juego</p>
                <p class="truncate font-display text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100" :title="money(activeTotal)">{{ compactMoney(activeTotal) }}</p>
            </div>
            <div class="min-w-0">
                <p class="text-xs text-cap-text-soft">Ponderado</p>
                <p class="truncate font-display text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100" :title="money(weightedTotal)">{{ compactMoney(weightedTotal) }}</p>
            </div>
            <Link href="/licitaciones?window=open" class="min-w-0 rounded-cap-control transition-colors duration-cap-fast ease-cap hover:bg-cap-card-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome dark:focus-visible:ring-gray-100">
                <p class="text-xs text-cap-text-soft">Procesos vigentes</p>
                <p class="font-display text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ number(openTenders) }}</p>
            </Link>
        </div>

        <div v-if="activeTotal > 0" class="flex h-3 w-full overflow-hidden rounded-full bg-cap-card-2" role="img" :aria-label="`Monto activo por etapa: ${active.map((stage) => `${stage.label} ${money(stage.amount)}`).join(', ')}`">
            <span v-for="stage in active" :key="stage.stage" class="m-fill h-full first:rounded-l-full last:rounded-r-full" :style="{ width: `${share(stage)}%`, backgroundColor: color(stage) }" />
        </div>

        <ol v-stagger class="flex flex-col gap-1">
            <li v-for="stage in stages" :key="stage.stage">
                <Link :href="stage.href" class="group grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 rounded-cap-control px-2 py-2 transition-colors duration-cap-fast ease-cap hover:bg-cap-card-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome dark:focus-visible:ring-gray-100 sm:grid-cols-[10rem_minmax(0,1fr)_7rem]">
                    <span class="flex min-w-0 items-center gap-2 text-sm text-gray-900 dark:text-gray-100">
                        <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: color(stage) }" aria-hidden="true" />
                        <span class="truncate">{{ stage.label }}</span>
                    </span>
                    <span class="col-span-2 row-start-2 h-2 min-w-0 rounded-full bg-cap-card-2 sm:col-span-1 sm:row-start-auto">
                        <span class="m-fill block h-full rounded-full transition-[width] duration-cap-slow ease-cap" :style="{ width: `${Math.max(stage.count ? 4 : 0, ((Number(stage.count) || 0) / maxCount) * 100)}%`, backgroundColor: color(stage) }" />
                    </span>
                    <span class="flex items-center justify-end gap-1 text-right text-xs tabular-nums text-cap-text-soft">
                        <strong class="text-sm text-gray-900 dark:text-gray-100">{{ number(stage.count) }}</strong>
                        <span :title="money(stage.amount)">· {{ compactMoney(stage.amount) }}</span>
                        <i class="mdi mdi-chevron-right text-base leading-none transition-transform duration-cap-fast ease-cap group-hover:translate-x-0.5" aria-hidden="true" />
                    </span>
                </Link>
            </li>
        </ol>
    </div>
</template>
