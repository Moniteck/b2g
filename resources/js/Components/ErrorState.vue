<script setup>
// Estado de error de una pantalla o de una sección (parcial): qué pasó y cómo seguir.
// Props: title, description, code (código o id para soporte), retryLabel ('Reintentar'; '' lo oculta),
//        compact. Eventos: retry. Slot `action` para otra salida («Volver a la lista»).
import AppButton from './AppButton.vue';

defineProps({
    title: { type: String, default: 'No se pudo cargar esta sección' },
    description: { type: String, default: 'Revisa tu conexión y vuelve a intentarlo. Si sigue igual, avisa a soporte.' },
    code: { type: [String, Number], default: '' },
    retryLabel: { type: String, default: 'Reintentar' },
    compact: { type: Boolean, default: false },
});

defineEmits(['retry']);
</script>

<template>
    <div role="alert" class="flex flex-col items-center justify-center px-6 text-center" :class="compact ? 'py-8' : 'py-12'">
        <i class="m-error-icon mdi mdi-alert-circle-outline text-cap-icon text-cap-status-error dark:text-cap-status-dark-error" aria-hidden="true" />
        <p class="mt-3 font-display text-base font-semibold text-gray-900 dark:text-gray-100">{{ title }}</p>
        <p class="mt-1 max-w-md text-sm text-cap-text-soft">{{ description }}</p>
        <p v-if="code" class="mt-1 text-xs tabular-nums text-cap-text-soft">Código para soporte: {{ code }}</p>
        <div v-if="retryLabel || $slots.action" class="mt-4 flex flex-wrap justify-center gap-2">
            <slot name="action" />
            <AppButton v-if="retryLabel" icon="refresh" @click="$emit('retry')">{{ retryLabel }}</AppButton>
        </div>
    </div>
</template>
