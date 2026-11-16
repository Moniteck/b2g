<script setup>
// «Por vencer»: contratos que terminan en 30 días (o ya terminaron sin cerrarse) y documentos vencidos o
// por vencer, en dos pestañas segmentadas. Cada fila abre su registro.
// Props: expiring { contracts: [{ id, title, detail, date, progress, href }], documents: [{ id, title, detail, date, href }] }.
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CapSegmented from '@design/vue/CapSegmented.vue';
import EmptyState from '@/Components/EmptyState.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { date, daysUntil, number } from '@/lib/format.js';

const props = defineProps({
    expiring: { type: Object, default: () => ({ contracts: [], documents: [] }) },
});

const contracts = computed(() => props.expiring?.contracts ?? []);
const documents = computed(() => props.expiring?.documents ?? []);
const tab = ref(contracts.value.length || !documents.value.length ? 'contracts' : 'documents');
const tabs = computed(() => [
    { value: 'contracts', label: 'Contratos', count: contracts.value.length },
    { value: 'documents', label: 'Documentos', count: documents.value.length },
]);
const rows = computed(() => (tab.value === 'contracts' ? contracts.value : documents.value));

function badge(row) {
    const days = daysUntil(row.date);
    if (days === null) return { status: 'neutral', label: '—' };
    if (days < 0) return { status: 'error', label: `Vencido hace ${number(-days)} d` };
    if (days === 0) return { status: 'error', label: 'Vence hoy' };
    return { status: days <= 7 ? 'warning' : 'info', label: `Vence en ${number(days)} d` };
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <CapSegmented v-model="tab" :items="tabs" label="Qué vence" />
        <EmptyState
            v-if="!rows.length"
            compact
            :icon="tab === 'contracts' ? 'file-sign' : 'folder-check-outline'"
            :title="tab === 'contracts' ? 'Ningún contrato vence en 30 días' : 'Todos los documentos están vigentes'"
            :description="tab === 'contracts' ? 'Los contratos en ejecución con fecha de fin cercana aparecerán aquí.' : 'Los documentos con vencimiento en 30 días o menos aparecerán aquí.'"
        />
        <ul v-else :key="tab" v-stagger class="flex flex-col divide-y divide-cap-divider">
            <li v-for="row in rows" :key="row.id">
                <Link :href="row.href" class="flex min-h-14 items-center gap-3 rounded-cap-control px-2 py-2 transition-colors duration-cap-fast ease-cap hover:bg-cap-card-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome dark:focus-visible:ring-gray-100">
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold text-gray-900 dark:text-gray-100" :title="row.title">{{ row.title }}</span>
                        <span class="block truncate text-xs text-cap-text-soft" :title="row.detail || undefined">{{ [date(row.date, 'medium'), row.detail].filter(Boolean).join(' · ') }}</span>
                        <span v-if="row.progress !== null && row.progress !== undefined" class="mt-1 flex items-center gap-2 text-xs text-cap-text-soft">
                            <span class="h-1.5 w-24 rounded-full bg-cap-card-2"><span class="block h-full rounded-full bg-cap-taupe transition-[width] duration-cap-slow ease-cap" :style="{ width: `${Math.min(100, row.progress)}%` }" /></span>
                            {{ number(row.progress) }} % avance
                        </span>
                    </span>
                    <StatusBadge v-bind="badge(row)" />
                </Link>
            </li>
        </ul>
    </div>
</template>
