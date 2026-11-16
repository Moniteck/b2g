<script setup>
// Conexiones MCP: accesos concedidos a ChatGPT, Claude u otros asistentes. Cada usuario ve y revoca
// las suyas; el administrador de la empresa activa ve también las de su empresa.
import { ref } from 'vue';
import AppButton from '@/Components/AppButton.vue';
import DataTable from '@/Components/DataTable.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Toolbar from '@/Components/Toolbar.vue';
import { useConfirm } from '@/composables/useConfirm.js';
import AppLayout from '@/Layouts/AppLayout.vue';
import { dateTime, number, relativeTime } from '@/lib/format.js';
import { visit } from '@/lib/http.js';
import { useListFilters } from '@/Pages/Admin/Partials/useListFilters.js';

const props = defineProps({
    connections: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    stats: { type: Object, default: () => ({ active: 0, mine: 0 }) },
    serverUrl: { type: String, required: true },
    scope: { type: String, default: 'mine' },
});

const { filters, loading, error, active, reset, reload } = useListFilters('/configuracion/mcp', props.filters, {
    only: ['connections', 'filters', 'stats'],
    defaults: { status: 'active', search: '' },
});

const STATUS = { Activa: 'ok', Vencida: 'warning', Revocada: 'neutral' };

const columns = [
    { key: 'client', label: 'Asistente', primary: true, wrap: true },
    { key: 'user', label: 'Usuario', wrap: true, hideOnMobile: props.scope === 'mine' },
    { key: 'companies', label: 'Empresas', wrap: true },
    { key: 'level_label', label: 'Nivel' },
    { key: 'last_used_at', label: 'Último uso' },
    { key: 'status', label: 'Estado' },
];

const confirm = useConfirm();
const revoking = ref(null);
const copied = ref(false);

function revoke(row) {
    confirm({
        verb: 'Revocar',
        object: `el acceso de ${row.client}`,
        noun: 'acceso',
        message: row.mine
            ? 'El asistente dejará de funcionar con el CRM de inmediato. Para volver a usarlo tendrás que conectarlo y aprobarlo de nuevo.'
            : `${row.user} dejará de usar ${row.client} con el CRM de inmediato.`,
        action: async () => {
            revoking.value = row.id;
            try {
                await visit('delete', `/configuracion/mcp/${row.id}`);
            } finally {
                revoking.value = null;
            }
        },
    });
}

async function copyUrl() {
    try {
        await navigator.clipboard.writeText(props.serverUrl);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        copied.value = false;
    }
}
</script>

<template>
    <AppLayout title="Conexiones MCP">
        <PageHeader
            :description="scope === 'company' ? 'Asistentes conectados por ti y por los usuarios de la empresa activa' : 'Asistentes de IA que conectaste a tu cuenta'"
        />

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 cap-stagger">
            <StatCard variant="featured" label="Conexiones activas" :count="stats.active" :hint="scope === 'company' ? 'En la empresa activa' : 'De tu cuenta'" />
            <StatCard label="Mías" :count="stats.mine" hint="Conectadas con tu usuario" />
            <section class="rounded-cap-card border border-cap-divider bg-cap-card p-5 sm:col-span-2 lg:col-span-1">
                <h2 class="font-display text-sm font-semibold text-gray-900 dark:text-gray-100">Dirección del servidor</h2>
                <p class="mt-1 text-xs text-cap-text-soft">Agrégala como conector en ChatGPT o Claude; te pedirá aprobar el acceso aquí.</p>
                <div class="mt-3 flex items-center gap-2">
                    <code class="min-w-0 flex-1 truncate rounded-cap-control border border-cap-divider bg-cap-card-2 px-3 py-2 text-xs text-gray-900 dark:text-gray-100">{{ serverUrl }}</code>
                    <AppButton :icon="copied ? 'check' : 'content-copy'" @click="copyUrl">{{ copied ? 'Copiada' : 'Copiar' }}</AppButton>
                </div>
            </section>
        </div>

        <Toolbar
            v-model:search="filters.search"
            v-model:filter="filters.status"
            search-placeholder="Buscar por asistente o usuario"
            filter-label="Estado"
            :filters="[
                { label: 'Activas', value: 'active' },
                { label: 'Vencidas o revocadas', value: 'inactive' },
                { label: 'Todas', value: 'all' },
            ]"
        />

        <div>
            <DataTable
                :columns="columns"
                :rows="connections.data"
                :loading="loading"
                :error="error"
                :filtered="active"
                caption="Conexiones MCP"
                empty-icon="connection"
                empty-title="Aún no hay asistentes conectados"
                empty-description="Cuando conectes ChatGPT o Claude al CRM y apruebes el acceso, la conexión aparecerá aquí."
                @retry="reload"
                @clear-filters="reset"
            >
                <template #cell-client="{ row }">
                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ row.client }}</span>
                    <span class="block text-xs text-cap-text-soft">Aprobada {{ dateTime(row.authorized_at) }}</span>
                </template>
                <template #cell-user="{ row }">
                    <span class="text-gray-900 dark:text-gray-100">{{ row.mine ? 'Tú' : row.user }}</span>
                    <span v-if="!row.mine" class="block break-all text-xs text-cap-text-soft">{{ row.user_email }}</span>
                </template>
                <template #cell-companies="{ value }">{{ value.length ? value.join(', ') : '—' }}</template>
                <template #cell-level_label="{ row }"><StatusBadge :status="row.level === 'write' ? 'warning' : 'info'" :label="row.level_label" /></template>
                <template #cell-last_used_at="{ value }">
                    <span v-if="value" :title="dateTime(value)">{{ relativeTime(value) }}</span>
                    <span v-else class="text-cap-text-soft">Sin uso</span>
                </template>
                <template #cell-status="{ row }">
                    <StatusBadge :status="STATUS[row.status] ?? 'neutral'" :label="row.status" />
                    <span v-if="row.status === 'Activa' && row.expires_at" class="block text-xs text-cap-text-soft">Vence {{ dateTime(row.expires_at) }}</span>
                </template>
                <template #actions="{ row }">
                    <AppButton v-if="row.can_revoke" variant="danger" icon="link-off" :loading="revoking === row.id" @click="revoke(row)">Revocar</AppButton>
                </template>
            </DataTable>
            <Pagination :paginator="connections" :only="['connections', 'filters', 'stats']" />
        </div>

        <p class="text-xs text-cap-text-soft">{{ number(connections.total ?? 0) }} {{ connections.total === 1 ? 'conexión' : 'conexiones' }} en la vista.</p>
    </AppLayout>
</template>
