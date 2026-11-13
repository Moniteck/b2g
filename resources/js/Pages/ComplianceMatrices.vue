<script setup>
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
defineProps({ records: Object });
function toggle(matrix, item) { router.patch(`/matriz-cumplimiento/${matrix}/items/${item.id}`, { completed: !item.completed }); }
function renew(matrix, item) { const documentId = prompt('ID del documento renovado'); if (documentId) router.patch(`/matriz-cumplimiento/${matrix}/items/${item.id}/renovar`, { document_id: documentId, renewal_due_at: prompt('Próxima renovación (AAAA-MM-DD)') || null }); }
</script>
<template><Head title="Matriz de Cumplimiento"/><AppLayout><section class="module-page"><div class="module-header"><div><h2>Matriz de Cumplimiento</h2><p>Checklist y renovaciones basados en datos persistidos.</p></div></div><div v-for="matrix in records.data" :key="matrix.id" class="module-table-wrap"><h3>{{ matrix.name }} <small>{{ matrix.tender?.reference }}</small></h3><table class="module-table"><thead><tr><th>Requisito</th><th>Documento</th><th>Renovación</th><th>Estado</th><th></th></tr></thead><tbody><tr v-for="item in matrix.items" :key="item.id"><td>{{ item.requirement }}</td><td>{{ item.document?.name || 'Sin vincular' }}</td><td>{{ item.renewal_due_at || '-' }}</td><td>{{ item.completed ? 'Cumplido' : 'Pendiente' }}</td><td><button class="row-action" @click="toggle(matrix.id, item)">{{ item.completed ? 'Marcar pendiente' : 'Completar' }}</button><button class="row-action" @click="renew(matrix.id, item)">Renovar</button></td></tr></tbody></table></div></section></AppLayout></template>
<style scoped>small{color:#64748b;font-weight:400}</style>
