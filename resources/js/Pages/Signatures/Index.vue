<script setup>
// «Mi firma y sello»: firma y sello personales, firmar un documento e historial de firmas aplicadas.
import { router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import CapAlert from '@design/vue/CapAlert.vue';
import CapSegmented from '@design/vue/CapSegmented.vue';
import AppButton from '@/Components/AppButton.vue';
import Checkbox from '@/Components/Checkbox.vue';
import DataTable from '@/Components/DataTable.vue';
import FileDropzone from '@/Components/FileDropzone.vue';
import FormField from '@/Components/FormField.vue';
import IconButton from '@/Components/IconButton.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { dateTime } from '@/lib/format.js';
import { card } from '@/lib/ui.js';
import AssetCard from '@/Pages/Signatures/Partials/AssetCard.vue';
import SignDocumentModal from '@/Pages/Signatures/Partials/SignDocumentModal.vue';
import { endpoints } from '@/Pages/Signatures/Partials/api.js';

const props = defineProps({
    assets: { type: Object, default: () => ({ firma: null, sello: null }) },
    history: { type: Object, default: () => ({ data: [] }) },
    lastPlacements: { type: Array, default: () => [] },
    company: { type: Object, default: () => ({ name: '', tax_id: '' }) },
    can: { type: Object, default: () => ({}) },
    limits: { type: Object, default: () => ({ imageMb: 2, pdfMb: 25 }) },
});

const heading = 'font-display text-lg font-semibold text-gray-900 dark:text-gray-100';

const source = ref(props.can.browseDocuments ? 'system' : 'upload');
const pdfFiles = ref([]);
const saveToDocuments = ref(Boolean(props.can.saveDocuments));
const documentName = ref('');
const documentId = ref(null);
const selectedDocument = ref(null);
const modalOpen = ref(false);
const signing = ref(null);
const result = ref(null);
const loadingHistory = ref(false);
let objectUrl = '';

const sourceOptions = computed(() => [
    ...(props.can.browseDocuments ? [{ label: 'Elegir del sistema', value: 'system' }] : []),
    { label: 'Subir un PDF', value: 'upload' },
]);
const hasAnyAsset = computed(() => Boolean(props.assets.firma || props.assets.sello));
const ready = computed(() => (source.value === 'upload' ? pdfFiles.value.length === 1 : Boolean(selectedDocument.value)));

const columns = [
    { key: 'document_name', label: 'Documento', primary: true, wrap: true },
    { key: 'applied_at', label: 'Fecha', format: (value) => dateTime(value), width: 'w-48' },
    { key: 'pages_label', label: 'Páginas', width: 'w-40' },
    { key: 'used', label: 'Aplicado', width: 'w-44' },
];

watch(pdfFiles, (files) => {
    if (files[0] && !documentName.value) documentName.value = files[0].name.replace(/\.pdf$/i, '');
    if (!files.length) documentName.value = '';
});

function releaseUrl() {
    if (objectUrl) URL.revokeObjectURL(objectUrl);
    objectUrl = '';
}

function openModal() {
    result.value = null;
    if (source.value === 'upload') {
        const file = pdfFiles.value[0];
        releaseUrl();
        objectUrl = URL.createObjectURL(file);
        signing.value = { id: null, name: file.name, previewUrl: objectUrl, file, saveToDocuments: saveToDocuments.value && props.can.saveDocuments, documentName: documentName.value };
    } else {
        signing.value = { id: selectedDocument.value.id, name: selectedDocument.value.name, previewUrl: selectedDocument.value.previewUrl };
    }
    modalOpen.value = true;
}

function onSigned(document, response) {
    result.value = response;
    pdfFiles.value = [];
    documentId.value = null;
    selectedDocument.value = null;
    reloadHistory();
}

function reloadHistory() {
    loadingHistory.value = true;
    router.reload({ only: ['history', 'lastPlacements'], onFinish: () => (loadingHistory.value = false) });
}

watch(modalOpen, (open) => {
    if (!open) setTimeout(releaseUrl, 500);
});

onBeforeUnmount(releaseUrl);
</script>

<template>
    <AppLayout title="Mi firma y sello">
        <PageHeader description="Tu firma y tu sello son personales: solo tú los ves y solo tú firmas con ellos." />

        <CapAlert v-if="!can.sign" status="warning" title="Tu rol no permite firmar en la empresa activa" detail="Puedes preparar tu firma y tu sello; para firmar documentos pide acceso de operador." />

        <!-- El título visible ya lo pone la cabecera; este solo nombra la sección para lectores de pantalla (B-26). -->
        <section aria-labelledby="mis-activos" class="flex flex-col gap-3">
            <h2 id="mis-activos" class="sr-only">Mi firma y mi sello</h2>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <AssetCard type="firma" :asset="assets.firma" :max-size-mb="limits.imageMb" :company="company" />
                <AssetCard type="sello" :asset="assets.sello" :max-size-mb="limits.imageMb" :company="company" />
            </div>
        </section>

        <section :class="[card, 'flex flex-col gap-4 p-4 md:p-5']" aria-labelledby="firmar-documento">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 id="firmar-documento" :class="heading">Firmar un documento</h2>
                    <p class="text-sm text-cap-text-soft">Elige el PDF, coloca la firma y el sello donde van y fírmalo. El original nunca se modifica.</p>
                </div>
                <CapSegmented v-if="sourceOptions.length > 1" v-model="source" :items="sourceOptions" label="Origen del documento" />
            </div>

            <div v-if="source === 'system'" class="max-w-xl">
                <FormField label="Documento de la empresa" help="Solo PDF de la empresa activa.">
                    <SearchSelect
                        v-model="documentId"
                        :url="endpoints.documents"
                        :min-chars="0"
                        option-value="id"
                        option-label="name"
                        option-description="description"
                        :selected="selectedDocument"
                        placeholder="Buscar por nombre…"
                        @select="(option) => (selectedDocument = option)"
                    />
                </FormField>
            </div>

            <div v-else class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <FileDropzone v-model="pdfFiles" accept=".pdf" :max-size-mb="limits.pdfMb" title="Arrastra el PDF aquí o elígelo" hint="Los escaneos también se pueden firmar." />
                <div v-if="can.saveDocuments" class="flex flex-col gap-3">
                    <Checkbox v-model="saveToDocuments" label="Guardar en los documentos de la empresa" description="Queda el original y la versión firmada. Si no, solo podrás descargarlo tú." />
                    <FormField v-if="saveToDocuments" label="Nombre del documento" optional>
                        <TextInput v-model="documentName" maxlength="255" />
                    </FormField>
                </div>
            </div>

            <CapAlert v-if="result" status="ok" title="Documento firmado" :detail="result.message" />

            <div class="flex flex-wrap items-center gap-2">
                <AppButton v-if="result" external :href="result.application.downloadUrl" icon="download-outline">Descargar PDF firmado</AppButton>
                <AppButton variant="primary" icon="draw-pen" :disabled="!ready || !can.sign" @click="openModal">Colocar firma y sello</AppButton>
                <span v-if="!hasAnyAsset && can.sign" class="text-xs text-cap-text-soft">Aún no tienes firma ni sello: podrás crearlos al colocar.</span>
            </div>
        </section>

        <section :class="[card, 'flex flex-col gap-3 p-4 md:p-5']" aria-labelledby="mis-firmas">
            <h2 id="mis-firmas" :class="heading">Mis firmas aplicadas</h2>
            <DataTable
                :columns="columns"
                :rows="history.data"
                :loading="loadingHistory"
                caption="Mis firmas aplicadas"
                empty-icon="draw-pen"
                empty-title="Aún no has firmado documentos"
                empty-description="Cada documento que firmes aparecerá aquí con su fecha y sus páginas."
            >
                <template #cell-document_name="{ row }">
                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ row.document_name }}</span>
                    <span v-if="row.loose" class="ml-2 text-xs text-cap-text-soft">PDF suelto</span>
                </template>
                <template #cell-used="{ row }">
                    <div class="flex flex-wrap gap-1">
                        <StatusBadge v-if="row.used_signature" status="info" label="Firma" />
                        <StatusBadge v-if="row.used_seal" status="neutral" label="Sello" />
                    </div>
                </template>
                <template #actions="{ row }">
                    <IconButton v-if="row.available" icon="download-outline" :label="`Descargar ${row.document_name} firmado`" :href="row.downloadUrl" external />
                </template>
            </DataTable>
            <Pagination :paginator="history" :only="['history']" :per-page-options="[]" />
        </section>

        <SignDocumentModal v-model:open="modalOpen" :document="signing" @signed="onSigned" />
    </AppLayout>
</template>
