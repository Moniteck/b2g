<script setup>
// Modal «Historial de sincronización»: las últimas corridas; al elegir una se abre su resultado.
import { ref, watch } from 'vue';
import AppButton from '../../Components/AppButton.vue';
import BaseModal from '../../Components/BaseModal.vue';
import DataTable from '../../Components/DataTable.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import { dateTime, number } from '../../lib/format.js';
import { getJson } from '../../lib/http.js';

const props = defineProps({
    open: { type: Boolean, default: false },
});

const emit = defineEmits(['update:open', 'select']);

const runs = ref([]);
const loading = ref(false);
const failed = ref('');

const columns = [
    { key: 'started_at', label: 'Inicio', primary: true, format: (value) => dateTime(value) },
    { key: 'status', label: 'Estado' },
    { key: 'trigger', label: 'Origen' },
    { key: 'created', label: 'Altas', align: 'right', format: (value) => number(value) },
    { key: 'updated', label: 'Cambios', align: 'right', format: (value) => number(value) },
    { key: 'deactivated', label: 'Bajas', align: 'right', format: (value) => number(value) },
    { key: 'error_count', label: 'Errores', align: 'right', format: (value) => number(value) },
];

async function load() {
    loading.value = true;
    failed.value = '';
    try {
        runs.value = (await getJson('/inventario/sincronizaciones')).data;
    } catch (error) {
        failed.value = error.message || 'No se pudo cargar el historial.';
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.open,
    (open) => open && load(),
    { immediate: true },
);
</script>

<template>
    <BaseModal :open="open" size="xl" title="Historial de sincronización" description="Las últimas 20 corridas con Blessing. Elige una para ver su resultado." @update:open="emit('update:open', $event)">
        <DataTable
            :columns="columns"
            :rows="runs"
            :loading="loading"
            :error="failed"
            clickable
            dense
            caption="Corridas de sincronización"
            empty-icon="sync"
            empty-title="Aún no hay sincronizaciones"
            empty-description="La primera corrida aparecerá aquí."
            @row-click="emit('select', $event)"
            @retry="load"
        >
            <template #cell-status="{ value }"><StatusBadge :value="value" /></template>
        </DataTable>

        <template #footer>
            <AppButton @click="emit('update:open', false)">Cerrar</AppButton>
        </template>
    </BaseModal>
</template>
