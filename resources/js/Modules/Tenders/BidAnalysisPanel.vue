<script setup>
// Pestaña «Análisis del pliego» de la ficha: subir el PDF, seguir el estado del análisis en cola,
// reintentar si falló, abrir la revisión y pedir una recomendación a la IA.
// El sondeo del estado espera cada vez más (2 s → 20 s) y se pausa con la pestaña oculta
// (hallazgo de rendimiento); al volver a la pestaña consulta enseguida.
// `uploadSignal` (número): cada vez que cambia, abre la subida (botón «Importar pliego» de la ficha).
import { router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import CapAlert from '@design/vue/CapAlert.vue';
import AppButton from '@/Components/AppButton.vue';
import BaseModal from '@/Components/BaseModal.vue';
import EmptyState from '@/Components/EmptyState.vue';
import FileDropzone from '@/Components/FileDropzone.vue';
import FormField from '@/Components/FormField.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TextArea from '@/Components/TextArea.vue';
import { useUpload } from '@/composables/useUpload.js';
import { dateTime, number } from '@/lib/format.js';
import { getJson, postJson, visit } from '@/lib/http.js';
import { card } from '@/lib/ui.js';
import { ANALYSIS_STATUS, createPoller } from '@/Modules/Tenders/tenders.js';

const props = defineProps({
    tender: { type: Object, required: true },
    analyses: { type: Array, default: () => [] },
    ai: { type: Object, default: () => ({ configured: false }) },
    maxPdfMb: { type: Number, default: 40 },
    maxPdfPages: { type: Number, default: 300 },
    can: { type: Object, default: () => ({}) },
    uploadSignal: { type: Number, default: 0 },
});

const uploadOpen = ref(false);
const files = ref([]);
const uploader = useUpload();
const live = ref({});
const retrying = ref(null);
const recommendation = ref('');
const recommendationError = ref('');
const recommending = ref(false);
const context = ref('');

const items = computed(() => props.analyses.map((analysis) => ({ ...analysis, ...(live.value[analysis.id] ?? {}) })));
const pending = computed(() => items.value.filter((analysis) => analysis.pending));
const latest = computed(() => items.value[0] ?? null);

function openUpload() {
    files.value = [];
    uploader.reset();
    uploadOpen.value = true;
}

async function send() {
    const done = await uploader.upload(`/licitaciones/${props.tender.id}/pliego`, files.value, { field: 'file' });
    if (done) uploadOpen.value = false;
}

async function poll() {
    let changed = false;
    for (const analysis of pending.value) {
        try {
            const fresh = await getJson(analysis.statusUrl);
            if (fresh.status !== analysis.status) changed = true;
            live.value = { ...live.value, [analysis.id]: fresh };
        } catch {
            // Un fallo de red puntual no detiene el sondeo.
        }
    }
    // Terminado: se recarga lo que depende del análisis (siguiente paso, stepper, fechas).
    if (changed) router.reload({ only: ['analyses', 'analysisState', 'flow', 'tender', 'matrices'] });
    return pending.value.length > 0;
}

const poller = createPoller(poll, { start: 2000, max: 20000, factor: 1.6 });

watch(() => props.analyses, () => {
    live.value = {};
    if (pending.value.length) poller.start();
    else poller.stop();
}, { immediate: true });

watch(() => props.uploadSignal, (value) => {
    if (value && props.can.useAi && props.ai.configured) openUpload();
});

onBeforeUnmount(() => poller.stop());

async function retry(analysis) {
    retrying.value = analysis.id;
    try {
        await visit('post', analysis.retryUrl, {}, { only: ['analyses', 'flash', 'errors'] });
    } catch {
        // El error ya se muestra con el toast del servidor.
    } finally {
        retrying.value = null;
    }
}

async function recommend() {
    recommending.value = true;
    recommendationError.value = '';
    try {
        const response = await postJson(`/licitaciones/${props.tender.id}/recomendacion`, { context: context.value || null });
        recommendation.value = response.analysis;
    } catch (error) {
        recommendationError.value = error.message;
    } finally {
        recommending.value = false;
    }
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <CapAlert status="info" title="El pliego se envía al proveedor de IA (OpenAI)" detail="Los pliegos son documentos públicos. Cada dato vuelve con su cita y su página, y el sistema comprueba que la cita exista en el PDF. Nada se aplica a la licitación sin que lo confirmes." />

        <CapAlert v-if="!ai.configured" status="warning" title="El análisis con IA no está configurado" detail="Falta la clave de OpenAI en el servidor. Puedes completar las fechas y datos a mano con «Editar»." />

        <section :class="card" class="p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Pliego de condiciones</h3>
                    <p class="mt-1 text-sm text-cap-text-soft">PDF de hasta {{ maxPdfMb }} MB y {{ maxPdfPages }} páginas. El análisis corre en segundo plano y puede tardar unos minutos.</p>
                </div>
                <AppButton v-if="can.useAi && ai.configured" :variant="latest ? 'secondary' : 'primary'" icon="file-upload-outline" @click="openUpload">{{ latest ? 'Analizar otro pliego' : 'Subir pliego' }}</AppButton>
            </div>

            <div class="mt-4">
                <EmptyState v-if="items.length === 0" compact icon="file-search-outline" title="Aún no se ha analizado un pliego" :description="can.useAi ? 'Sube el PDF para extraer fechas, garantías, documentos y productos.' : 'Tu rol no permite usar el análisis con IA.'" />

                <ol v-stagger v-else class="flex flex-col gap-3">
                    <li v-for="analysis in items" :key="analysis.id" class="rounded-cap-control bg-cap-card-2 p-3" :data-analysis-status="analysis.status">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="flex min-w-0 items-center gap-3">
                                <StatusBadge :status="ANALYSIS_STATUS[analysis.status]?.status ?? 'neutral'" :label="analysis.statusLabel" />
                                <span class="text-sm text-gray-900 dark:text-gray-100">Análisis #{{ analysis.id }}</span>
                                <span class="text-xs text-cap-text-soft">{{ dateTime(analysis.createdAt) }}</span>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <AppButton v-if="analysis.status === 'review'" variant="primary" icon="clipboard-check-outline" :href="analysis.reviewUrl">Revisar datos</AppButton>
                                <AppButton v-else-if="analysis.status === 'confirmed'" icon="eye-outline" :href="analysis.reviewUrl">Ver revisión</AppButton>
                                <AppButton v-if="analysis.status === 'failed' && can.useAi" icon="refresh" :loading="retrying === analysis.id" @click="retry(analysis)">Reintentar</AppButton>
                            </div>
                        </div>
                        <p v-if="analysis.pending" class="mt-2 flex items-center gap-2 text-xs text-cap-text-soft">
                            <span class="cap-skeleton inline-block h-2 w-24" aria-hidden="true"></span>
                            {{ analysis.status === 'queued' ? 'Esperando turno en la cola… Puedes salir de esta pantalla: el análisis sigue.' : 'Leyendo el PDF y consultando a la IA…' }}<span v-if="analysis.attempts > 1"> (intento {{ analysis.attempts }})</span>
                        </p>
                        <p v-if="analysis.status === 'failed'" class="mt-2 text-sm text-cap-status-error-ink" role="alert">{{ analysis.error }}</p>
                        <p v-if="analysis.tokens" class="mt-2 text-xs text-cap-text-soft">
                            {{ analysis.pages }} páginas · {{ number(analysis.tokens) }} tokens · {{ analysis.seconds }} s<span v-if="analysis.confidence !== null"> · {{ analysis.confidence }} % de datos con evidencia</span>
                        </p>
                    </li>
                </ol>
            </div>
        </section>

        <section v-if="can.useAi && ai.configured" :class="card" class="p-5">
            <h3 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Recomendación de la IA</h3>
            <p class="mt-1 text-sm text-cap-text-soft">Oportunidades, riesgos, documentos y próximos pasos según los datos de la licitación y del pliego analizado.</p>
            <FormField label="Contexto adicional" optional class="mt-4" help="Por ejemplo: experiencia de la empresa con esta institución o productos que manejamos.">
                <TextArea v-model="context" :rows="2" maxlength="4000" />
            </FormField>
            <div class="mt-3">
                <AppButton icon="creation-outline" :loading="recommending" @click="recommend">Pedir recomendación</AppButton>
            </div>
            <CapAlert v-if="recommendationError" class="mt-4" status="error" title="No se pudo obtener la recomendación" :detail="recommendationError" />
            <div v-if="recommendation" class="mt-4 whitespace-pre-line rounded-cap-control bg-cap-card-2 p-3 text-sm text-gray-800 dark:text-gray-200">{{ recommendation }}</div>
        </section>

        <BaseModal v-model:open="uploadOpen" size="md" title="Subir pliego de condiciones" :persistent="uploader.uploading.value">
            <div class="flex flex-col gap-4">
                <FileDropzone v-model="files" :uploader="uploader" accept=".pdf" :max-size-mb="maxPdfMb" title="Arrastra el PDF del pliego o elígelo" />
                <CapAlert status="info" title="Tope de páginas" :detail="`El análisis con IA lee hasta ${maxPdfPages} páginas. Si el pliego es más largo, sube solo la parte con los datos del proceso (fechas, garantías, documentos y productos).`" />
                <p class="text-xs text-cap-text-soft">Se guarda como documento de la licitación y se envía a OpenAI para extraer los datos.</p>
            </div>
            <template #footer>
                <AppButton :disabled="uploader.uploading.value" @click="uploadOpen = false">Cancelar</AppButton>
                <AppButton variant="primary" icon="creation-outline" :disabled="!files.length" :loading="uploader.uploading.value" @click="send">Subir y analizar</AppButton>
            </template>
        </BaseModal>
    </div>
</template>
