<script setup>
// Alta y edición manual de una licitación con todos sus campos.
// Props: open (v-model:open), tender (null = nueva), statuses, modalities.
import { useForm } from '@inertiajs/vue3';
import { computed, nextTick, watch } from 'vue';
import AppButton from '@/Components/AppButton.vue';
import BaseModal from '@/Components/BaseModal.vue';
import DateTimeInput from '@/Components/DateTimeInput.vue';
import FormField from '@/Components/FormField.vue';
import MoneyInput from '@/Components/MoneyInput.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextArea from '@/Components/TextArea.vue';
import TextInput from '@/Components/TextInput.vue';
import { focusFirstError } from '@/lib/errors.js';
import { emptyTender, formFrom } from '@/Pages/Tenders/Partials/tenders.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    tender: { type: Object, default: null },
    statuses: { type: Array, default: () => [] },
    modalities: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:open']);

const form = useForm(emptyTender());
const editing = computed(() => Boolean(props.tender?.id));

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        const values = props.tender ? formFrom(props.tender) : emptyTender();
        form.defaults(values);
        form.reset();
        form.clearErrors();
    },
);

const modalityOptions = computed(() => props.modalities.map((value) => ({ value, label: value })));

function close() {
    emit('update:open', false);
}

function submit() {
    const options = {
        preserveScroll: true,
        onSuccess: () => close(),
        onError: () => nextTick(() => focusFirstError(document.querySelector('[data-tender-form]'))),
    };
    if (editing.value) form.put(`/licitaciones/${props.tender.id}`, options);
    else form.post('/licitaciones', options);
}
</script>

<template>
    <BaseModal :open="open" as="form" size="xl" :title="editing ? `Editar ${tender.reference}` : 'Nueva licitación'" description="Las fechas van en hora de Honduras." :persistent="form.processing || form.isDirty" @update:open="emit('update:open', $event)" @submit="submit">
        <div class="flex flex-col gap-8" data-tender-form>
            <section class="flex flex-col gap-4">
                <h3 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Identificación</h3>
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
                    <FormField label="Expediente" required :error="form.errors.reference" help="Como aparece en HonduCompras, p. ej. LPN-SIT-007-2026.">
                        <TextInput v-model="form.reference" maxlength="100" autocomplete="off" />
                    </FormField>
                    <FormField label="Estado" required :error="form.errors.status">
                        <SelectInput v-model="form.status" :options="statuses" placeholder="" />
                    </FormField>
                    <FormField label="Objeto" required class="md:col-span-2" :error="form.errors.title">
                        <TextArea v-model="form.title" :rows="2" maxlength="1000" />
                    </FormField>
                    <FormField label="Entidad" optional :error="form.errors.institution_id">
                        <SearchSelect v-model="form.institution_id" url="/instituciones/buscar" option-value="id" option-label="name" :selected="tender?.institution ?? null" placeholder="Buscar institución…" />
                    </FormField>
                    <FormField label="Unidad de compra" optional :error="form.errors.purchasing_unit">
                        <TextInput v-model="form.purchasing_unit" maxlength="255" />
                    </FormField>
                    <FormField label="Nombre del proceso" optional class="md:col-span-2" :error="form.errors.process_name">
                        <TextInput v-model="form.process_name" maxlength="255" />
                    </FormField>
                </div>
            </section>

            <section class="flex flex-col gap-4">
                <h3 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Fechas críticas</h3>
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
                    <FormField label="Inicio" optional :error="form.errors.started_at"><DateTimeInput v-model="form.started_at" /></FormField>
                    <FormField label="Cierre de aclaraciones" optional :error="form.errors.clarification_deadline"><DateTimeInput v-model="form.clarification_deadline" /></FormField>
                    <FormField label="Recepción de ofertas" optional :error="form.errors.presentation_deadline"><DateTimeInput v-model="form.presentation_deadline" /></FormField>
                    <FormField label="Apertura de ofertas" optional :error="form.errors.opening_at"><DateTimeInput v-model="form.opening_at" /></FormField>
                </div>
            </section>

            <section class="flex flex-col gap-4">
                <h3 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Clasificación y montos</h3>
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
                    <FormField label="Modalidad" optional :error="form.errors.purchase_type">
                        <SelectInput v-model="form.purchase_type" :options="modalityOptions" placeholder="Sin definir" />
                    </FormField>
                    <FormField label="Etapa en HonduCompras" optional :error="form.errors.honducompras_stage"><TextInput v-model="form.honducompras_stage" maxlength="120" /></FormField>
                    <FormField label="Tipo de adquisición" optional :error="form.errors.acquisition_type"><TextInput v-model="form.acquisition_type" maxlength="120" /></FormField>
                    <FormField label="Tipo de fuente" optional :error="form.errors.funding_source_type"><TextInput v-model="form.funding_source_type" maxlength="120" /></FormField>
                    <FormField label="Fuente" optional :error="form.errors.funding_source"><TextInput v-model="form.funding_source" maxlength="120" /></FormField>
                    <FormField label="Monto estimado" optional :error="form.errors.estimated_amount"><MoneyInput v-model="form.estimated_amount" :min="0" /></FormField>
                    <FormField label="Valor de pliegos" optional :error="form.errors.bid_document_fee"><MoneyInput v-model="form.bid_document_fee" :min="0" /></FormField>
                    <FormField label="Vigencia de la oferta (días)" optional :error="form.errors.offer_validity_days">
                        <TextInput v-model="form.offer_validity_days" type="number" min="1" max="3650" inputmode="numeric" class="md:max-w-40" />
                    </FormField>
                </div>
            </section>

            <section class="flex flex-col gap-4">
                <h3 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Recepción y contacto</h3>
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
                    <FormField label="Lugar de recepción de ofertas" optional class="md:col-span-2" :error="form.errors.offer_reception_place"><TextInput v-model="form.offer_reception_place" maxlength="500" /></FormField>
                    <FormField label="Contacto" optional :error="form.errors.contact_name"><TextInput v-model="form.contact_name" maxlength="255" /></FormField>
                    <FormField label="Teléfono" optional :error="form.errors.contact_phone"><TextInput v-model="form.contact_phone" maxlength="100" /></FormField>
                    <FormField label="Correo" optional :error="form.errors.contact_email"><TextInput v-model="form.contact_email" type="email" maxlength="255" /></FormField>
                    <FormField label="Enlace en HonduCompras" optional :error="form.errors.source_url"><TextInput v-model="form.source_url" type="url" maxlength="255" placeholder="https://" /></FormField>
                    <FormField label="Plazo de entrega" optional :error="form.errors.delivery_terms"><TextInput v-model="form.delivery_terms" maxlength="255" /></FormField>
                    <FormField label="Forma de pago" optional :error="form.errors.payment_terms"><TextInput v-model="form.payment_terms" maxlength="255" /></FormField>
                    <FormField label="Requisitos y criterios" optional class="md:col-span-2" :error="form.errors.requirements"><TextArea v-model="form.requirements" :rows="3" maxlength="10000" /></FormField>
                    <FormField label="Observaciones" optional class="md:col-span-2" :error="form.errors.observations"><TextArea v-model="form.observations" :rows="3" maxlength="5000" /></FormField>
                </div>
            </section>
        </div>

        <template #footer>
            <AppButton :disabled="form.processing" @click="close">Cancelar</AppButton>
            <AppButton variant="primary" type="submit" icon="content-save-outline" :loading="form.processing">{{ editing ? 'Guardar cambios' : 'Crear licitación' }}</AppButton>
        </template>
    </BaseModal>
</template>
