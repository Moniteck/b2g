<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';

const props = defineProps({ report: { type: Object, required: true } });
const filters = ref({ ...props.report.filters });
const money = (value) => `L ${new Intl.NumberFormat('es-HN', { minimumFractionDigits: 2 }).format(value || 0)}`;
const query = computed(() => new URLSearchParams(filters.value).toString());
function apply() { router.get('/reportes', filters.value, { preserveState: true, replace: true }); }
</script>
<template><Head title="Reportes e Indicadores" /><AppLayout><section class="module-page"><div class="module-header"><div><h2>Reportes e Indicadores</h2><p>Agregados canónicos del período seleccionado.</p></div><div class="report-actions"><a :href="`/reportes/pdf?${query}`">PDF</a><a :href="`/reportes/xlsx?${query}`">XLSX</a><a :href="`/reportes/csv?${query}`">CSV</a></div></div><form class="module-toolbar" @submit.prevent="apply"><label>Desde <input v-model="filters.from" type="date"></label><label>Hasta <input v-model="filters.to" type="date"></label><select v-model="filters.module"><option value="all">Todos los módulos</option><option value="pipeline">Pipeline</option><option value="cotizaciones">Cotizaciones</option><option value="cobros">Cobros</option><option value="licitaciones">Licitaciones</option><option value="poa">POA</option></select><button class="module-primary">Actualizar</button></form><div class="module-table-wrap"><table class="module-table"><thead><tr><th>Indicador</th><th>Módulo</th><th>Registros</th><th>Monto</th></tr></thead><tbody><tr v-for="row in report.rows" :key="row.module"><td>{{ row.label }}</td><td>{{ row.module }}</td><td>{{ row.count }}</td><td>{{ money(row.amount) }}</td></tr><tr class="report-total"><td colspan="3">TOTAL</td><td>{{ money(report.total) }}</td></tr></tbody></table></div></section></AppLayout></template>
