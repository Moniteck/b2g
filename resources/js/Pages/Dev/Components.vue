<script setup>
// Página de referencia de la biblioteca compartida (/dev/componentes): cada componente con datos de
// ejemplo, en sus estados. Sirve para revisar claro, oscuro, móvil y teclado de un vistazo.
import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import CapAlert from '@design/vue/CapAlert.vue';
import CapSegmented from '@design/vue/CapSegmented.vue';
import { useToasts } from '@design/vue/composables/useToasts.js';
import AppButton from '@/Components/AppButton.vue';
import Avatar from '@/Components/Avatar.vue';
import BaseModal from '@/Components/BaseModal.vue';
import Checkbox from '@/Components/Checkbox.vue';
import DataTable from '@/Components/DataTable.vue';
import DateInput from '@/Components/DateInput.vue';
import DateTimeInput from '@/Components/DateTimeInput.vue';
import DescriptionList from '@/Components/DescriptionList.vue';
import DropdownMenu from '@/Components/DropdownMenu.vue';
import EmptyState from '@/Components/EmptyState.vue';
import ErrorState from '@/Components/ErrorState.vue';
import FileDropzone from '@/Components/FileDropzone.vue';
import FormField from '@/Components/FormField.vue';
import IconButton from '@/Components/IconButton.vue';
import MoneyInput from '@/Components/MoneyInput.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import PdfViewer from '@/Components/PdfViewer.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import SelectInput from '@/Components/SelectInput.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Tabs from '@/Components/Tabs.vue';
import TextArea from '@/Components/TextArea.vue';
import TextInput from '@/Components/TextInput.vue';
import Timeline from '@/Components/Timeline.vue';
import Toggle from '@/Components/Toggle.vue';
import Toolbar from '@/Components/Toolbar.vue';
import Tooltip from '@/Components/Tooltip.vue';
import { useConfirm } from '@/composables/useConfirm.js';
import AppLayout from '@/Layouts/AppLayout.vue';
import CompanySwitcher from '@/Layouts/Partials/CompanySwitcher.vue';
import { date, dateTime, money, relativeDays } from '@/lib/format.js';
import { card, tableLink } from '@/lib/ui.js';

const toasts = useToasts();
const confirm = useConfirm();

const panel = [card, 'p-4 md:p-5'];
const heading = 'font-display text-lg font-semibold text-gray-900 dark:text-gray-100';
const note = 'mt-1 text-sm text-cap-text-soft';

// --- Tabla ---------------------------------------------------------------------------------
const tenders = [
    { id: 1, reference: 'LPN-SESAL-012-2026', institution: 'Secretaría de Salud', amount: 4250000, closing_date: '2026-11-18', status: 'En preparación' },
    { id: 2, reference: 'LPR-ENEE-044-2026', institution: 'Empresa Nacional de Energía Eléctrica', amount: 1830500.5, closing_date: '2026-11-14', status: 'Presentada' },
    { id: 3, reference: 'CD-IHSS-007-2026', institution: 'Instituto Hondureño de Seguridad Social', amount: 612000, closing_date: '2026-11-13', status: 'Activa' },
    { id: 4, reference: 'LPN-SEDUC-031-2026', institution: 'Secretaría de Educación', amount: 9875400, closing_date: '2026-10-30', status: 'Adjudicada' },
    { id: 5, reference: 'LPR-SANAA-019-2026', institution: 'Servicio Autónomo Nacional de Acueductos y Alcantarillados', amount: null, closing_date: '2026-10-12', status: 'Perdida' },
    { id: 6, reference: 'LPN-INSEP-003-2026', institution: 'Secretaría de Infraestructura y Transporte', amount: 22400000, closing_date: null, status: 'Borrador' },
];
const tenderColumns = [
    { key: 'reference', label: 'Proceso', sortable: true, primary: true },
    { key: 'institution', label: 'Institución', sortable: true },
    { key: 'amount', label: 'Monto (L)', align: 'right', sortable: true, format: (value) => money(value, 'HNL', { symbol: false }) },
    { key: 'closing_date', label: 'Cierre', sortable: true, format: (value) => date(value) },
    { key: 'status', label: 'Estado' },
];
const tableStates = [
    { label: 'Con datos', value: 'rows' },
    { label: 'Cargando', value: 'loading' },
    { label: 'Vacío', value: 'empty' },
    { label: 'Sin resultados', value: 'no-results' },
    { label: 'Error', value: 'error' },
];
const tableState = ref('rows');
const tableSearch = ref('');
const tableFilter = ref('all');
const selectedTender = ref(null);
const tableFilters = [
    { label: 'Todas', value: 'all', count: tenders.length },
    { label: 'Vigentes', value: 'open', count: 3 },
    { label: 'Cerradas', value: 'closed', count: 2 },
];
const tableRows = computed(() => {
    if (tableState.value !== 'rows') return [];
    const needle = tableSearch.value.trim().toLowerCase();
    return tenders.filter((row) => {
        if (tableFilter.value === 'open' && !['En preparación', 'Presentada', 'Activa'].includes(row.status)) return false;
        if (tableFilter.value === 'closed' && !['Adjudicada', 'Perdida'].includes(row.status)) return false;
        return !needle || `${row.reference} ${row.institution}`.toLowerCase().includes(needle);
    });
});
const paginator = { current_page: 2, last_page: 50, from: 26, to: 50, total: 1240, per_page: 25, prev_page_url: '#anterior', next_page_url: '#siguiente' };

function rowActions(row) {
    return [
        { label: 'Ver ficha', icon: 'eye-outline', onSelect: () => (selectedTender.value = row.id) },
        { label: 'Descargar pliego', icon: 'download-outline', onSelect: () => toasts.show({ status: 'info', title: 'Descarga iniciada', detail: row.reference }) },
        { label: 'Eliminar', icon: 'trash-can-outline', danger: true, separator: true, onSelect: () => askDelete(row) },
    ];
}

// --- Formulario ----------------------------------------------------------------------------
const form = reactive({ name: 'Suministro de repuestos', email: '', rtn: '08011999123456', password: 'secreta', notes: '', type: '', date: '2026-11-18', datetime: '2026-11-18T10:00', amount: 12345.67, usd: 980, currency: 'USD', institution: 2, contact: null, product: null, accept: true, modalities: ['lpn'], reminders: true });
const institutions = [
    { id: 1, name: 'Secretaría de Salud', acronym: 'SESAL' },
    { id: 2, name: 'Empresa Nacional de Energía Eléctrica', acronym: 'ENEE' },
    { id: 3, name: 'Instituto Hondureño de Seguridad Social', acronym: 'IHSS' },
    { id: 4, name: 'Secretaría de Educación', acronym: 'SEDUC' },
    { id: 5, name: 'Secretaría de Infraestructura y Transporte', acronym: 'SIT' },
    { id: 6, name: 'Servicio Autónomo Nacional de Acueductos y Alcantarillados', acronym: 'SANAA' },
    { id: 7, name: 'Universidad Nacional Autónoma de Honduras', acronym: 'UNAH' },
];
const inventory = ['Filtro de aceite HF-204', 'Filtro de aire AF-88', 'Batería 12 V 90 Ah', 'Llanta 11R22.5', 'Aceite 15W-40 (cubeta)', 'Pastillas de freno delanteras', 'Banda de distribución', 'Amortiguador trasero'].map((label, index) => ({ value: index + 1, label, description: `SKU BL-${1000 + index} · ${12 * (index + 1)} en existencia` }));

function searchInventory(term) {
    // Simula la búsqueda remota: con `url="/inventario/buscar"` el componente hace el GET por su cuenta.
    return new Promise((resolve) => setTimeout(() => resolve(inventory.filter((item) => item.label.toLowerCase().includes(term.toLowerCase()))), 500));
}

// --- Archivos ------------------------------------------------------------------------------
const files = ref([]);
const fakeStates = reactive(new Map());
const fakeUploading = ref(false);
let uploadTimer = null;
const fakeUploader = { stateOf: (file) => fakeStates.get(file) ?? null, uploading: fakeUploading };

function simulateUpload() {
    if (!files.value.length || fakeUploading.value) return;
    fakeUploading.value = true;
    files.value.forEach((file) => fakeStates.set(file, { status: 'uploading', progress: 0, error: '' }));
    uploadTimer = setInterval(() => {
        let pending = false;
        files.value.forEach((file, index) => {
            const state = fakeStates.get(file);
            if (!state || state.status !== 'uploading') return;
            state.progress = Math.min(100, state.progress + 10 + index * 5);
            if (state.progress >= 100) fakeStates.set(file, index === 1 ? { status: 'error', progress: 0, error: 'El servidor rechazó el archivo: el PDF está protegido con contraseña.' } : { status: 'done', progress: 100, error: '' });
            else pending = true;
        });
        if (!pending) {
            clearInterval(uploadTimer);
            fakeUploading.value = false;
        }
    }, 250);
}
onBeforeUnmount(() => clearInterval(uploadTimer));

// --- Modales -------------------------------------------------------------------------------
const modal = reactive({ sm: false, md: false, lg: false, xl: false, full: false, stacked: false, viewer: false });
const modalTab = ref('datos');
const saving = ref(false);

function fakeSave(key) {
    saving.value = true;
    setTimeout(() => {
        saving.value = false;
        modal[key] = false;
        toasts.show({ status: 'ok', title: 'Cambios guardados', detail: 'La licitación se actualizó.' });
    }, 900);
}

function askDelete(row) {
    confirm({
        verb: 'Eliminar',
        object: `la licitación ${row.reference}`,
        noun: 'licitación',
        message: 'Se borrarán también su pliego y sus recordatorios. No se puede deshacer.',
        action: () => new Promise((resolve) => setTimeout(resolve, 800)),
    }).then((accepted) => {
        if (accepted) toasts.show({ status: 'ok', title: 'Licitación eliminada', detail: row.reference });
    });
}

function askMassive() {
    confirm({ verb: 'Anular', object: 'las 12 cotizaciones seleccionadas', confirmLabel: 'Anular cotizaciones', message: 'Dejarán de ser válidas ante las instituciones. No se puede deshacer.', acknowledgeLabel: 'Entiendo que no se puede deshacer' });
}

// --- PDF de ejemplo (dos páginas, generado en el navegador) --------------------------------
function buildSamplePdf() {
    const pageText = (number) => `BT /F1 22 Tf 72 720 Td (Documento de ejemplo) Tj 0 -30 Td /F1 12 Tf (Pagina ${number} de 2 - visor de la biblioteca compartida) Tj ET\n0.7 w 72 640 468 -1 re S\nBT /F1 11 Tf 72 610 Td (La capa "page-overlay" permite colocar la firma y el sello sobre la hoja.) Tj ET`;
    const objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R 5 0 R] /Count 2 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 7 0 R >> >> /Contents 4 0 R >>',
        null,
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 7 0 R >> >> /Contents 6 0 R >>',
        null,
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];
    [1, 2].forEach((number) => {
        const stream = pageText(number);
        objects[number === 1 ? 3 : 5] = `<< /Length ${stream.length} >>\nstream\n${stream}\nendstream`;
    });
    let body = '%PDF-1.4\n';
    const offsets = objects.map((object, index) => {
        const offset = body.length;
        body += `${index + 1} 0 obj\n${object}\nendobj\n`;
        return offset;
    });
    const xref = body.length;
    body += `xref\n0 ${objects.length + 1}\n0000000000 65535 f \n${offsets.map((offset) => `${String(offset).padStart(10, '0')} 00000 n \n`).join('')}trailer\n<< /Size ${objects.length + 1} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF`;
    return URL.createObjectURL(new Blob([body], { type: 'application/pdf' }));
}
const samplePdf = buildSamplePdf();
const renderedPages = ref([]);
onBeforeUnmount(() => URL.revokeObjectURL(samplePdf));

// --- Varios --------------------------------------------------------------------------------
const tab = ref('datos');
const tabs = [
    { value: 'datos', label: 'Datos', icon: 'card-text-outline' },
    { value: 'documentos', label: 'Documentos', icon: 'file-multiple-outline', count: 4 },
    { value: 'historial', label: 'Historial', icon: 'history' },
    { value: 'bloqueada', label: 'Entregas', disabled: true },
];
const history = [
    { id: 3, title: 'Oferta presentada', meta: 'Ana Muñoz, 12 nov 2026, 9:40 a. m.', icon: 'send-outline', status: 'ok' },
    { id: 2, title: 'Pliego analizado', description: 'Se detectaron 14 requisitos y 3 fechas clave.', meta: 'Sistema, 10 nov 2026, 4:12 p. m.', icon: 'creation-outline', status: 'info' },
    { id: 1, title: 'Licitación creada', meta: 'Carlos Mejía, 8 nov 2026, 8:15 a. m.', icon: 'file-document-outline' },
];
const details = [
    { key: 'institution', label: 'Institución', value: 'Secretaría de Salud' },
    { key: 'status', label: 'Estado', value: 'En preparación' },
    { key: 'amount', label: 'Monto estimado', value: money(4250000) },
    { key: 'closing', label: 'Cierre', value: `${dateTime('2026-11-18 10:00:00', 'medium')} (${relativeDays('2026-11-18')})` },
    { key: 'contact', label: 'Contacto', value: null },
    { key: 'notes', label: 'Observaciones', value: 'Visita de campo obligatoria el 15 de noviembre. Garantía de mantenimiento de oferta del 2 %.', wide: true },
];
const companies = [
    { id: 1, name: 'Empresa de ejemplo Uno' },
    { id: 2, name: 'Empresa de ejemplo Dos, S. de R. L.' },
];
</script>

<template>
    <AppLayout title="Componentes" :breadcrumbs="[{ label: 'Desarrollo' }]">
        <PageHeader description="Biblioteca compartida: un ejemplo real de cada pieza. La guía de uso está en resources/js/Components/README.md.">
            <template #actions>
                <AppButton icon="bell-outline" @click="toasts.show({ status: 'warning', title: 'La licitación cierra mañana', detail: 'LPR-ENEE-044-2026', action: { label: 'Ver', onSelect: () => {} } })">Probar toast</AppButton>
                <AppButton variant="primary" icon="plus" @click="modal.lg = true">Nueva licitación</AppButton>
            </template>
        </PageHeader>

        <section :class="panel" aria-labelledby="dev-buttons">
            <h2 id="dev-buttons" :class="heading">Botones, avisos y estados</h2>
            <p :class="note">Un solo primario visible por pantalla (aquí es «Nueva licitación», arriba). El destructivo es de contorno; el relleno de error solo vive en la confirmación.</p>
            <div class="mt-4 flex flex-wrap items-center gap-2">
                <AppButton>Cancelar</AppButton>
                <AppButton icon="file-excel-outline">Exportar Excel</AppButton>
                <AppButton variant="danger" icon="trash-can-outline" @click="askDelete(tenders[0])">Eliminar licitación</AppButton>
                <AppButton variant="danger" @click="askMassive">Anular seleccionadas</AppButton>
                <AppButton loading>Guardando</AppButton>
                <Tooltip text="Solo un administrador puede cerrar el período."><AppButton disabled>Cerrar período</AppButton></Tooltip>
                <IconButton icon="pencil-outline" label="Editar" />
                <IconButton icon="download-outline" label="Descargar" />
                <DropdownMenu label="Acciones de LPN-SESAL-012-2026" :items="rowActions(tenders[0])" />
            </div>
            <div class="mt-4 flex flex-wrap items-center gap-2">
                <StatusBadge status="ok" label="Vigente" />
                <StatusBadge status="warning" label="Por vencer" />
                <StatusBadge status="error" label="Vencido" />
                <StatusBadge status="info" label="En proceso" />
                <StatusBadge status="neutral" label="Borrador" />
                <StatusBadge value="Adjudicada" />
                <StatusBadge value="Perdida" />
                <span class="mx-2 hidden h-5 border-l border-cap-divider sm:block" aria-hidden="true" />
                <Avatar name="Ana Muñoz" size="sm" />
                <Avatar name="Carlos Mejía" />
                <Avatar name="Grupo Operativo" initials="GO" size="lg" />
                <CompanySwitcher :companies="companies" :active="companies[0]" />
            </div>
            <div class="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-2">
                <CapAlert status="warning" title="Este documento vence en 5 días" detail="Renuévalo antes de presentar otra oferta." />
                <CapAlert status="error" title="El período ya está cerrado" detail="Abre uno nuevo para registrar cobros." />
                <CapAlert status="ok" title="Firma y sello vigentes" />
                <CapAlert status="info" title="La sincronización con Blessing corre cada hora" />
            </div>
        </section>

        <section aria-labelledby="dev-stats">
            <h2 id="dev-stats" class="mb-3" :class="heading">Cifras</h2>
            <div class="cap-stagger grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard variant="featured" label="Pipeline estimado" :count="248300000" format="currency-compact" hint="38 oportunidades activas" chip="+12 %" href="#pipeline" />
                <StatCard label="Tasa de adjudicación" :count="31" format="percent" hint="9 ganadas de 29 presentadas" href="#licitaciones" />
                <StatCard variant="alert" label="Cartera vencida" :value="money(182450.75)" hint="6 facturas con más de 30 días" href="#cobros" />
                <StatCard label="Documentos por vencer" :count="4" hint="1 ya vencido" />
            </div>
        </section>

        <section aria-labelledby="dev-table" class="flex flex-col gap-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="dev-table" :class="heading">Barra de lista, tabla y paginación</h2>
                <div class="max-w-full overflow-x-auto"><CapSegmented v-model="tableState" :items="tableStates" label="Estado de la tabla" /></div>
            </div>
            <Toolbar v-model:search="tableSearch" v-model:filter="tableFilter" :filters="tableFilters" filter-label="Filtrar licitaciones" search-placeholder="Buscar proceso o institución…">
                <template #actions>
                    <AppButton icon="file-pdf-box">PDF</AppButton>
                </template>
            </Toolbar>
            <div>
                <DataTable
                    :columns="tenderColumns"
                    :rows="tableRows"
                    caption="Licitaciones de ejemplo"
                    :loading="tableState === 'loading'"
                    :error="tableState === 'error' ? 'El servidor tardó demasiado en responder. Revisa tu conexión y vuelve a intentarlo.' : ''"
                    :filtered="tableState === 'no-results' || Boolean(tableSearch) || tableFilter !== 'all'"
                    :selected-key="selectedTender"
                    clickable
                    empty-title="Aún no hay licitaciones"
                    empty-description="Importa una captura de HonduCompras o créala a mano."
                    empty-icon="gavel"
                    @row-click="selectedTender = $event.id"
                    @retry="tableState = 'rows'"
                    @clear-filters="((tableSearch = ''), (tableFilter = 'all'), (tableState = 'rows'))"
                >
                    <template #cell-reference="{ row }"><a href="#ficha" :class="tableLink" @click.prevent="modal.md = true">{{ row.reference }}</a></template>
                    <template #cell-status="{ value }"><StatusBadge :value="value" /></template>
                    <template #actions="{ row }"><DropdownMenu :label="`Acciones de ${row.reference}`" :items="rowActions(row)" /></template>
                    <template #empty-action><AppButton icon="plus" @click="modal.lg = true">Nueva licitación</AppButton></template>
                </DataTable>
                <Pagination :paginator="paginator" />
            </div>
        </section>

        <section :class="panel" aria-labelledby="dev-form">
            <h2 id="dev-form" :class="heading">Formulario</h2>
            <p :class="note">Etiqueta encima, ayuda y error debajo. Dos columnas desde `md`; fecha y monto con ancho fijo.</p>
            <div class="mt-4 grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
                <FormField label="Nombre del proceso" required help="Como aparece en HonduCompras."><TextInput v-model="form.name" /></FormField>
                <FormField label="Correo del contacto" error="Escribe un correo válido, como nombre@institucion.gob.hn."><TextInput v-model="form.email" type="email" placeholder="nombre@institucion.gob.hn" icon="email-outline" /></FormField>
                <FormField label="RTN"><TextInput v-model="form.rtn" class="max-w-52" inputmode="numeric" maxlength="14" /></FormField>
                <FormField label="Contraseña de la conexión"><TextInput v-model="form.password" type="password" autocomplete="new-password" /></FormField>
                <FormField label="Modalidad" optional>
                    <SelectInput v-model="form.type" :options="['Licitación pública nacional', 'Licitación privada', 'Contratación directa', 'Concurso público', 'Compra por catálogo', 'Subasta inversa']" />
                </FormField>
                <FormField label="Código interno (solo lectura)"><TextInput model-value="B2G-2026-0147" readonly /></FormField>
                <FormField label="Fecha de publicación"><DateInput v-model="form.date" /></FormField>
                <FormField label="Cierre de ofertas" help="Hora de Honduras."><DateTimeInput v-model="form.datetime" /></FormField>
                <FormField label="Monto estimado"><MoneyInput v-model="form.amount" /></FormField>
                <FormField label="Precio de referencia" help="Con selector de moneda."><MoneyInput v-model="form.usd" v-model:currency="form.currency" :currencies="['HNL', 'USD']" /></FormField>
                <FormField label="Institución" help="Búsqueda local sobre la lista cargada."><SearchSelect v-model="form.institution" :options="institutions" option-value="id" option-label="name" option-description="acronym" placeholder="Buscar institución…" /></FormField>
                <FormField label="Producto de Blessing" help="Búsqueda remota: escribe «filtro»."><SearchSelect v-model="form.product" :search="searchInventory" placeholder="Buscar en el inventario…" /></FormField>
                <FormField label="Observaciones" class="md:col-span-2"><TextArea v-model="form.notes" maxlength="500" placeholder="Visitas de campo, garantías, aclaraciones…" /></FormField>
                <div class="flex flex-col">
                    <Checkbox v-model="form.accept" label="Requiere garantía de mantenimiento de oferta" description="Se agregará a la matriz documental." />
                    <Checkbox v-model="form.modalities" value="lpn" label="Avisarme de licitaciones públicas" />
                    <Checkbox v-model="form.modalities" value="cd" label="Avisarme de contrataciones directas" />
                    <Checkbox :model-value="false" label="Opción no disponible" disabled />
                </div>
                <div class="flex flex-col">
                    <Toggle v-model="form.reminders" label="Recordatorios por correo" description="Se aplican al momento, sin guardar." />
                    <Toggle :model-value="false" label="Sincronización automática" disabled />
                </div>
            </div>
        </section>

        <section :class="panel" aria-labelledby="dev-files">
            <h2 id="dev-files" :class="heading">Archivos</h2>
            <p :class="note">Arrastra dos o más archivos y pulsa «Simular subida»: el segundo falla para mostrar el error por archivo.</p>
            <div class="mt-4 grid grid-cols-1 gap-6 lg:grid-cols-2">
                <FormField label="Pliego y anexos" help="El tamaño máximo y los tipos siempre se ven en la zona.">
                    <FileDropzone v-model="files" :uploader="fakeUploader" accept=".pdf,.docx,image/*" :max-size-mb="20" multiple :max-files="5" />
                </FormField>
                <div class="flex flex-col gap-4">
                    <FormField label="Sello de la empresa" error="Sube el sello para poder firmar.">
                        <FileDropzone :model-value="[]" accept="image/png" :max-size-mb="2" title="Arrastra el sello en PNG o elígelo" />
                    </FormField>
                    <div><AppButton icon="tray-arrow-up" :disabled="!files.length" :loading="fakeUploading" @click="simulateUpload">Simular subida</AppButton></div>
                </div>
            </div>
        </section>

        <section :class="panel" aria-labelledby="dev-modals">
            <h2 id="dev-modals" :class="heading">Modales y confirmación</h2>
            <p :class="note">Cinco tamaños; en móvil siempre ocupan toda la pantalla con el pie fijo. Esc y clic fuera cierran, salvo las persistentes.</p>
            <div class="mt-4 flex flex-wrap gap-2">
                <AppButton @click="modal.sm = true">Pequeña</AppButton>
                <AppButton @click="modal.md = true">Mediana con pestañas</AppButton>
                <AppButton @click="modal.lg = true">Grande con formulario</AppButton>
                <AppButton @click="modal.xl = true">Extra grande</AppButton>
                <AppButton @click="modal.full = true">Pantalla completa</AppButton>
                <AppButton icon="file-eye-outline" @click="modal.viewer = true">Visor en modal</AppButton>
                <AppButton variant="danger" @click="askDelete(tenders[1])">Confirmación</AppButton>
            </div>
        </section>

        <section aria-labelledby="dev-viewer" class="flex flex-col gap-3">
            <h2 id="dev-viewer" :class="heading">Visor de PDF e imágenes</h2>
            <div class="overflow-hidden rounded-cap-card border border-cap-divider">
                <PdfViewer :src="samplePdf" name="documento-de-ejemplo.pdf" height="h-[32rem]" @page-rendered="renderedPages = [...renderedPages.filter((item) => item.page !== $event.page), { page: $event.page, width: $event.width, height: $event.height }]">
                    <template #page-overlay="{ page, scale }">
                        <div v-if="page === 1" class="absolute flex items-center justify-center rounded-md border-2 border-dashed border-cap-brand bg-cap-red-50/80 text-xs font-medium text-cap-red-800" :style="{ left: `${330 * scale}px`, top: `${560 * scale}px`, width: `${180 * scale}px`, height: `${70 * scale}px` }">Firma aquí</div>
                    </template>
                </PdfViewer>
            </div>
            <p class="text-xs tabular-nums text-cap-text-soft">Evento `page-rendered`: {{ renderedPages.map((item) => `pág. ${item.page} = ${item.width} × ${item.height} px`).join(' · ') || 'esperando…' }}</p>
        </section>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
            <section :class="panel" aria-labelledby="dev-tabs">
                <h2 id="dev-tabs" :class="heading">Pestañas y datos de un registro</h2>
                <Tabs v-model="tab" :tabs="tabs" label="Secciones de la ficha" class="mt-3">
                    <template #datos>
                        <DescriptionList :items="details">
                            <template #status="{ item }"><StatusBadge :value="item.value" /></template>
                        </DescriptionList>
                    </template>
                    <template #documentos><EmptyState compact icon="file-multiple-outline" title="Aún no hay documentos" description="Sube el pliego para empezar el análisis."><template #action><AppButton icon="tray-arrow-up">Subir pliego</AppButton></template></EmptyState></template>
                    <template #historial><Timeline :items="history" /></template>
                </Tabs>
            </section>

            <section :class="panel" aria-labelledby="dev-states">
                <h2 id="dev-states" :class="heading">Historial y estados</h2>
                <Timeline class="mt-4" :items="history" />
                <div class="mt-6 grid grid-cols-1 gap-3">
                    <div class="rounded-cap-control bg-cap-card-2"><EmptyState compact variant="no-results" /></div>
                    <div class="rounded-cap-control bg-cap-card-2"><ErrorState compact title="No se pudieron cargar las entregas" description="El resto de la ficha sigue disponible. Vuelve a intentarlo." code="REQ-7F3A" /></div>
                </div>
            </section>
        </div>

        <!-- Modales de ejemplo -->
        <BaseModal v-model:open="modal.sm" size="sm" title="Renovar documento" description="Constancia de solvencia fiscal">
            <div class="flex flex-col gap-4">
                <FormField label="Nueva fecha de vencimiento" required><DateInput v-model="form.date" /></FormField>
                <FormField label="Motivo" optional><TextArea v-model="form.notes" rows="3" /></FormField>
            </div>
            <template #footer>
                <AppButton @click="modal.sm = false">Cancelar</AppButton>
                <AppButton variant="primary" :loading="saving" @click="fakeSave('sm')">Renovar documento</AppButton>
            </template>
        </BaseModal>

        <BaseModal v-model:open="modal.md" title="LPN-SESAL-012-2026" description="Secretaría de Salud">
            <Tabs v-model="modalTab" :tabs="tabs.slice(0, 3)" label="Secciones de la licitación">
                <template #datos><DescriptionList :items="details"><template #status="{ item }"><StatusBadge :value="item.value" /></template></DescriptionList></template>
                <template #documentos><EmptyState compact icon="file-multiple-outline" title="Aún no hay documentos" /></template>
                <template #historial><Timeline :items="history" /></template>
            </Tabs>
            <template #footer>
                <AppButton @click="modal.md = false">Cerrar</AppButton>
                <AppButton variant="danger" @click="modal.stacked = true">Marcar como perdida</AppButton>
            </template>
        </BaseModal>

        <BaseModal v-model:open="modal.stacked" size="sm" title="Marcar como perdida" description="Esta modal se abrió encima de la anterior.">
            <FormField label="Motivo" required help="Queda en el historial de la oportunidad."><SearchSelect v-model="form.contact" :options="['Precio', 'Incumplimiento documental', 'Plazo de entrega', 'Especificación técnica', 'Proceso declarado desierto'].map((label) => ({ value: label, label }))" placeholder="Elegir motivo…" /></FormField>
            <template #footer>
                <AppButton @click="modal.stacked = false">Cancelar</AppButton>
                <AppButton variant="danger" @click="askDelete(tenders[0])">Marcar como perdida</AppButton>
            </template>
        </BaseModal>

        <BaseModal v-model:open="modal.lg" as="form" size="lg" title="Nueva licitación" description="Los datos se pueden completar después con el análisis del pliego." :persistent="saving" @submit="fakeSave('lg')">
            <div class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
                <FormField label="Número de proceso" required><TextInput v-model="form.rtn" /></FormField>
                <FormField label="Institución" required><SearchSelect v-model="form.institution" :options="institutions" option-value="id" option-label="name" option-description="acronym" /></FormField>
                <FormField label="Título" required class="md:col-span-2"><TextInput v-model="form.name" /></FormField>
                <FormField label="Cierre de ofertas"><DateTimeInput v-model="form.datetime" /></FormField>
                <FormField label="Monto estimado"><MoneyInput v-model="form.amount" /></FormField>
                <FormField label="Pliego" class="md:col-span-2"><FileDropzone v-model="files" accept=".pdf" :max-size-mb="40" /></FormField>
                <FormField label="Observaciones" class="md:col-span-2"><TextArea v-model="form.notes" /></FormField>
            </div>
            <template #footer>
                <AppButton :disabled="saving" @click="modal.lg = false">Cancelar</AppButton>
                <AppButton variant="primary" type="submit" :loading="saving">Crear licitación</AppButton>
            </template>
        </BaseModal>

        <BaseModal v-model:open="modal.xl" size="xl" title="Revisión del análisis" description="Requisitos detectados en el pliego">
            <DataTable :columns="tenderColumns" :rows="tenders" caption="Requisitos" dense>
                <template #cell-status="{ value }"><StatusBadge :value="value" /></template>
            </DataTable>
            <template #footer><AppButton @click="modal.xl = false">Cerrar</AppButton></template>
        </BaseModal>

        <BaseModal v-model:open="modal.full" size="full" title="Firmar y sellar" description="Arrastra la firma al lugar que corresponda." flush>
            <PdfViewer :src="samplePdf" name="documento-de-ejemplo.pdf" height="h-full" />
            <template #footer>
                <AppButton @click="modal.full = false">Cancelar</AppButton>
                <AppButton variant="primary" @click="modal.full = false">Firmar documento</AppButton>
            </template>
        </BaseModal>

        <BaseModal v-model:open="modal.viewer" size="xl" title="documento-de-ejemplo.pdf" flush>
            <PdfViewer :src="samplePdf" name="documento-de-ejemplo.pdf" height="h-[70vh] max-md:h-full" />
        </BaseModal>
    </AppLayout>
</template>
