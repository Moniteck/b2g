<script setup>
// Pasos del proceso de una licitación (TenderFlow::for): Registrada · Pliego analizado · Cotización ·
// Oferta presentada · Resultado. Estados: done, current, todo, skipped, error.
// Horizontal desde `md`; en móvil, una fila deslizable compacta que lleva el paso actual a la vista.
import { nextTick, onMounted, ref } from 'vue';

defineProps({
    steps: { type: Array, required: true },
});

const list = ref(null);

const ICON = { done: 'check', error: 'close', skipped: 'minus', current: '', todo: '' };
const STATE_LABEL = { done: 'completado', current: 'paso actual', todo: 'pendiente', skipped: 'omitido', error: 'no se cumplió' };

onMounted(() => nextTick(() => list.value?.querySelector('[aria-current="step"]')?.scrollIntoView({ block: 'nearest', inline: 'center' })));
</script>

<template>
    <nav aria-label="Proceso de la licitación" data-tender-stepper>
        <ol ref="list" class="flex snap-x items-start gap-0 overflow-x-auto pb-1">
            <li v-for="(step, index) in steps" :key="step.key" class="relative flex min-w-28 flex-1 snap-center flex-col items-center gap-2 px-1 text-center" :aria-current="step.state === 'current' ? 'step' : undefined" :data-step="step.key" :data-state="step.state">
                <span v-if="index > 0" class="absolute right-1/2 top-4 h-0.5 w-full -translate-y-1/2" :class="['done', 'skipped'].includes(steps[index - 1].state) && step.state !== 'todo' ? 'bg-cap-chrome dark:bg-gray-100' : 'bg-cap-divider'" aria-hidden="true" />
                <span
                    class="relative flex h-8 w-8 items-center justify-center rounded-full border-2 text-sm font-semibold transition-colors duration-cap-base ease-cap"
                    :class="{
                        'border-cap-chrome bg-cap-chrome text-white dark:border-gray-100 dark:bg-gray-100 dark:text-gray-900': step.state === 'done',
                        'border-cap-brand bg-cap-card text-cap-brand ring-4 ring-cap-red-100 dark:border-cap-brand-soft dark:text-cap-brand-soft dark:ring-cap-red-600/25': step.state === 'current',
                        'border-cap-divider bg-cap-card text-cap-text-soft': step.state === 'todo' || step.state === 'skipped',
                        'border-cap-status-error bg-cap-status-error-tint text-cap-status-error-ink dark:border-cap-status-dark-error': step.state === 'error',
                    }"
                    aria-hidden="true"
                >
                    <i v-if="ICON[step.state]" class="mdi text-base leading-none" :class="`mdi-${ICON[step.state]}`" />
                    <span v-else>{{ index + 1 }}</span>
                </span>
                <span class="text-xs leading-tight" :class="step.state === 'current' ? 'font-semibold text-gray-900 dark:text-gray-100' : step.state === 'error' ? 'font-medium text-cap-status-error-ink' : 'text-cap-text-soft'">
                    {{ step.label }}<span class="sr-only">: {{ STATE_LABEL[step.state] }}</span>
                </span>
            </li>
        </ol>
    </nav>
</template>
