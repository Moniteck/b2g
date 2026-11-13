<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import ResourceCrud from './Partials/ResourceCrud.vue';

defineProps({ records: Object, analytics: Object });
const fields = [
    { key: 'name', label: 'Competidor' }, { key: 'tax_id', label: 'RTN' }, { key: 'category', label: 'Categoría' },
    { key: 'wins', label: 'Adjudicaciones', type: 'number', default: 0 }, { key: 'losses', label: 'No adjudicaciones', type: 'number', default: 0 },
    { key: 'average_award_price', label: 'Precio promedio adjudicado', type: 'number' }, { key: 'last_participation_at', label: 'Última participación', type: 'date' }, { key: 'notes', label: 'Notas', type: 'textarea' },
];
</script>
<template><Head title="Inteligencia Competitiva"/><AppLayout><ResourceCrud title="Inteligencia Competitiva" :records="records" :statistics="analytics" :fields="fields" create-url="/inteligencia-competitiva" update-url="/inteligencia-competitiva/:id" delete-url="/inteligencia-competitiva/:id"/></AppLayout></template>
