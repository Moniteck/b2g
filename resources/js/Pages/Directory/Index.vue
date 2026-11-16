<script setup>
// Directorio Institucional: documentos de la empresa activa (RTN, solvencias, escritura…) con su
// vigencia. Subir, ver, reemplazar, renovar, historial, editar, eliminar y «Descargar para oferta».
import { router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useToasts } from '@design/vue/composables/useToasts.js';
import AppButton from '@/Components/AppButton.vue';
import Checkbox from '@/Components/Checkbox.vue';
import DataTable from '@/Components/DataTable.vue';
import DropdownMenu from '@/Components/DropdownMenu.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import SelectInput from '@/Components/SelectInput.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Toolbar from '@/Components/Toolbar.vue';
import { useConfirm } from '@/composables/useConfirm.js';
import AppLayout from '@/Layouts/AppLayout.vue';
import { date, number, relativeDays } from '@/lib/format.js';
import { visit } from '@/lib/http.js';
import { fileIcon } from '@/Pages/Directory/Partials/api.js';
import DirectoryUploadModal from '@/Pages/Directory/Partials/DirectoryUploadModal.vue';
import DocumentEditModal from '@/Pages/Directory/Partials/DocumentEditModal.vue';
import DocumentRenewModal from '@/Pages/Directory/Partials/DocumentRenewModal.vue';
import DocumentReplaceModal from '@/Pages/Directory/Partials/DocumentReplaceModal.vue';
import DocumentVersionsModal from '@/Pages/Directory/Partials/DocumentVersionsModal.vue';
import DocumentViewerModal from '@/Pages/Directory/Partials/DocumentViewerModal.vue';

const props = defineProps({
    documents: { type: Object, required: true },
    stats: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    limits: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
});

const confirm = useConfirm();
const toasts = useToasts();

const state = reactive({ search: props.filters.search ?? '', category: props.filters.category ?? '', status: props.filters.status ?? '' });
const loading = ref(false);
const selected = ref(new Map());
const modal = reactive({ upload: false, view: false, edit: false, replace: false, renew: false, versions: false });
const current = ref(null);
const viewing = ref(null);
const deleting = ref(false);

const TILES = [
    { value: 'vigente', label: 'Vigentes', key: 'valid', dot: 'bg-cap-status-ok dark:bg-cap-status-dark-ok' },
    { value: 'por_vencer', label: 'Por vencer (30 días)', key: 'expiring', dot: 'bg-cap-status-warning dark:bg-cap-status-dark-warning' },
    { value: 'vencido', label: 'Vencidos', key: 'expired', dot: 'bg-cap-status-error dark:bg-cap-status-dark-error' },
    { value: 'sin_vencimiento', label: 'Sin vencimiento', key: 'noExpiry', dot: 'bg-cap-status-neutral dark:bg-cap-status-dark-neutral' },
];

const columns = [
    { key: 'select', label: 'Elegir', width: 'w-12' },
    { key: 'name', label: 'Documento', primary: true },
    { key: 'categoryLabel', label: 'Categoría' },
    { key: 'referenceNumber', label: 'Número o referencia', hideOnMobile: true },
    { key: 'issuedAt', label: 'Emisión', width: 'w-32', format: (value) => date(value), hideOnMobile: true },
    { key: 'expiresAt', label: 'Vencimiento', width: 'w-40' },
    { key: 'status', label: 'Estado', width: 'w-36' },
];

const filtered = computed(() => Boolean(state.search || state.category || state.status));
const total = computed(() => props.stats.total ?? 0);
const selectedRows = computed(() => [...selected.value.values()]);
const selectedExpired = computed(() => selectedRows.value.filter((row) => row.status === 'Vencido'));
const description = computed(() => (total.value === 1 ? '1 documento de la empresa activa' : `${number(total.value)} documentos de la empresa activa`));

const stopStart = router.on('start', (event) => {
    if (event.detail.visit.only?.includes('documents')) loading.value = true;
});
const stopFinish = router.on('finish', () => {
    loading.value = false;
});
onBeforeUnmount(() => {
    stopStart();
    stopFinish();
});

function applyFilters() {
    const query = Object.fromEntries(Object.entries(state).filter(([, value]) => value));
    router.get('/directorio', query, { preserveState: true, preserveScroll: true, replace: true, only: ['documents', 'filters'] });
}

function setSearch(value) {
    state.search = value;
    applyFilters();
}

function setCategory(value) {
    state.category = value ?? '';
    applyFilters();
}

function toggleStatus(value) {
    state.status = state.status === value ? '' : value;
    applyFilters();
}

function clearFilters() {
    Object.assign(state, { search: '', category: '', status: '' });
    applyFilters();
}

// La selección sobrevive a la paginación; al recargar se actualizan los datos de lo elegido.
watch(
    () => props.documents.data,
    (rows) => {
        if (!selected.value.size) return;
        const next = new Map(selected.value);
        rows.forEach((row) => next.has(row.id) && next.set(row.id, row));
        selected.value = next;
    },
);

function isSelected(row) {
    return selected.value.has(row.id);
}

function toggleSelected(row, value) {
    const next = new Map(selected.value);
    if (value) next.set(row.id, row);
    else next.delete(row.id);
    selected.value = next;
}

function clearSelection() {
    selected.value = new Map();
}

function open(name, row) {
    current.value = row;
    modal[name] = true;
}

function view(target) {
    viewing.value = { id: target.id, name: target.name, fileName: target.fileName, previewUrl: target.previewUrl, downloadUrl: target.downloadUrl, mime: target.mime };
    current.value = props.documents.data.find((row) => row.id === target.id) ?? current.value;
    modal.view = true;
}

async function remove(row) {
    await confirm({
        verb: 'Eliminar',
        object: `«${row.name}»`,
        noun: 'documento',
        message: 'Se borrarán el documento y todas sus versiones del directorio. No se puede deshacer.',
        action: async () => {
            deleting.value = true;
            try {
                await visit('delete', `/documentos/${row.id}`, {}, { only: ['documents', 'stats', 'flash'] });
                toggleSelected(row, false);
                if (viewing.value?.id === row.id) modal.view = false;
            } finally {
                deleting.value = false;
            }
        },
    });
}

function actionsOf(row) {
    const items = [
        { label: 'Ver', icon: 'eye-outline', onSelect: () => view(row) },
        { label: 'Descargar', icon: 'download-outline', href: row.downloadUrl, external: true },
        { label: 'Historial de versiones', icon: 'history', onSelect: () => open('versions', row) },
    ];
    if (props.can.update) {
        items.push(
            { label: 'Renovar', icon: 'calendar-refresh-outline', separator: true, onSelect: () => open('renew', row) },
            { label: 'Reemplazar archivo', icon: 'file-replace-outline', onSelect: () => open('replace', row) },
            { label: 'Editar datos', icon: 'pencil-outline', onSelect: () => open('edit', row) },
        );
    }
    if (props.can.delete) items.push({ label: 'Eliminar', icon: 'trash-can-outline', danger: true, separator: true, onSelect: () => remove(row) });
    return items;
}

function expiryTone(row) {
    if (row.status === 'Vencido') return 'text-cap-status-error-ink';
    if (row.status === 'Por vencer') return 'text-cap-status-warning-ink';
    return 'text-cap-text-soft';
}

function packageUrl(rows) {
    const query = new URLSearchParams();
    rows.forEach((row) => query.append('ids[]', row.id));
    return `/directorio/paquete?${query.toString()}`;
}

async function downloadPackage() {
    const rows = selectedRows.value;
    const valid = rows.filter((row) => row.status !== 'Vencido');
    if (!valid.length) {
        toasts.show({ status: 'warning', title: 'Ninguno de los documentos elegidos está vigente', detail: 'Renuévalos antes de armar la oferta.' });
        return;
    }
    if (selectedExpired.value.length) {
        const names = selectedExpired.value.map((row) => row.name).join(', ');
        const accepted = await confirm({
            title: selectedExpired.value.length === 1 ? 'Un documento elegido está vencido' : `${selectedExpired.value.length} documentos elegidos están vencidos`,
            message: `No se incluirán en el ZIP: ${names}. Renuévalos antes de presentar la oferta.`,
            confirmLabel: `Descargar ${valid.length} vigente${valid.length === 1 ? '' : 's'}`,
            icon: 'alert-outline',
        });
        if (!accepted) return;
    }
    window.location.assign(packageUrl(valid));
    toasts.show({ status: 'ok', title: 'Preparando el ZIP para la oferta', detail: `${valid.length} documento${valid.length === 1 ? '' : 's'} vigente${valid.length === 1 ? '' : 's'}.` });
}
</script>

<template>
    <AppLayout title="Directorio Institucional">
        <PageHeader :description="description">
            <template #actions>
                <AppButton icon="folder-zip-outline" :disabled="!selectedRows.length" @click="downloadPackage">
                    Descargar para oferta<template v-if="selectedRows.length"> ({{ selectedRows.length }})</template>
                </AppButton>
                <AppButton v-if="can.create && (total > 0 || filtered)" variant="primary" icon="tray-arrow-up" @click="modal.upload = true">Subir documentos</AppButton>
            </template>
        </PageHeader>

        <div class="grid grid-cols-2 gap-3 md:grid-cols-4" role="group" aria-label="Filtrar por vigencia">
            <button
                v-for="tile in TILES"
                :key="tile.value"
                type="button"
                :aria-pressed="state.status === tile.value ? 'true' : 'false'"
                class="rounded-cap-card border bg-cap-card p-4 text-left transition-transform duration-cap-fast ease-cap hover:-translate-y-0.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome focus-visible:ring-offset-2 focus-visible:ring-offset-cap-page dark:focus-visible:ring-gray-100"
                :class="state.status === tile.value ? 'border-2 border-cap-brand bg-cap-card-2 dark:border-cap-brand-soft' : 'border-cap-divider'"
                @click="toggleStatus(tile.value)"
            >
                <p class="font-display text-3xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ number(stats[tile.key] ?? 0) }}</p>
                <p class="mt-1 flex items-center gap-1.5 text-xs text-cap-text-soft"><span class="h-1.5 w-1.5 shrink-0 rounded-full" :class="tile.dot" aria-hidden="true" />{{ tile.label }}</p>
            </button>
        </div>

        <div class="flex flex-col gap-3">
            <Toolbar :search="state.search" search-placeholder="Buscar documento o número" search-label="Buscar documentos" @update:search="setSearch">
                <template #filters>
                    <div class="w-full sm:w-64">
                        <SelectInput :model-value="state.category" :options="categories" placeholder="Todas las categorías" aria-label="Filtrar por categoría" @update:model-value="setCategory" />
                    </div>
                    <AppButton v-if="filtered" icon="filter-remove-outline" @click="clearFilters">Limpiar filtros</AppButton>
                </template>
            </Toolbar>

            <DataTable
                :columns="columns"
                :rows="documents.data"
                :loading="loading"
                :filtered="filtered"
                caption="Documentos del Directorio Institucional"
                clickable
                empty-icon="folder-account-outline"
                empty-title="Aún no hay documentos en el directorio"
                empty-description="Sube el RTN, las solvencias y demás documentos de la empresa para tenerlos a mano en cada oferta."
                @row-click="view"
                @clear-filters="clearFilters"
            >
                <template #cell-select="{ row }">
                    <div class="-my-2" @click.stop>
                        <Checkbox :model-value="isSelected(row)" @update:model-value="toggleSelected(row, $event)"><span class="sr-only">Elegir {{ row.name }}</span></Checkbox>
                    </div>
                </template>
                <template #cell-name="{ row }">
                    <span class="flex min-w-0 items-center gap-2">
                        <i class="mdi shrink-0 text-xl leading-none text-cap-text-soft" :class="`mdi-${fileIcon(row.mime, row.fileName)}`" aria-hidden="true" />
                        <span class="min-w-0">
                            <span class="block truncate font-medium text-cap-brand dark:text-cap-brand-soft" :title="row.name">{{ row.name }}</span>
                            <span v-if="row.version > 1 || row.signed" class="block truncate text-xs font-normal text-cap-text-soft">
                                {{ [row.version > 1 ? `Versión ${row.version}` : '', row.signed ? 'Firmado' : ''].filter(Boolean).join(' · ') }}
                            </span>
                        </span>
                    </span>
                </template>
                <template #cell-expiresAt="{ row }">
                    <span v-if="row.expiresAt" class="tabular-nums">{{ date(row.expiresAt) }}</span>
                    <span v-else>—</span>
                    <span v-if="row.expiresAt && row.status !== 'Vigente'" class="block text-xs" :class="expiryTone(row)">{{ relativeDays(row.expiresAt) }}</span>
                </template>
                <template #cell-status="{ value }"><StatusBadge :value="value" /></template>
                <template #actions="{ row }"><DropdownMenu :label="`Acciones de ${row.name}`" :items="actionsOf(row)" width="w-60" /></template>
                <template v-if="can.create" #empty-action><AppButton variant="primary" icon="tray-arrow-up" @click="modal.upload = true">Subir documentos</AppButton></template>
            </DataTable>
            <Pagination :paginator="documents" :only="['documents', 'filters']" />
        </div>

        <div v-if="selectedRows.length" class="cap-selection-bar cap-glass max-lg:hidden" role="region" aria-label="Documentos elegidos">
            <span class="cap-selection-bar__count" aria-live="polite">{{ selectedRows.length === 1 ? '1 elegido' : `${selectedRows.length} elegidos` }}</span>
            <button class="cap-button cap-button--secondary" type="button" @click="downloadPackage"><i class="mdi mdi-folder-zip-outline" aria-hidden="true" /> Descargar para oferta</button>
            <button class="cap-button cap-button--secondary" type="button" @click="clearSelection">Quitar selección</button>
        </div>

        <DirectoryUploadModal v-model:open="modal.upload" :categories="categories" :limits="limits" />
        <DocumentEditModal v-model:open="modal.edit" :document="current" :categories="categories" />
        <DocumentReplaceModal v-model:open="modal.replace" :document="current" :limits="limits" />
        <DocumentRenewModal v-model:open="modal.renew" :document="current" :limits="limits" />
        <DocumentVersionsModal v-model:open="modal.versions" :document="current" @view="view" />
        <DocumentViewerModal v-model:open="modal.view" :document="viewing" :can-delete="can.delete && !modal.versions" :deleting="deleting" @delete="remove(current)">
            <template v-if="!modal.versions" #actions>
                <AppButton icon="history" class="max-md:hidden" @click="open('versions', current)">Historial</AppButton>
                <AppButton v-if="can.update" icon="calendar-refresh-outline" @click="open('renew', current)">Renovar</AppButton>
            </template>
        </DocumentViewerModal>
    </AppLayout>
</template>
