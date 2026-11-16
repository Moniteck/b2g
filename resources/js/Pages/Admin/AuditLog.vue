<script setup>
// Bitácora de auditoría de la empresa activa (solo lectura), con filtros y detalle de cambios.
import { computed, ref } from 'vue';
import AppButton from '@/Components/AppButton.vue';
import BaseModal from '@/Components/BaseModal.vue';
import DataTable from '@/Components/DataTable.vue';
import DateInput from '@/Components/DateInput.vue';
import DescriptionList from '@/Components/DescriptionList.vue';
import FormField from '@/Components/FormField.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import SelectInput from '@/Components/SelectInput.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Toolbar from '@/Components/Toolbar.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { dateTime, number } from '@/lib/format.js';
import { useListFilters } from '@/Pages/Admin/Partials/useListFilters.js';

const props = defineProps({
    logs: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({ actions: [], resource_types: [], users: [], channels: [] }) },
});

const { filters, loading, error, active, reset, reload } = useListFilters('/configuracion/auditoria', props.filters, {
    only: ['logs', 'filters'],
    defaults: { search: '', from: '', to: '', user_id: null, action: '', channel: 'all', resource_type: '' },
});

const CHANNEL_LABELS = { web: 'Web', mcp: 'MCP', console: 'Consola' };
const RESOURCE_LABELS = {
    Client: 'Cliente', Institution: 'Institución', Contact: 'Contacto', Company: 'Empresa', User: 'Usuario', Quote: 'Cotización',
    Contract: 'Contrato', Tender: 'Licitación', Opportunity: 'Oportunidad', Document: 'Documento', Event: 'Evento',
};

/** «usuario.rol_cambiado» → «Usuario · rol cambiado». */
function actionLabel(action) {
    const [area, ...rest] = String(action ?? '').split('.');
    const verb = rest.join(' ').replace(/_/g, ' ');
    const head = area.replace(/_/g, ' ');
    return verb ? `${head.charAt(0).toUpperCase()}${head.slice(1)} · ${verb}` : head;
}

const resourceLabel = (type) => RESOURCE_LABELS[type] ?? type ?? '—';

const columns = [
    { key: 'created_at', label: 'Fecha', format: (value) => dateTime(value) },
    { key: 'actor_name', label: 'Usuario', primary: true, wrap: true },
    { key: 'action', label: 'Acción', wrap: true },
    { key: 'resource_type', label: 'Recurso', hideOnMobile: true },
    { key: 'channel', label: 'Canal' },
];

const userOptions = computed(() => props.options.users);
const selected = ref(null);
const open = computed({ get: () => selected.value !== null, set: (value) => !value && (selected.value = null) });

const detail = computed(() => {
    const log = selected.value;
    if (!log) return [];
    return [
        { label: 'Fecha', value: dateTime(log.created_at, 'medium') },
        { label: 'Usuario', value: log.actor_name ?? 'Sistema' },
        { label: 'Acción', value: actionLabel(log.action) },
        { key: 'channel', label: 'Canal', value: CHANNEL_LABELS[log.channel] ?? log.channel },
        { label: 'Recurso', value: log.resource_type ? `${resourceLabel(log.resource_type)} #${log.resource_id ?? '—'}` : null },
        { label: 'Dirección IP', value: log.ip },
        { label: 'Id de petición', value: log.request_id, wide: true },
        { label: 'Navegador', value: log.user_agent, wide: true },
    ];
});

/** Cambios como filas «campo · antes → después» o «campo · valor». */
const changes = computed(() =>
    Object.entries(selected.value?.changes ?? {}).map(([field, value]) => {
        if (value && typeof value === 'object' && !Array.isArray(value) && ('de' in value || 'a' in value)) {
            return { field, before: format(value.de), after: format(value.a) };
        }
        return { field, before: null, after: format(value) };
    }),
);

function format(value) {
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'boolean') return value ? 'Sí' : 'No';
    if (typeof value === 'object') return JSON.stringify(value);
    return String(value);
}
</script>

<template>
    <AppLayout title="Auditoría">
        <PageHeader :description="`${number(logs.total)} ${logs.total === 1 ? 'registro' : 'registros'} de la empresa activa · solo lectura`" />

        <Toolbar
            v-model:search="filters.search"
            v-model:filter="filters.channel"
            search-placeholder="Buscar en la bitácora"
            filter-label="Canal"
            :filters="[
                { label: 'Todos', value: 'all' },
                { label: 'Web', value: 'web' },
                { label: 'MCP', value: 'mcp' },
            ]"
        >
            <template #filters>
                <div class="grid w-full grid-cols-2 gap-3 lg:flex lg:w-auto lg:flex-wrap">
                    <DateInput v-model="filters.from" class="lg:w-40" aria-label="Desde" :max="filters.to || ''" />
                    <DateInput v-model="filters.to" class="lg:w-40" aria-label="Hasta" :min="filters.from || ''" />
                    <SelectInput v-model="filters.user_id" class="lg:w-48" aria-label="Usuario" placeholder="Todos los usuarios" :options="userOptions" />
                    <SelectInput v-model="filters.action" class="lg:w-52" aria-label="Acción" placeholder="Todas las acciones" :options="options.actions.map((value) => ({ value, label: actionLabel(value) }))" />
                    <SelectInput v-model="filters.resource_type" class="col-span-2 lg:w-44" aria-label="Recurso" placeholder="Todos los recursos" :options="options.resource_types.map((value) => ({ value, label: resourceLabel(value) }))" />
                </div>
            </template>
        </Toolbar>

        <div>
            <DataTable
                :columns="columns"
                :rows="logs.data"
                :loading="loading"
                :error="error"
                :filtered="active"
                clickable
                caption="Bitácora de auditoría"
                empty-icon="shield-search"
                empty-title="Aún no hay registros de auditoría"
                empty-description="Aquí quedan los cambios de datos, roles, documentos y resultados de la empresa."
                @row-click="(row) => (selected = row)"
                @retry="reload"
                @clear-filters="reset"
            >
                <template #cell-actor_name="{ row }">{{ row.actor_name || 'Sistema' }}</template>
                <template #cell-action="{ value }">{{ actionLabel(value) }}</template>
                <template #cell-resource_type="{ row }">{{ row.resource_type ? `${resourceLabel(row.resource_type)} #${row.resource_id ?? '—'}` : '—' }}</template>
                <template #cell-channel="{ value }"><StatusBadge :status="value === 'mcp' ? 'info' : 'neutral'" :label="CHANNEL_LABELS[value] ?? value" /></template>
            </DataTable>
            <Pagination :paginator="logs" :only="['logs', 'filters']" />
        </div>

        <BaseModal v-model:open="open" size="lg" title="Detalle del registro" :description="selected ? actionLabel(selected.action) : ''">
            <div class="flex flex-col gap-6">
                <DescriptionList :items="detail" />
                <div>
                    <h3 class="mb-2 font-display text-sm font-semibold text-gray-900 dark:text-gray-100">Cambios</h3>
                    <p v-if="!changes.length" class="text-sm text-cap-text-soft">El registro no guarda cambios de campos.</p>
                    <div v-else class="overflow-x-auto rounded-cap-control border border-cap-divider">
                        <table class="w-full text-sm">
                            <thead class="bg-cap-table-head text-cap-table-head-ink border-b border-cap-table-head-line">
                                <tr><th scope="col" class="px-3 py-2 text-left font-medium">Campo</th><th scope="col" class="px-3 py-2 text-left font-medium">Antes</th><th scope="col" class="px-3 py-2 text-left font-medium">Después</th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="change in changes" :key="change.field" class="border-b border-cap-divider last:border-0">
                                    <td class="px-3 py-2 font-medium text-gray-900 dark:text-gray-100">{{ change.field }}</td>
                                    <td class="break-all px-3 py-2 text-cap-text-soft">{{ change.before ?? '—' }}</td>
                                    <td class="break-all px-3 py-2 text-gray-800 dark:text-gray-200">{{ change.after }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <template #footer>
                <AppButton @click="selected = null">Cerrar</AppButton>
            </template>
        </BaseModal>
    </AppLayout>
</template>
