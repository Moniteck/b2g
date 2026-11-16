<script setup>
// Crear o editar una oportunidad del pipeline.
// Props: open (v-model:open), opportunity (null = nueva; si no, `{ id, form, institution, tender }` del
//        detalle), stage (etapa inicial al crear: prospecto u oportunidad), owners ([{ id, name }]),
//        tenders ([{ id, name, description }]), userId (responsable por defecto).
// Eventos: saved (el tablero ya llega actualizado por la redirección parcial). La etapa solo se elige al
// crear; después se cambia moviendo la tarjeta.
import { useForm } from '@inertiajs/vue3';
import { computed, nextTick, watch } from 'vue';
import CapSegmented from '@design/vue/CapSegmented.vue';
import AppButton from '@/Components/AppButton.vue';
import BaseModal from '@/Components/BaseModal.vue';
import DateInput from '@/Components/DateInput.vue';
import FormField from '@/Components/FormField.vue';
import MoneyInput from '@/Components/MoneyInput.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextArea from '@/Components/TextArea.vue';
import TextInput from '@/Components/TextInput.vue';
import { focusFirstError } from '@/lib/errors.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    opportunity: { type: Object, default: null },
    stage: { type: String, default: 'prospecto' },
    owners: { type: Array, default: () => [] },
    tenders: { type: Array, default: () => [] },
    userId: { type: [Number, String], default: null },
});

const emit = defineEmits(['update:open', 'saved']);

const editing = computed(() => Boolean(props.opportunity?.id));
const blank = () => ({
    title: '',
    institution_id: null,
    tender_id: null,
    owner_id: props.userId ? Number(props.userId) : null,
    amount: null,
    probability: 30,
    stage: props.stage,
    expected_close_date: '',
    next_action: '',
    notes: '',
});

const form = useForm(blank());

const ownerOptions = computed(() => props.owners.map((owner) => ({ value: owner.id, label: owner.name })));
const tenderOptions = computed(() => {
    const linked = props.opportunity?.tender;
    const list = [...props.tenders];
    if (linked && !list.some((tender) => tender.id === linked.id)) list.unshift({ id: linked.id, name: linked.reference, description: '' });
    return list;
});
const stageItems = [
    { label: 'Prospecto', value: 'prospecto' },
    { label: 'Oportunidad', value: 'oportunidad' },
];

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        form.clearErrors();
        const values = editing.value ? { ...blank(), ...props.opportunity.form } : blank();
        Object.keys(values).forEach((key) => (form[key] = values[key] ?? (key === 'title' || key === 'notes' || key === 'next_action' || key === 'expected_close_date' ? '' : null)));
        form.defaults(values);
    },
);

function submit() {
    if (form.processing) return;
    const options = {
        preserveScroll: true,
        preserveState: true,
        // La redirección de vuelta al tablero trae solo lo que cambia (sin otra recarga después).
        only: ['opportunities', 'summary', 'filterOptions', 'flash'],
        onSuccess: () => {
            emit('saved');
            emit('update:open', false);
        },
        onError: () => nextTick(() => focusFirstError(document)),
    };
    form.transform((data) => {
        const payload = { ...data, owner_id: data.owner_id === '' ? null : data.owner_id };
        if (editing.value) delete payload.stage;
        return payload;
    });
    if (editing.value) form.put(`/pipeline/${props.opportunity.id}`, options);
    else form.post('/pipeline', options);
}
</script>

<template>
    <BaseModal :open="open" as="form" size="lg" :title="editing ? 'Editar oportunidad' : 'Nueva oportunidad'" :description="editing ? 'La etapa se cambia moviendo la tarjeta en el tablero.' : 'Empieza en Prospecto u Oportunidad; después avanza moviendo la tarjeta.'" :persistent="form.processing || form.isDirty" @update:open="emit('update:open', $event)" @submit="submit">
        <div class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
            <FormField label="Título" required class="md:col-span-2" :error="form.errors.title">
                <TextInput v-model="form.title" maxlength="255" placeholder="Suministro de equipo médico" data-autofocus />
            </FormField>
            <FormField label="Institución" required :error="form.errors.institution_id">
                <SearchSelect v-model="form.institution_id" url="/instituciones/buscar" option-value="id" option-label="name" :selected="opportunity?.institution" placeholder="Buscar institución…" />
            </FormField>
            <FormField label="Licitación vinculada" optional :error="form.errors.tender_id" help="Una licitación tiene una sola oportunidad.">
                <SearchSelect v-model="form.tender_id" :options="tenderOptions" option-value="id" option-label="name" option-description="description" placeholder="Buscar licitación…" :min-chars="0" />
            </FormField>
            <FormField label="Monto estimado" required :error="form.errors.amount">
                <MoneyInput v-model="form.amount" :min="0" />
            </FormField>
            <FormField label="Probabilidad (%)" required :error="form.errors.probability">
                <TextInput v-model="form.probability" type="number" min="0" max="100" step="5" inputmode="numeric" />
            </FormField>
            <FormField label="Cierre esperado" optional :error="form.errors.expected_close_date">
                <DateInput v-model="form.expected_close_date" />
            </FormField>
            <FormField label="Responsable" optional :error="form.errors.owner_id">
                <SelectInput v-model="form.owner_id" :options="ownerOptions" placeholder="Sin asignar" />
            </FormField>
            <FormField v-if="!editing" label="Etapa inicial" class="md:col-span-2" :error="form.errors.stage">
                <div class="max-w-full overflow-x-auto"><CapSegmented v-model="form.stage" :items="stageItems" label="Etapa inicial" /></div>
            </FormField>
            <FormField label="Próxima acción" optional class="md:col-span-2" :error="form.errors.next_action">
                <TextInput v-model="form.next_action" maxlength="255" placeholder="Visitar a la unidad de compras" />
            </FormField>
            <FormField label="Notas" optional class="md:col-span-2" :error="form.errors.notes">
                <TextArea v-model="form.notes" :rows="3" maxlength="5000" />
            </FormField>
        </div>
        <template #footer>
            <AppButton :disabled="form.processing" @click="emit('update:open', false)">Cancelar</AppButton>
            <AppButton variant="primary" type="submit" icon="content-save-outline" :loading="form.processing" :disabled="form.processing">{{ editing ? 'Guardar cambios' : 'Crear oportunidad' }}</AppButton>
        </template>
    </BaseModal>
</template>
