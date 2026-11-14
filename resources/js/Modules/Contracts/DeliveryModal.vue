<script setup>
// Crear o editar una entrega del contrato. Su estado es independiente del estado del contrato.
// Props: open (v-model:open), contractId, delivery (null = nueva), statuses.
import { useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import AppButton from '../../Components/AppButton.vue';
import BaseModal from '../../Components/BaseModal.vue';
import DateInput from '../../Components/DateInput.vue';
import FormField from '../../Components/FormField.vue';
import SelectInput from '../../Components/SelectInput.vue';
import TextArea from '../../Components/TextArea.vue';
import TextInput from '../../Components/TextInput.vue';
import { focusFirstError } from '../../lib/errors.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    contractId: { type: Number, required: true },
    delivery: { type: Object, default: null },
    statuses: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:open']);
const root = ref(null);
const editing = computed(() => Boolean(props.delivery?.id));

const blank = () => ({ description: '', quantity: 1, unit: 'Unidad', due_date: '', status: 'Pendiente', delivered_at: '', notes: '' });
const form = useForm(blank());

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        const d = props.delivery;
        form.defaults(d ? { description: d.description, quantity: d.quantity, unit: d.unit ?? '', due_date: d.due_date ?? '', status: d.status, delivered_at: d.delivered_at ?? '', notes: d.notes ?? '' } : blank());
        form.reset();
        form.clearErrors();
    },
);

function submit() {
    const options = { preserveScroll: true, onSuccess: () => emit('update:open', false), onError: () => nextTick(() => focusFirstError(root.value)) };
    form.transform((data) => ({ ...data, delivered_at: data.status === 'Entregada' ? data.delivered_at || null : null }));
    if (editing.value) form.put(`/post-adjudicacion/${props.contractId}/entregas/${props.delivery.id}`, options);
    else form.post(`/post-adjudicacion/${props.contractId}/entregas`, options);
}
</script>

<template>
    <BaseModal :open="open" as="form" size="lg" :title="editing ? 'Editar entrega' : 'Nueva entrega'" description="La fecha comprometida aparece en el calendario con sus recordatorios." :persistent="form.processing || form.isDirty" @update:open="emit('update:open', $event)" @submit="submit">
        <div ref="root" class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
            <FormField label="Descripción" required class="md:col-span-2" :error="form.errors.description"><TextInput v-model="form.description" maxlength="255" /></FormField>
            <FormField label="Cantidad" required :error="form.errors.quantity"><TextInput v-model="form.quantity" type="number" min="0.01" step="0.01" inputmode="decimal" /></FormField>
            <FormField label="Unidad" optional :error="form.errors.unit"><TextInput v-model="form.unit" maxlength="50" /></FormField>
            <FormField label="Fecha comprometida" optional :error="form.errors.due_date"><DateInput v-model="form.due_date" /></FormField>
            <FormField label="Estado" required :error="form.errors.status"><SelectInput v-model="form.status" :options="statuses" placeholder="" /></FormField>
            <FormField v-if="form.status === 'Entregada'" label="Fecha real de entrega" optional :error="form.errors.delivered_at" help="Si se deja vacía, es hoy."><DateInput v-model="form.delivered_at" /></FormField>
            <FormField label="Notas" optional class="md:col-span-2" :error="form.errors.notes"><TextArea v-model="form.notes" :rows="3" maxlength="2000" /></FormField>
        </div>
        <template #footer>
            <AppButton :disabled="form.processing" @click="emit('update:open', false)">Cancelar</AppButton>
            <AppButton variant="primary" type="submit" icon="content-save-outline" :loading="form.processing">{{ editing ? 'Guardar entrega' : 'Registrar entrega' }}</AppButton>
        </template>
    </BaseModal>
</template>
