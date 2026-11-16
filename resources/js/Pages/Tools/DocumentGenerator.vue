<script setup>
// Generador de documentos empresariales con IA y el membrete de la empresa activa, con el historial de
// los documentos generados y su PDF. Requiere `generate_documents`.
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import AppButton from '@/Components/AppButton.vue';
import BaseModal from '@/Components/BaseModal.vue';
import DataTable from '@/Components/DataTable.vue';
import DropdownMenu from '@/Components/DropdownMenu.vue';
import FormField from '@/Components/FormField.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextArea from '@/Components/TextArea.vue';
import TextInput from '@/Components/TextInput.vue';
import Toolbar from '@/Components/Toolbar.vue';
import CapAlert from '@design/vue/CapAlert.vue';
import { useToasts } from '@design/vue/composables/useToasts.js';
import { useConfirm } from '@/composables/useConfirm.js';
import AppLayout from '@/Layouts/AppLayout.vue';
import { focusFirstError } from '@/lib/errors.js';
import { dateTime } from '@/lib/format.js';
import { HttpError, postJson, visit } from '@/lib/http.js';
import { formatMessage, plainText } from '@/Pages/Tools/Partials/formatMessage.js';
import { useListFilters } from '@/Pages/Tools/Partials/useListFilters.js';

const props = defineProps({
    records: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    types: { type: Array, default: () => [] },
    company: { type: Object, default: () => ({}) },
    notice: { type: String, default: '' },
    can: { type: Object, default: () => ({}) },
});

const ONLY = ['records', 'filters'];
const { filters, loading, error, active, reset, reload } = useListFilters('/generador-documentos', props.filters, { only: ONLY, defaults: { search: '', type: '' } });

const ICONS = { carta_presentacion: 'card-account-details-outline', carta_interes: 'hand-pointing-right', carta_compromiso: 'truck-check-outline', declaracion_jurada: 'file-certificate-outline', oficio_seguimiento: 'email-fast-outline' };

const toasts = useToasts();
const confirm = useConfirm();
const generating = ref(false);
const sending = ref(false);
const failure = ref('');
const errors = ref({});
const result = ref(null);
const viewing = ref(null);
const formBody = ref(null);
const form = reactive({ type: '', recipient: '', contact: '', context: '' });

const total = computed(() => props.records.total ?? props.records.data.length);
const typeOptions = computed(() => props.types.map((type) => ({ value: type.value, label: type.label })));
const selected = computed(() => props.types.find((type) => type.value === form.type));
const viewingOpen = computed({ get: () => viewing.value !== null, set: (value) => !value && (viewing.value = null) });
const missingCompany = computed(() => !props.company?.tax_id);

const columns = computed(() => [
    { key: 'title', label: 'Documento', primary: true, wrap: true },
    { key: 'recipient', label: 'Destinatario', wrap: true, hideOnMobile: true },
    ...(props.can.seesAll ? [{ key: 'author', label: 'Autor', hideOnMobile: true }] : []),
    { key: 'created_at', label: 'Generado', format: (value) => dateTime(value) },
]);

function open(type = '') {
    Object.assign(form, { type, recipient: '', contact: '', context: '' });
    errors.value = {};
    failure.value = '';
    result.value = null;
    generating.value = true;
}

async function generate() {
    sending.value = true;
    failure.value = '';
    errors.value = {};
    try {
        const response = await postJson('/generador-documentos', { ...form });
        result.value = response.document;
        router.reload({ only: ONLY });
    } catch (problem) {
        errors.value = Object.fromEntries(Object.entries(problem.errors ?? {}).map(([key, messages]) => [key, messages[0]]));
        if (Object.keys(errors.value).length) setTimeout(() => focusFirstError(formBody.value));
        else failure.value = problem instanceof HttpError && problem.status === 429 ? 'Generaste varios documentos seguidos. Espera un minuto y vuelve a intentarlo.' : problem.message;
    } finally {
        sending.value = false;
    }
}

async function copy(content) {
    try {
        await navigator.clipboard.writeText(plainText(content));
        toasts.show({ status: 'ok', title: 'Texto copiado', detail: 'Pégalo en tu procesador de texto para ajustarlo.' });
    } catch {
        toasts.show({ status: 'error', title: 'No se pudo copiar', detail: 'Selecciona el texto y cópialo a mano.' });
    }
}

function remove(row) {
    confirm({
        verb: 'Eliminar',
        object: `«${row.title}» del historial`,
        noun: 'documento',
        message: 'Se borrará el documento generado; los PDF ya descargados no se ven afectados. No se puede deshacer.',
        icon: 'trash-can-outline',
        action: () => visit('delete', `/generador-documentos/${row.id}`, {}, { only: [...ONLY, 'flash'], onSuccess: () => (viewing.value = null) }),
    });
}

function actionsOf(row) {
    const items = [
        { label: 'Ver documento', icon: 'eye-outline', onSelect: () => (viewing.value = row) },
        { label: 'Descargar PDF', icon: 'file-pdf-box', href: row.pdf_url, external: true },
        { label: 'Copiar texto', icon: 'content-copy', onSelect: () => copy(row.content) },
    ];
    if (props.can.delete) items.push({ label: 'Eliminar', icon: 'trash-can-outline', danger: true, separator: true, onSelect: () => remove(row) });
    return items;
}
</script>

<template>
    <AppLayout title="Generador de documentos" :fab-label="can.generate ? 'Generar documento' : ''" @fab="open()">
        <PageHeader :description="`Borradores con el membrete de ${company.name ?? 'la empresa activa'}.`">
            <template #actions>
                <AppButton v-if="can.generate && total" variant="primary" icon="file-document-plus-outline" @click="open()">Generar documento</AppButton>
            </template>
        </PageHeader>

        <CapAlert v-if="missingCompany" status="warning" title="Faltan datos de la empresa" detail="El RTN y la dirección de la empresa activa se toman de Configuración; complétalos para que el membrete salga completo." />

        <section v-if="can.generate">
            <h2 class="mb-3 font-display text-base font-semibold text-gray-900 dark:text-gray-100">¿Qué necesitas redactar?</h2>
            <div v-stagger class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5">
                <button v-for="type in types" :key="type.value" type="button" class="flex h-full flex-col gap-2 rounded-cap-card border border-cap-divider bg-cap-card p-4 text-left transition-transform duration-cap-fast ease-cap hover:-translate-y-0.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome focus-visible:ring-offset-2 focus-visible:ring-offset-cap-card dark:focus-visible:ring-gray-100" @click="open(type.value)">
                    <span class="flex h-9 w-9 items-center justify-center rounded-full bg-cap-red-50 text-cap-brand dark:bg-cap-red-600/15 dark:text-cap-brand-soft" aria-hidden="true"><i class="mdi text-xl leading-none" :class="`mdi-${ICONS[type.value] ?? 'file-document-outline'}`" /></span>
                    <span class="font-display text-sm font-semibold text-gray-900 dark:text-gray-100">{{ type.label }}</span>
                    <span class="text-xs text-cap-text-soft">{{ type.description }}</span>
                </button>
            </div>
        </section>

        <section class="flex flex-col gap-4">
            <h2 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">{{ can.seesAll ? 'Documentos generados en la empresa' : 'Tus documentos generados' }}</h2>
            <Toolbar v-model:search="filters.search" search-placeholder="Buscar por título o destinatario">
                <template #filters><SelectInput v-model="filters.type" class="sm:w-64" :options="typeOptions" placeholder="Todos los tipos" aria-label="Filtrar por tipo" /></template>
            </Toolbar>
            <div>
                <DataTable
                    :columns="columns"
                    :rows="records.data"
                    :loading="loading"
                    :error="error"
                    :filtered="active"
                    caption="Documentos generados"
                    clickable
                    empty-icon="file-document-edit-outline"
                    empty-title="Aún no has generado documentos"
                    empty-description="Elige un tipo de documento arriba para redactar el primer borrador."
                    @row-click="(row) => (viewing = row)"
                    @retry="reload"
                    @clear-filters="reset"
                >
                    <template #cell-title="{ row }">
                        <div class="min-w-0">
                            <p class="font-medium text-gray-900 dark:text-gray-100">{{ row.type_label }}</p>
                            <p class="truncate text-xs text-cap-text-soft" :title="row.recipient">{{ row.recipient }}</p>
                        </div>
                    </template>
                    <template #actions="{ row }"><DropdownMenu :label="`Acciones de ${row.title}`" :items="actionsOf(row)" /></template>
                    <template #empty-action><AppButton v-if="can.generate" variant="primary" icon="file-document-plus-outline" @click="open()">Generar documento</AppButton></template>
                </DataTable>
                <Pagination :paginator="records" :only="ONLY" />
            </div>
        </section>

        <BaseModal v-model:open="generating" :as="result ? 'div' : 'form'" size="xl" :title="result ? result.type_label : 'Generar documento'" :description="result ? `Para ${result.recipient}` : company.name" :persistent="sending" @submit="generate">
            <div v-if="!result" ref="formBody" class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
                <FormField label="Tipo de documento" required :error="errors.type" :help="selected?.description" class="md:col-span-2"><SelectInput v-model="form.type" :options="typeOptions" placeholder="Elige el tipo de documento" /></FormField>
                <template v-if="selected">
                    <FormField :label="selected.recipient" required :error="errors.recipient"><TextInput v-model="form.recipient" maxlength="255" placeholder="Secretaría de Salud" /></FormField>
                    <FormField label="Persona de contacto" optional :error="errors.contact"><TextInput v-model="form.contact" maxlength="255" placeholder="Lic. Ana Martínez, Jefa de Compras" /></FormField>
                    <FormField :label="selected.context" required :help="selected.hint" :error="errors.context" class="md:col-span-2"><TextArea v-model="form.context" rows="7" maxlength="5000" /></FormField>
                </template>
                <CapAlert v-if="failure" class="md:col-span-2" status="error" title="No se pudo generar el documento" :detail="failure" />
            </div>
            <div v-else class="flex flex-col gap-4">
                <CapAlert status="warning" title="Revisa el borrador" :detail="notice" />
                <!-- formatMessage escapa todo el HTML antes de dar formato: el texto de la IA es dato. -->
                <div class="formatted-text break-words rounded-cap-control bg-cap-card-2 p-4 text-sm leading-relaxed text-gray-900 dark:text-gray-100" v-html="formatMessage(result.content)" />
            </div>
            <template #footer>
                <template v-if="!result">
                    <AppButton :disabled="sending" @click="generating = false">Cancelar</AppButton>
                    <AppButton variant="primary" type="submit" icon="creation-outline" :loading="sending" :disabled="!form.type">{{ sending ? 'Redactando…' : 'Generar borrador' }}</AppButton>
                </template>
                <template v-else>
                    <AppButton class="mr-auto" @click="generating = false">Cerrar</AppButton>
                    <AppButton icon="content-copy" @click="copy(result.content)">Copiar</AppButton>
                    <AppButton variant="primary" external :href="result.pdf_url" icon="file-pdf-box">Descargar PDF</AppButton>
                </template>
            </template>
        </BaseModal>

        <BaseModal v-model:open="viewingOpen" size="xl" :title="viewing?.type_label ?? ''" :description="viewing ? `Para ${viewing.recipient} · ${dateTime(viewing.created_at)}${viewing.author ? ` · ${viewing.author}` : ''}` : ''">
            <div class="formatted-text break-words rounded-cap-control bg-cap-card-2 p-4 text-sm leading-relaxed text-gray-900 dark:text-gray-100" v-html="formatMessage(viewing?.content)" />
            <template #footer>
                <AppButton v-if="can.delete" variant="danger" icon="trash-can-outline" class="mr-auto" @click="remove(viewing)">Eliminar</AppButton>
                <AppButton icon="content-copy" @click="copy(viewing.content)">Copiar</AppButton>
                <AppButton variant="primary" external :href="viewing?.pdf_url" icon="file-pdf-box">Descargar PDF</AppButton>
            </template>
        </BaseModal>
    </AppLayout>
</template>

<style scoped>
.formatted-text :deep(p + p),
.formatted-text :deep(p + ul),
.formatted-text :deep(p + ol),
.formatted-text :deep(ul + p),
.formatted-text :deep(ol + p) {
    margin-top: 0.75rem;
}
.formatted-text :deep(ul) {
    list-style: disc;
    padding-left: 1.25rem;
}
.formatted-text :deep(ol) {
    list-style: decimal;
    padding-left: 1.25rem;
}
</style>
