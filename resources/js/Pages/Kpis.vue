<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';

const props = defineProps({ period: Object, kpis: Array, canManage: Boolean });
const dates = ref({ ...props.period });
const target = ref({});
const moneyMetrics = ['pipeline', 'adjudicaciones', 'cobros'];
const value = (kpi) => moneyMetrics.includes(kpi.metric) ? `L ${new Intl.NumberFormat('es-HN', { minimumFractionDigits: 2 }).format(kpi.actual)}` : `${kpi.actual}%`;
function filter() { router.get('/kpis', dates.value, { preserveState: true, replace: true }); }
function save(kpi) { router.post('/kpis', { metric: kpi.metric, target: target.value[kpi.metric] ?? kpi.goal, period_start: dates.value.from, period_end: dates.value.to }, { preserveScroll: true }); }
</script>
<template><Head title="KPIs y Metas" /><AppLayout><section class="module-page"><div class="module-header"><div><h2>KPIs y Metas</h2><p>Valores reales calculados para el período elegido.</p></div></div><form class="module-toolbar" @submit.prevent="filter"><label>Desde <input v-model="dates.from" type="date"></label><label>Hasta <input v-model="dates.to" type="date"></label><button class="module-primary">Consultar</button></form><div class="kpi-grid"><article v-for="kpi in kpis" :key="kpi.metric" class="kpi-card"><span>{{ kpi.label }}</span><strong>{{ value(kpi) }}</strong><small>Meta: {{ moneyMetrics.includes(kpi.metric) ? `L ${Number(kpi.goal).toLocaleString('es-HN')}` : `${kpi.goal}%` }}</small><div class="kpi-progress"><i :style="{ width: `${Math.min(100, kpi.goal ? kpi.actual / kpi.goal * 100 : 0)}%` }"></i></div><form v-if="canManage" class="kpi-goal" @submit.prevent="save(kpi)"><input v-model.number="target[kpi.metric]" :placeholder="`Meta actual: ${kpi.goal}`" type="number" min="0" step="0.01"><button>Guardar meta</button></form></article></div></section></AppLayout></template>
