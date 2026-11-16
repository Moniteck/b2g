<script setup>
// Empresas del grupo. La raíz las crea (asistente del marco: datos fiscales, logo, primer
// administrador, Blessing e ISV) y administra; un administrador de empresa solo ve las suyas.
// La prop del paginador es `companyRecords`: `companies` es la prop compartida del selector (B-12).
import { router } from '@inertiajs/vue3';
import CapAlert from '../../../../design/vue/CapAlert.vue';
import AppButton from '../../../Components/AppButton.vue';
import DataTable from '../../../Components/DataTable.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import Pagination from '../../../Components/Pagination.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import Toolbar from '../../../Components/Toolbar.vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import { useShell } from '../../../Layouts/shell.js';
import { number } from '../../../lib/format.js';
import { useListFilters } from '../Partials/useListFilters.js';

const props = defineProps({
    companyRecords: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
});

const { filters, loading, error, active, reset, reload } = useListFilters('/empresas', props.filters, {
    only: ['companyRecords', 'filters'],
    defaults: { search: '' },
});

const ROLE_LABELS = { admin: 'Administrador', operator: 'Operador', viewer: 'Solo lectura' };

const columns = [
    { key: 'name', label: 'Empresa', primary: true, wrap: true },
    { key: 'tax_id', label: 'RTN', hideOnMobile: true },
    { key: 'users_count', label: 'Miembros', align: 'right', format: (value) => number(value) },
    { key: 'blessing', label: 'Blessing', hideOnMobile: true },
    { key: 'is_active', label: 'Estado' },
];

const shell = useShell();
const openCreate = () => shell?.openCompanyWizard?.();
</script>

<template>
    <AppLayout title="Empresas" :fab-label="can.manage ? 'Nueva empresa' : ''" @fab="openCreate">
        <PageHeader :description="`${number(companyRecords.total)} ${companyRecords.total === 1 ? 'empresa' : 'empresas'} del grupo`">
            <template #actions>
                <AppButton v-if="can.manage" class="max-lg:hidden" variant="primary" icon="plus" @click="openCreate">Nueva empresa</AppButton>
            </template>
        </PageHeader>

        <CapAlert v-if="!can.manage" status="info" title="Solo la raíz del grupo administra empresas" detail="Aquí ve las empresas a las que pertenece. Los miembros de su empresa se gestionan en Usuarios." />

        <Toolbar v-model:search="filters.search" search-placeholder="Buscar empresa o RTN" />

        <div>
            <DataTable
                :columns="columns"
                :rows="companyRecords.data"
                :loading="loading"
                :error="error"
                :filtered="active"
                clickable
                caption="Empresas"
                empty-icon="domain"
                empty-title="No hay empresas"
                empty-description="Cree la primera empresa del grupo."
                @row-click="(row) => router.visit(`/empresas/${row.id}`)"
                @retry="reload"
                @clear-filters="reset"
            >
                <template #cell-name="{ row }">
                    <div class="flex min-w-0 items-center gap-3">
                        <img v-if="row.logo_url" :src="row.logo_url" alt="" class="h-9 w-9 shrink-0 rounded-cap-control border border-cap-divider bg-cap-card object-contain" />
                        <span v-else class="flex h-9 w-9 shrink-0 items-center justify-center rounded-cap-control border border-cap-divider bg-cap-card-2 text-cap-text-soft" aria-hidden="true"><i class="mdi mdi-domain text-xl leading-none" /></span>
                        <div class="min-w-0">
                            <p class="font-medium text-gray-900 dark:text-gray-100">{{ row.name }}</p>
                            <p class="text-xs text-cap-text-soft">{{ [row.code, row.my_role ? ROLE_LABELS[row.my_role] : null].filter(Boolean).join(' · ') || '—' }}</p>
                        </div>
                    </div>
                </template>
                <template #cell-blessing="{ value }"><StatusBadge :status="value ? 'ok' : 'neutral'" :label="value ? 'Conectada' : 'Sin configurar'" /></template>
                <template #cell-is_active="{ value }"><StatusBadge :value="value ? 'Activa' : 'Inactiva'" /></template>
                <template #empty-action><AppButton v-if="can.manage" variant="primary" icon="plus" @click="openCreate">Nueva empresa</AppButton></template>
            </DataTable>
            <Pagination :paginator="companyRecords" :per-page-options="[]" :only="['companyRecords', 'filters']" />
        </div>

    </AppLayout>
</template>
