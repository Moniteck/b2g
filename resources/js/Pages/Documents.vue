<script setup>
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import { useConfirm } from '../composables/useConfirm.js';
import { visit } from '../lib/http.js';
const ask = useConfirm();
defineProps({ records: Object });
function renew(id) { const issuedAt = prompt('Fecha de emisión (AAAA-MM-DD)'); if (issuedAt) router.patch(`/documentos/${id}/renovar`, { issued_at: issuedAt, expires_at: prompt('Vencimiento (opcional, AAAA-MM-DD)') || null }); }
function remove(id) { ask({ verb: 'Eliminar', object: 'este documento', noun: 'documento', message: 'Se borrará también su archivo privado. No se puede deshacer.', action: () => visit('delete', `/documentos/${id}`) }); }
</script>
<template><Head title="Documentos"/><AppLayout><section class="module-page"><div class="module-header"><div><h2>Biblioteca Documental</h2><p>Archivos privados, fechas de vigencia y renovación trazable.</p></div></div><form class="module-toolbar" enctype="multipart/form-data" @submit.prevent="router.post('/documentos', new FormData($event.target))"><input name="name" required placeholder="Nombre"><input name="category" placeholder="Categoría"><input name="issued_at" type="date"><input name="expires_at" type="date"><input name="status" value="Vigente"><input name="file" type="file" required><button class="module-primary">Subir</button></form><div class="module-table-wrap"><table class="module-table"><thead><tr><th>Documento</th><th>Emisión / vencimiento</th><th>Estado</th><th>Acciones</th></tr></thead><tbody><tr v-for="row in records.data" :key="row.id"><td><strong>{{ row.name }}</strong><small>{{ row.original_filename || 'Sin archivo' }}</small></td><td>{{ row.issued_at || '-' }}<br>{{ row.expires_at || '-' }}</td><td>{{ row.status }}</td><td><a class="row-action" :href="`/documentos/${row.id}/download`">Descargar</a><button class="row-action" @click="renew(row.id)">Renovar</button><button class="row-action delete" @click="remove(row.id)">Eliminar</button></td></tr></tbody></table></div></section></AppLayout></template>
<style scoped>small{display:block;color:#64748b}</style>
