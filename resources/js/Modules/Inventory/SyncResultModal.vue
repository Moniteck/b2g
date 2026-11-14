<script setup>
// Modal «Resultado de sincronización»: estado, altas, cambios, bajas, errores y duración de una corrida.
// Props: open (v-model:open), run (resumen de `InventorySyncRun::summary()`).
import { computed } from 'vue';
import CapAlert from '../../../design/vue/CapAlert.vue';
import AppButton from '../../Components/AppButton.vue';
import BaseModal from '../../Components/BaseModal.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import { dateTime, number } from '../../lib/format.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    run: { type: Object, default: null },
});

const emit = defineEmits(['update:open']);

const figures = computed(() => [
    { label: 'Altas', value: props.run?.created ?? 0 },
    { label: 'Cambios', value: props.run?.updated ?? 0 },
    { label: 'Bajas', value: props.run?.deactivated ?? 0 },
    { label: 'Errores', value: props.run?.error_count ?? 0 },
]);

const duration = computed(() => {
    const seconds = props.run?.duration_seconds;
    if (seconds === null || seconds === undefined) return '—';
    return seconds < 60 ? `${number(seconds)} s` : `${number(Math.floor(seconds / 60))} min ${number(seconds % 60)} s`;
});

const alert = computed(() => {
    if (!props.run) return null;
    if (props.run.status === 'Fallida') return { status: 'error', title: 'La sincronización falló y se conservó el inventario anterior', detail: props.run.error };
    if (props.run.status === 'Parcial') return { status: 'warning', title: 'Algunos productos no se pudieron guardar', detail: 'El resto del inventario quedó actualizado. Revisa el detalle abajo.' };
    if (props.run.status === 'En curso') return { status: 'info', title: 'La sincronización sigue en curso' };
    return { status: 'ok', title: 'Inventario actualizado', detail: `${number(props.run.processed)} productos revisados.` };
});
</script>

<template>
    <BaseModal :open="open" size="md" title="Resultado de sincronización" @update:open="emit('update:open', $event)">
        <div v-if="run" class="flex flex-col gap-4">
            <CapAlert v-if="alert" :status="alert.status" :title="alert.title" :detail="alert.detail || ''" />

            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-cap-text-soft">
                <StatusBadge :value="run.status" />
                <span>{{ dateTime(run.started_at) }}</span>
                <span>{{ run.trigger }}<template v-if="run.user"> · {{ run.user }}</template></span>
                <span>Duración: {{ duration }}</span>
            </div>

            <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div v-for="figure in figures" :key="figure.label" class="rounded-cap-control bg-cap-card-2 p-3">
                    <dt class="text-xs font-medium text-cap-text-soft">{{ figure.label }}</dt>
                    <dd class="mt-1 font-display text-2xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ number(figure.value) }}</dd>
                </div>
            </dl>

            <div v-if="run.errors?.length">
                <h3 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Productos con error</h3>
                <ul class="mt-2 divide-y divide-cap-divider rounded-cap-control border border-cap-divider">
                    <li v-for="(entry, index) in run.errors" :key="index" class="px-3 py-2 text-sm">
                        <span class="font-medium text-gray-900 dark:text-gray-100">{{ entry.sku || entry.id || 'Sin SKU' }}</span>
                        <span class="block text-cap-text-soft">{{ entry.message }}</span>
                    </li>
                </ul>
            </div>
        </div>

        <template #footer>
            <AppButton @click="emit('update:open', false)">Cerrar</AppButton>
        </template>
    </BaseModal>
</template>
