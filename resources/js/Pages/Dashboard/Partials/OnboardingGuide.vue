<script setup>
// Primeros pasos de una empresa nueva: importar una captura, cargar documentos, invitar al equipo y crear
// la primera cotización. Muestra el avance y, en cada paso pendiente, su acción (si el rol puede).
// «Importar captura» abre el modal del Dashboard (evento `import`). Props: steps; evento: import.
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    steps: { type: Array, default: () => [] },
});
const emit = defineEmits(['import']);

const done = computed(() => props.steps.filter((step) => step.done).length);
const progress = computed(() => (props.steps.length ? Math.round((done.value / props.steps.length) * 100) : 0));
</script>

<template>
    <section class="rounded-cap-card border border-cap-red-100 bg-cap-red-50 p-4 dark:border-cap-brand-soft/30 dark:bg-cap-red-600/15 sm:p-5" aria-labelledby="dashboard-onboarding">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h2 id="dashboard-onboarding" class="font-display text-lg font-semibold text-gray-900 dark:text-gray-100">Pon en marcha tu empresa</h2>
                <p class="text-sm text-cap-text-soft">Con estos pasos el tablero empieza a mostrar tus plazos, tu pipeline y tus cifras.</p>
            </div>
            <div class="flex items-center gap-3 text-xs text-cap-text-soft">
                <span class="h-2 w-28 rounded-full bg-cap-card"><span class="block h-full rounded-full bg-cap-brand transition-[width] duration-cap-slow ease-cap dark:bg-cap-brand-soft" :style="{ width: `${progress}%` }" /></span>
                {{ done }} de {{ steps.length }}
            </div>
        </div>
        <ol v-stagger class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <li v-for="(step, index) in steps" :key="step.key" class="flex gap-3 rounded-cap-control bg-cap-card p-3">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full" :class="step.done ? 'bg-cap-status-ok-tint text-cap-status-ok-ink' : 'bg-cap-red-200 text-cap-red-800 dark:bg-cap-red-600/20 dark:text-cap-brand-soft'" aria-hidden="true">
                    <i class="mdi text-xl leading-none" :class="step.done ? 'mdi-check' : `mdi-${step.icon}`" />
                </span>
                <div class="flex min-w-0 flex-1 flex-col gap-1">
                    <p class="text-sm font-semibold text-gray-900 dark:text-gray-100" :class="{ 'line-through decoration-gray-400': step.done }">
                        <span class="sr-only">Paso {{ index + 1 }}{{ step.done ? ', hecho' : '' }}: </span>{{ step.title }}
                    </p>
                    <p class="text-xs text-cap-text-soft">{{ step.detail }}</p>
                    <template v-if="!step.done && step.action">
                        <button v-if="step.key === 'tender'" type="button" class="mt-1 self-start text-xs font-medium text-gray-900 underline decoration-gray-400 underline-offset-2 hover:decoration-current focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome dark:text-gray-100 dark:focus-visible:ring-gray-100" @click="emit('import')">{{ step.action }}</button>
                        <Link v-else :href="step.href" class="mt-1 self-start text-xs font-medium text-gray-900 underline decoration-gray-400 underline-offset-2 hover:decoration-current focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome dark:text-gray-100 dark:focus-visible:ring-gray-100">{{ step.action }}</Link>
                    </template>
                </div>
            </li>
        </ol>
    </section>
</template>
