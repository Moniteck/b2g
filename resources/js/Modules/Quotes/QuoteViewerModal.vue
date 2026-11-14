<script setup>
// Modal «Ver» de una cotización: visor del PDF y, a un lado, la lista de documentos (el PDF generado
// y los PDF adjuntos). Pie: Firmar y sellar, Agregar PDF, Descargar, Eliminar (el adjunto visible;
// el PDF generado no se elimina, decisión 4) y Cerrar. En móvil: pantalla completa, visor arriba y
// la lista desplegable.
// Props: open (v-model:open), quote (fila de la lista: id, number, status, outcome, pdf, can),
//        startWithUpload (abre «Agregar PDF» al cargar). Eventos: changed (subió, firmó o eliminó).
import { computed, nextTick, ref, watch } from 'vue';
import CapAlert from '../../../design/vue/CapAlert.vue';
import { useToasts } from '../../../design/vue/composables/useToasts.js';
import AppButton from '../../Components/AppButton.vue';
import BaseModal from '../../Components/BaseModal.vue';
import PdfViewer from '../../Components/PdfViewer.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import { useConfirm } from '../../composables/useConfirm.js';
import { dateTime, money } from '../../lib/format.js';
import { deleteAttachment } from '../Documents/api.js';
import AttachmentPanel from '../Documents/AttachmentPanel.vue';
import SignDocumentModal from '../Signatures/SignDocumentModal.vue';
import { firstError, snapshotPdf } from './api.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    quote: { type: Object, default: null },
    startWithUpload: { type: Boolean, default: false },
    categories: { type: Array, default: () => ['Oferta técnica', 'Oferta económica', 'Ficha técnica', 'Garantía', 'Documento legal', 'Otro'] },
});

const emit = defineEmits(['update:open', 'changed']);

const confirm = useConfirm();
const toasts = useToasts();
const panel = ref(null);
const current = ref(null);
const listOpen = ref(false);
const preparing = ref(false);
const deleting = ref(false);
const signOpen = ref(false);
const signing = ref(null);
const version = ref(Date.now());
let pendingUpload = false;

const can = computed(() => props.quote?.can ?? {});
const generated = computed(() => ({
    kind: 'generated',
    id: null,
    name: `Cotización ${props.quote?.number ?? ''} (PDF generado)`,
    previewUrl: `/cotizaciones/${props.quote?.id}/pdf`,
    downloadUrl: `/cotizaciones/${props.quote?.id}/pdf?descargar=1`,
    mime: 'application/pdf',
}));
const isGenerated = computed(() => !current.value || current.value.kind === 'generated');
const shown = computed(() => current.value ?? generated.value);
const viewerSrc = computed(() => `${shown.value.previewUrl}${shown.value.previewUrl.includes('?') ? '&' : '?'}v=${version.value}`);
const attachmentCount = computed(() => panel.value?.items?.length ?? 0);
const canPreview = computed(() => shown.value.mime === 'application/pdf' || String(shown.value.mime).startsWith('image/'));
const canSignShown = computed(() => Boolean(can.value.sign) && shown.value.mime === 'application/pdf');

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        current.value = null;
        listOpen.value = false;
        version.value = Date.now();
        pendingUpload = props.startWithUpload;
    },
);

function onLoaded(items) {
    if (current.value?.kind === 'attachment') {
        const fresh = items.find((item) => item.id === current.value.id);
        current.value = fresh ? describe(fresh) : null;
    }
    if (pendingUpload && can.value.attach) {
        pendingUpload = false;
        nextTick(() => panel.value?.openUpload());
    }
}

function describe(item) {
    const full = panel.value?.items?.find((entry) => entry.id === item.id) ?? item;
    return { kind: 'attachment', ...item, signedBy: full.signedBy, signedAt: full.signedAt, category: full.category };
}

function show(item) {
    current.value = item ? describe(item) : null;
    listOpen.value = false;
}

function onChanged({ action }) {
    if (action === 'deleted' && current.value?.kind === 'attachment' && !panel.value?.items?.some((item) => item.id === current.value.id)) current.value = null;
    emit('changed');
}

async function sign() {
    if (!canSignShown.value || preparing.value) return;
    let document = shown.value;
    if (isGenerated.value) {
        preparing.value = true;
        try {
            const response = await snapshotPdf(props.quote.id);
            document = { kind: 'attachment', ...response.document };
            await panel.value?.reload();
            emit('changed');
        } catch (error) {
            toasts.show({ status: 'error', title: 'No se pudo preparar el PDF para firmar', detail: firstError(error) });
            return;
        } finally {
            preparing.value = false;
        }
    }
    signing.value = { id: document.id, name: document.name, previewUrl: `/firmas/documentos/${document.id}/vista` };
    current.value = describe(document);
    signOpen.value = true;
}

async function onSigned(document) {
    await panel.value?.reload();
    version.value = Date.now();
    if (document?.id) {
        const fresh = panel.value?.items?.find((item) => item.id === document.id);
        current.value = fresh ? describe(fresh) : { kind: 'attachment', id: document.id, name: document.name, previewUrl: document.previewUrl, downloadUrl: document.downloadUrl, mime: 'application/pdf', signed: true, signedBy: document.signed_by, signedAt: document.signed_at };
    }
    emit('changed');
}

async function remove() {
    if (isGenerated.value || !can.value.attach) return;
    const item = current.value;
    const accepted = await confirm({
        verb: 'Eliminar',
        object: `el adjunto «${item.name}»`,
        noun: 'adjunto',
        message: 'Se quitará de la cotización y se borrará el archivo con todas sus versiones. El PDF generado de la cotización no se toca. No se puede deshacer.',
        action: async () => {
            deleting.value = true;
            try {
                const response = await deleteAttachment('quote', props.quote.id, item.id);
                toasts.show({ status: 'ok', title: response?.message ?? 'Adjunto eliminado.' });
            } catch (error) {
                toasts.show({ status: 'error', title: 'No se pudo eliminar el adjunto', detail: error.message });
                throw error;
            } finally {
                deleting.value = false;
            }
        },
    });
    if (!accepted) return;
    current.value = null;
    await panel.value?.reload();
    emit('changed');
}

function upload() {
    panel.value?.openUpload();
}
</script>

<template>
    <BaseModal :open="open" size="full" flush :title="quote ? `Cotización ${quote.number}` : 'Cotización'" :description="quote ? `${quote.institution?.name ?? ''} · ${money(quote.total)}` : ''" :persistent="preparing || deleting" @update:open="emit('update:open', $event)">
        <div v-if="quote" class="flex h-full min-h-0 flex-col lg:flex-row" data-quote-viewer>
            <div class="flex min-h-0 min-w-0 flex-1 flex-col">
                <div class="flex flex-wrap items-center gap-2 border-b border-cap-divider px-4 py-2.5 md:px-6">
                    <i class="mdi mdi-file-pdf-box text-xl leading-none text-cap-text-soft" aria-hidden="true" />
                    <span class="min-w-0 flex-1 truncate text-sm font-semibold text-gray-900 dark:text-gray-100" :title="shown.name">{{ shown.name }}</span>
                    <StatusBadge v-if="isGenerated" status="info" label="PDF generado" />
                    <StatusBadge v-else-if="shown.signed" status="ok" label="Firmado" />
                    <StatusBadge v-else-if="shown.category" status="neutral" :label="shown.category" />
                    <button type="button" class="inline-flex items-center gap-1 rounded-cap-control px-2 py-1 text-sm font-medium text-cap-brand focus:outline-none focus-visible:ring-2 focus-visible:ring-current lg:hidden dark:text-cap-brand-soft" :aria-expanded="listOpen ? 'true' : 'false'" aria-controls="quote-documents" @click="listOpen = !listOpen">
                        Documentos ({{ attachmentCount + 1 }})
                        <i class="mdi text-xl leading-none" :class="listOpen ? 'mdi-chevron-up' : 'mdi-chevron-down'" aria-hidden="true" />
                    </button>
                </div>
                <div v-if="shown.signed" class="px-4 pt-3 md:px-6">
                    <CapAlert status="ok" title="Firmado y sellado" :detail="`Firmado por ${shown.signedBy || 'un usuario'}${shown.signedAt ? ' el ' + dateTime(shown.signedAt, 'medium') : ''}`.replace(/\.?$/, '.')" />
                </div>
                <div class="min-h-0 flex-1" :class="listOpen ? 'max-lg:hidden' : ''">
                    <PdfViewer v-if="canPreview" :key="viewerSrc" :src="viewerSrc" :name="shown.fileName || shown.name" download-url="" height="h-[calc(100dvh-15rem)] lg:h-[calc(100dvh-13rem)]" />
                    <div v-else class="p-6 text-sm text-cap-text-soft">Este archivo no tiene vista previa. Descárgalo para abrirlo.</div>
                </div>
            </div>

            <aside id="quote-documents" class="shrink-0 overflow-y-auto border-cap-divider lg:block lg:w-96 lg:border-l" :class="listOpen ? 'block border-t' : 'hidden'" aria-label="Documentos de la cotización">
                <div class="flex flex-col gap-4 p-4">
                    <button
                        type="button"
                        class="flex w-full items-center gap-3 rounded-cap-control border p-3 text-left transition-colors duration-cap-fast ease-cap hover:bg-cap-card-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome dark:focus-visible:ring-gray-100"
                        :class="isGenerated ? 'border-cap-brand bg-cap-card-2 dark:border-cap-brand-soft' : 'border-cap-divider'"
                        :aria-pressed="isGenerated ? 'true' : 'false'"
                        @click="show(null)"
                    >
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-cap-control bg-cap-card-2 text-cap-text-soft" aria-hidden="true"><i class="mdi mdi-file-document-outline text-xl leading-none" /></span>
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-semibold text-gray-900 dark:text-gray-100">PDF de la cotización</span>
                            <span class="block text-xs text-cap-text-soft">Generado con los datos actuales · no se elimina</span>
                        </span>
                    </button>
                    <AttachmentPanel ref="panel" compact entity-type="quote" :entity-id="quote.id" :can-edit="Boolean(can.attach)" :categories="categories" title="PDF adjuntos" empty-text="Agrega fichas técnicas, garantías o la oferta firmada." @view="show" @changed="onChanged" @loaded="onLoaded" />
                    <CapAlert v-if="!can.attach && quote.status !== 'Presentada'" status="info" title="Adjuntos cerrados" :detail="`Una cotización ${quote.status} ya no recibe documentos.`" />
                </div>
            </aside>
        </div>

        <template #footer>
            <div v-if="!isGenerated && can.attach" class="flex max-md:w-full md:mr-auto [&>.cap-button]:max-md:flex-1">
                <AppButton variant="danger" icon="trash-can-outline" :loading="deleting" @click="remove">Eliminar</AppButton>
            </div>
            <AppButton v-if="can.sign" icon="draw" :loading="preparing" :disabled="!canSignShown" :title="canSignShown ? '' : 'Solo se firman PDF'" @click="sign">Firmar y sellar</AppButton>
            <AppButton v-if="can.attach" icon="tray-arrow-up" @click="upload">Agregar PDF</AppButton>
            <AppButton external :href="shown.downloadUrl" icon="download-outline">Descargar</AppButton>
            <AppButton @click="emit('update:open', false)">Cerrar</AppButton>
        </template>

        <SignDocumentModal v-model:open="signOpen" :document="signing" @signed="onSigned" />
    </BaseModal>
</template>
