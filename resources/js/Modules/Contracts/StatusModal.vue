<script setup>
// Cambiar el estado del contrato (mode 'change') o cerrarlo (mode 'close').
// Las transiciones válidas llegan del servidor (`transitions`); cerrar exige producto entregado.
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import CapAlert from '../../../design/vue/CapAlert.vue';
import AppButton from '../../Components/AppButton.vue';
import BaseModal from '../../Components/BaseModal.vue';
import Checkbox from '../../Components/Checkbox.vue';
import FormField from '../../Components/FormField.vue';
import SelectInput from '../../Components/SelectInput.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import TextArea from '../../Components/TextArea.vue';
import { tone } from './contracts.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    mode: { type: String, default: 'change' },
    contract: { type: Object, required: true },
    transitions: { type: Array, default: () => [] },
    deliveries: { type: Array, default: () => [] },
    preset: { type: String, default: '' },
});

const emit = defineEmits(['update:open']);
const form = useForm({ status: '', reason: '', confirm: false });
const closing = computed(() => props.mode === 'close');
const delivered = computed(() => props.contract.product_delivered || props.contract.status === 'Producto entregado');
const pending = computed(() => props.deliveries.filter((d) => !['Entregada', 'Cancelada'].includes(d.status)).length);
const options = computed(() => props.transitions.filter((s) => s !== 'Cerrado').map((value) => ({ value, label: value })));
const needsReason = computed(() => ['Cancelado', 'Suspendido'].includes(form.status));

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        form.defaults({ status: closing.value ? 'Cerrado' : props.preset || options.value[0]?.value || '', reason: '', confirm: false });
        form.reset();
        form.clearErrors();
    },
);

function submit() {
    const options = { preserveScroll: true, onSuccess: () => emit('update:open', false) };
    if (closing.value) form.patch(`/post-adjudicacion/${props.contract.id}/completar`, options);
    else form.patch(`/post-adjudicacion/${props.contract.id}/estado`, options);
}
</script>

<template>
    <BaseModal :open="open" as="form" size="md" :title="closing ? `Cerrar contrato ${contract.number}` : 'Cambiar estado'" :persistent="form.processing" @update:open="emit('update:open', $event)" @submit="submit">
        <div class="flex flex-col gap-4">
            <div class="flex flex-wrap items-center gap-2 text-sm text-cap-text-soft">
                Estado actual: <StatusBadge :status="tone(contract.status)" :label="contract.status" />
            </div>

            <template v-if="closing">
                <CapAlert v-if="!delivered" status="warning" title="Falta el producto entregado" detail="Marca el producto como entregado (en Editar o pasando a «Producto entregado») antes de cerrar." />
                <CapAlert v-else-if="pending" status="info" :title="`Quedan ${pending} ${pending === 1 ? 'entrega pendiente' : 'entregas pendientes'}`" detail="Puedes cerrar igual; sus recordatorios se cancelan." />
                <p class="text-sm text-gray-700 dark:text-gray-300">Al cerrar, el contrato deja de avisar en el calendario y queda como finalizado. Solo un administrador puede reabrirlo.</p>
                <FormField label="Nota de cierre" optional :error="form.errors.reason"><TextArea v-model="form.reason" :rows="2" maxlength="1000" /></FormField>
                <Checkbox v-model="form.confirm" :disabled="!delivered" label="Confirmo que el contrato está cumplido y se puede cerrar" />
                <p v-if="form.errors.status" class="text-sm text-cap-status-error-ink">{{ form.errors.status }}</p>
            </template>

            <template v-else>
                <CapAlert v-if="!options.length" status="info" title="Sin cambios posibles" detail="Desde este estado no hay transiciones permitidas para tu rol." />
                <template v-else>
                    <FormField label="Nuevo estado" required :error="form.errors.status"><SelectInput v-model="form.status" :options="options" placeholder="" /></FormField>
                    <FormField :label="needsReason ? 'Motivo' : 'Nota'" :required="needsReason" :optional="!needsReason" :error="form.errors.reason"><TextArea v-model="form.reason" :rows="2" maxlength="1000" /></FormField>
                </template>
            </template>
        </div>
        <template #footer>
            <AppButton :disabled="form.processing" @click="emit('update:open', false)">Cancelar</AppButton>
            <AppButton v-if="closing" variant="primary" type="submit" icon="lock-check-outline" :loading="form.processing" :disabled="!delivered || !form.confirm">Cerrar contrato</AppButton>
            <AppButton v-else-if="options.length" variant="primary" type="submit" icon="swap-horizontal" :loading="form.processing">Cambiar estado</AppButton>
        </template>
    </BaseModal>
</template>
