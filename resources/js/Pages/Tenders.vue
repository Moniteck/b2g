<script setup>
import AppButton from '../Components/AppButton.vue';
import AppLayout from '../Layouts/AppLayout.vue';
import ResourceCrud from './Partials/ResourceCrud.vue';

defineProps({ records: Object, institutions: Array });

const statuses = ['Activa', 'En preparación', 'Presentada', 'Adjudicada', 'Perdida', 'Cancelada'].map((value) => ({ value, label: value }));
const fields = [
    { key: 'reference', label: 'Proceso' },
    { key: 'title', label: 'Título' },
    { key: 'institution_id', label: 'Institución', type: 'institution' },
    { key: 'purchase_type', label: 'Tipo' },
    { key: 'estimated_amount', label: 'Monto', type: 'number' },
    { key: 'published_at', label: 'Publicación', type: 'date' },
    { key: 'closing_date', label: 'Cierre', type: 'date' },
    { key: 'status', label: 'Estado', type: 'select', default: 'Activa', options: statuses },
    { key: 'offer_submitted', label: 'Oferta presentada', type: 'checkbox' },
    { key: 'result', label: 'Resultado' },
    { key: 'observations', label: 'Observaciones', type: 'textarea' },
];
</script>

<template>
    <AppLayout title="Licitaciones">
        <ResourceCrud title="Licitaciones" noun="licitación" :records="records" :institutions="institutions" :fields="fields" create-url="/licitaciones" update-url="/licitaciones/:id" delete-url="/licitaciones/:id">
            <template #actions>
                <AppButton external href="/licitaciones/export/pdf" icon="file-pdf-box">PDF</AppButton>
                <AppButton external href="/licitaciones/export/xlsx" icon="file-excel-outline">Excel</AppButton>
            </template>
        </ResourceCrud>
    </AppLayout>
</template>
