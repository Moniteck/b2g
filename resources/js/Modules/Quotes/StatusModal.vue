<script setup>
// Cambiar el estado documental (Borrador → Emitida → Presentada; Sustituida, Anulada).
// Props: open, quote (fila con `number`, `status`, `next_statuses`).
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import AppButton from '../../Components/AppButton.vue';
import BaseModal from '../../Components/BaseModal.vue';
import FormField from '../../Components/FormField.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import { STATUS_HELP } from './quotes.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    quote: { type: Object, default: null },
});

const emit = defineEmits(['update:open']);

const form = useForm({ status: '' });
const options = computed(() => props.quote?.next_statuses ?? []);

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        form.defaults({ status: options.value[0] ?? '' });
        form.reset();
        form.clearErrors();
    },
);

function submit() {
    form.patch(`/cotizaciones/${props.quote.id}/estado`, { preserveScroll: true, onSuccess: () => emit('update:open', false) });
}
</script>

<template>
    <BaseModal :open="open" as="form" size="md" :title="`Cambiar estado · ${quote?.number ?? ''}`" :persistent="form.processing" @update:open="emit('update:open', $event)" @submit="submit">
        <div class="flex flex-col gap-4">
            <p class="flex items-center gap-2 text-sm text-cap-text-soft">Estado actual: <StatusBadge :value="quote?.status" :label="quote?.status" /></p>
            <FormField label="Nuevo estado" required :error="form.errors.status">
                <div class="flex flex-col gap-2" role="radiogroup" aria-label="Nuevo estado">
                    <label v-for="status in options" :key="status" class="flex cursor-pointer items-start gap-3 rounded-cap-control border p-3 transition-colors duration-cap-fast ease-cap hover:bg-cap-card-2" :class="form.status === status ? 'border-cap-brand bg-cap-card-2 dark:border-cap-brand-soft' : 'border-cap-divider'">
                        <input v-model="form.status" type="radio" name="quote-status" :value="status" class="mt-1 accent-cap-brand">
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold text-gray-900 dark:text-gray-100">{{ status }}</span>
                            <span class="block text-xs text-cap-text-soft">{{ STATUS_HELP[status] }}</span>
                        </span>
                    </label>
                </div>
            </FormField>
        </div>
        <template #footer>
            <AppButton :disabled="form.processing" @click="emit('update:open', false)">Cancelar</AppButton>
            <AppButton variant="primary" type="submit" icon="swap-horizontal" :loading="form.processing" :disabled="!form.status">Cambiar a {{ form.status || '…' }}</AppButton>
        </template>
    </BaseModal>
</template>
