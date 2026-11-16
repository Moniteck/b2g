<script setup>
// Crear o editar un contrato (también completar el que nace «Pendiente de contrato» de una cotización ganada).
// Props: open (v-model:open), contract (null = nuevo; la ficha de `Contracts/Show`), officials, form (opciones del servidor).
import { useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import AppButton from '@/Components/AppButton.vue';
import BaseModal from '@/Components/BaseModal.vue';
import Checkbox from '@/Components/Checkbox.vue';
import DateInput from '@/Components/DateInput.vue';
import FormField from '@/Components/FormField.vue';
import MoneyInput from '@/Components/MoneyInput.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextArea from '@/Components/TextArea.vue';
import TextInput from '@/Components/TextInput.vue';
import Toggle from '@/Components/Toggle.vue';
import { focusFirstError } from '@/lib/errors.js';
import { date } from '@/lib/format.js';
import { getJson } from '@/lib/http.js';
import { contractForm } from '@/Pages/Contracts/Partials/contracts.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    contract: { type: Object, default: null },
    officials: { type: Array, default: () => [] },
    options: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['update:open']);

const form = useForm(contractForm());
const editing = computed(() => Boolean(props.contract?.id));
const preview = ref(null);
const previewError = ref('');
const root = ref(null);
let previewTimer = null;

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        form.defaults(contractForm(props.contract));
        form.reset();
        form.clearErrors();
        preview.value = null;
    },
);

// Cambiar de institución invalida el contacto elegido de otra.
watch(
    () => form.institution_id,
    (value, old) => {
        if (old !== undefined && value !== old && props.open && old !== null) form.contact_id = null;
    },
);

// Vista previa de la fecha fin calculada con los feriados de la empresa.
watch(
    () => [form.start_date, form.duration_days, form.duration_business_days, form.is_end_date_manual, props.open],
    () => {
        clearTimeout(previewTimer);
        previewError.value = '';
        if (!props.open || form.is_end_date_manual || !form.start_date || !Number(form.duration_days)) {
            preview.value = null;
            return;
        }
        previewTimer = setTimeout(async () => {
            try {
                const result = await getJson('/post-adjudicacion/plazo', { start_date: form.start_date, duration_days: form.duration_days, business: form.duration_business_days ? 1 : 0 });
                preview.value = result.end_date;
                form.end_date = result.end_date ?? form.end_date;
            } catch {
                previewError.value = 'No se pudo calcular la fecha fin. Se calculará al guardar.';
            }
        }, 300);
    },
);

const officialOptions = computed(() => props.officials.map((user) => ({ value: user.id, label: user.name })));
const triggerOptions = computed(() => (props.options.triggers ?? []).map((value) => ({ value, label: value })));
const statusOptions = computed(() => (props.options.initialStatuses ?? []).map((value) => ({ value, label: value })));
const contactParams = computed(() => (form.institution_id ? { institution_id: form.institution_id } : {}));
const termKind = computed({
    get: () => (form.duration_business_days ? 'habiles' : 'calendario'),
    set: (value) => (form.duration_business_days = value === 'habiles'),
});

function close() {
    emit('update:open', false);
}

function submit() {
    const options = {
        preserveScroll: true,
        onSuccess: () => close(),
        onError: () => nextTick(() => focusFirstError(root.value)),
    };
    form.transform((data) => ({ ...data, duration_days: data.duration_days === '' ? null : data.duration_days }));
    if (editing.value) form.put(`/post-adjudicacion/${props.contract.id}`, options);
    else form.post('/post-adjudicacion', options);
}
</script>

<template>
    <BaseModal
        :open="open"
        as="form"
        size="xl"
        :title="editing ? `Editar contrato ${contract.number}` : 'Nuevo contrato'"
        description="Los plazos generan sus eventos y recordatorios en el calendario al guardar."
        :persistent="form.processing || form.isDirty"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <div ref="root" class="flex flex-col gap-8">
            <section class="flex flex-col gap-4">
                <h3 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Identificación</h3>
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
                    <FormField label="Número de contrato" required :error="form.errors.number" help="Único en la empresa.">
                        <TextInput v-model="form.number" maxlength="100" autocomplete="off" />
                    </FormField>
                    <FormField v-if="!editing" label="Estado inicial" required :error="form.errors.status">
                        <SelectInput v-model="form.status" :options="statusOptions" placeholder="" />
                    </FormField>
                    <FormField label="Nombre u objeto" required :class="editing ? '' : 'md:col-span-2'" :error="form.errors.name">
                        <TextInput v-model="form.name" maxlength="255" />
                    </FormField>
                    <FormField label="Institución" optional :error="form.errors.institution_id">
                        <SearchSelect v-model="form.institution_id" url="/instituciones/buscar" option-value="id" option-label="name" :selected="contract?.institution ?? null" placeholder="Buscar institución…" />
                    </FormField>
                    <FormField label="Cliente" optional :error="form.errors.client_id" help="Se registra al ganar la oferta.">
                        <SearchSelect v-model="form.client_id" url="/post-adjudicacion/clientes" option-value="id" option-label="name" :selected="contract?.client ?? null" :min-chars="0" placeholder="Buscar cliente…" />
                    </FormField>
                </div>
            </section>

            <section class="flex flex-col gap-4">
                <h3 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Responsables</h3>
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
                    <FormField label="Persona responsable del contacto" optional :error="form.errors.contact_id" help="Contacto de la institución.">
                        <SearchSelect
                            :key="form.institution_id ?? 'sin-institucion'"
                            v-model="form.contact_id"
                            url="/contactos/buscar"
                            :params="contactParams"
                            :min-chars="form.institution_id ? 0 : 2"
                            option-value="id"
                            option-label="name"
                            option-description="position"
                            :selected="contract?.contact ?? null"
                            placeholder="Buscar contacto…"
                        />
                    </FormField>
                    <FormField label="Oficial de seguimiento" optional :error="form.errors.manager_id" help="Recibe los avisos del calendario.">
                        <SelectInput v-model="form.manager_id" :options="officialOptions" placeholder="Sin asignar" />
                    </FormField>
                </div>
            </section>

            <section class="flex flex-col gap-4">
                <h3 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Montos</h3>
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
                    <FormField label="Monto adjudicado" :error="form.errors.awarded_amount" help="Monto por el que se adjudicó el contrato.">
                        <MoneyInput v-model="form.awarded_amount" :min="0" />
                    </FormField>
                    <FormField label="Monto del contrato" optional :error="form.errors.amount" help="Si se deja vacío, es el adjudicado.">
                        <MoneyInput v-model="form.amount" :min="0" />
                    </FormField>
                </div>
            </section>

            <section class="flex flex-col gap-4">
                <h3 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Plazo y fechas</h3>
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
                    <FormField label="Fecha de firma" optional :error="form.errors.signed_at"><DateInput v-model="form.signed_at" /></FormField>
                    <FormField label="El plazo corre desde" optional :error="form.errors.start_trigger">
                        <SelectInput v-model="form.start_trigger" :options="triggerOptions" placeholder="Sin definir" />
                    </FormField>
                    <FormField label="Plazo (días)" optional :error="form.errors.duration_days">
                        <TextInput v-model="form.duration_days" type="number" min="1" max="3650" inputmode="numeric" class="md:max-w-40" />
                    </FormField>
                    <FormField label="Tipo de días">
                        <SelectInput v-model="termKind" :options="[{ value: 'calendario', label: 'Días calendario' }, { value: 'habiles', label: 'Días hábiles (lunes a viernes sin feriados)' }]" placeholder="" />
                    </FormField>
                    <FormField label="Fecha de inicio" optional :error="form.errors.start_date" :help="form.start_trigger === 'Firma del contrato' ? 'Si se deja vacía, es la fecha de firma.' : ''">
                        <DateInput v-model="form.start_date" />
                    </FormField>
                    <FormField label="Fecha fin" :optional="!form.is_end_date_manual" :required="form.is_end_date_manual" :error="form.errors.end_date"
                        :help="form.is_end_date_manual ? 'Fijada a mano: no se recalcula desde el plazo.' : preview ? `Calculada: ${date(preview, 'long')}` : previewError || 'Se calcula desde el inicio y el plazo.'">
                        <DateInput v-model="form.end_date" :readonly="!form.is_end_date_manual && Boolean(preview)" :min="form.start_date || ''" />
                    </FormField>
                    <Checkbox v-model="form.is_end_date_manual" class="md:col-span-2" label="Fijar fecha fin manualmente" description="La fecha que escribas no se pisa al cambiar el plazo." />
                </div>
            </section>

            <section class="flex flex-col gap-4">
                <h3 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Ejecución</h3>
                <Toggle v-model="form.product_delivered" label="Producto entregado" description="Requisito para cerrar el contrato." />
                <FormField label="Notas" optional :error="form.errors.notes"><TextArea v-model="form.notes" :rows="3" maxlength="5000" /></FormField>
            </section>
        </div>

        <template #footer>
            <AppButton :disabled="form.processing" @click="close">Cancelar</AppButton>
            <AppButton variant="primary" type="submit" icon="content-save-outline" :loading="form.processing">{{ editing ? 'Guardar cambios' : 'Crear contrato' }}</AppButton>
        </template>
    </BaseModal>
</template>
