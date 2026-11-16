<script setup>
// Generador de escritos del Agente Legal en tres pasos dentro de la misma modal:
//   1. Tipo de escrito (tarjetas)  2. Datos del caso  3. Borrador en una hoja (copiar o exportar a PDF).
// El borrador no se guarda en el servidor. Mientras se redacta, Lici escribe sobre una hoja.
import { computed, reactive, ref, watch } from 'vue';
import CapAlert from '@design/vue/CapAlert.vue';
import { useToasts } from '@design/vue/composables/useToasts.js';
import AppButton from '@/Components/AppButton.vue';
import BaseModal from '@/Components/BaseModal.vue';
import FormField from '@/Components/FormField.vue';
import TextArea from '@/Components/TextArea.vue';
import TextInput from '@/Components/TextInput.vue';
import { focusFirstError } from '@/lib/errors.js';
import { date } from '@/lib/format.js';
import { postJson } from '@/lib/http.js';
import Mascot from '@/Modules/Auth/Mascot.vue';
import { formatMessage, plainText } from '@/Pages/Tools/Partials/formatMessage.js';
import { downloadPdf } from '@/Pages/Tools/Partials/requests.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    types: { type: Array, default: () => [] },
    companyName: { type: String, default: '' },
    disclaimer: { type: String, default: '' },
});
const emit = defineEmits(['update:open']);

const ICONS = {
    impugnacion: 'gavel',
    recurso_administrativo: 'scale-balance',
    gestion_cobro: 'cash-clock',
    solicitud_aclaracion: 'comment-question-outline',
    modificacion_plazo: 'calendar-clock',
    respuesta_requerimiento: 'file-document-check-outline',
};
const STEPS = [
    { value: 1, label: 'Tipo' },
    { value: 2, label: 'Datos del caso' },
    { value: 3, label: 'Borrador' },
];

const toasts = useToasts();
const body = ref(null);
const step = ref(1);
const sending = ref(false);
const exporting = ref(false);
const failure = ref('');
const errors = ref({});
const result = ref(null);
const form = reactive({ type: '', company: '', recipient: '', process: '', context: '' });

const selectedType = computed(() => props.types.find((type) => type.value === form.type));
const visible = computed({ get: () => props.open, set: (value) => emit('update:open', value) });
const today = computed(() => date(new Date(), 'long'));

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        failure.value = '';
        errors.value = {};
        if (!form.company) form.company = props.companyName;
        if (!result.value) step.value = form.type ? 2 : 1;
    },
);

function chooseType(value) {
    form.type = value;
    errors.value = {};
    step.value = 2;
}

function goTo(target) {
    if (sending.value) return;
    if (target === 3 && !result.value) return;
    if (target >= 2 && !form.type) return;
    step.value = target;
}

async function generate() {
    if (step.value !== 2) return;
    sending.value = true;
    failure.value = '';
    errors.value = {};
    step.value = 3;
    try {
        result.value = await postJson('/agente-legal/escritos', { ...form });
    } catch (problem) {
        errors.value = Object.fromEntries(Object.entries(problem.errors ?? {}).map(([key, messages]) => [key, messages[0]]));
        failure.value = Object.keys(errors.value).length ? '' : problem.status === 429 ? `Lici necesita un respiro: ${problem.message}` : problem.message;
        step.value = errors.value.type ? 1 : 2;
        if (Object.keys(errors.value).length) setTimeout(() => focusFirstError(body.value));
    } finally {
        sending.value = false;
    }
}

function restart() {
    result.value = null;
    step.value = 2;
}

async function copy() {
    try {
        await navigator.clipboard.writeText(plainText(result.value.content));
        toasts.show({ status: 'ok', title: 'Borrador copiado', detail: 'Pégalo en tu procesador de texto para revisarlo.' });
    } catch {
        toasts.show({ status: 'error', title: 'No se pudo copiar', detail: 'Selecciona el texto y cópialo a mano.' });
    }
}

async function exportPdf() {
    exporting.value = true;
    try {
        await downloadPdf('/agente-legal/escritos/pdf', { type: form.type, content: result.value.content }, `${result.value.title}.pdf`);
    } catch (problem) {
        toasts.show({ status: 'error', title: 'No se pudo exportar el PDF', detail: problem.message });
    } finally {
        exporting.value = false;
    }
}
</script>

<template>
    <BaseModal
        v-model:open="visible"
        :as="step === 2 ? 'form' : 'div'"
        size="xl"
        title="Redactar escrito"
        :description="step === 3 && result ? 'Borrador para revisión profesional' : 'Lici prepara un borrador a partir de los hechos del caso.'"
        :persistent="sending"
        @submit="generate"
    >
        <div ref="body" class="flex flex-col gap-5">
            <ol class="flex items-center gap-2" aria-label="Pasos">
                <li v-for="(item, index) in STEPS" :key="item.value" class="flex min-w-0 flex-1 items-center gap-2">
                    <button
                        type="button"
                        class="flex min-w-0 items-center gap-2 rounded-full py-1 pr-2 text-left text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome disabled:cursor-default dark:focus-visible:ring-gray-100"
                        :aria-current="step === item.value ? 'step' : undefined"
                        :disabled="sending || (item.value === 3 && !result) || (item.value === 2 && !form.type)"
                        @click="goTo(item.value)"
                    >
                        <span
                            class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold transition-colors duration-cap-fast ease-cap"
                            :class="step === item.value ? 'bg-cap-chrome text-cap-on-chrome dark:bg-gray-100 dark:text-gray-900' : step > item.value ? 'bg-cap-red-50 text-cap-brand dark:bg-cap-red-600/15 dark:text-cap-brand-soft' : 'border border-cap-divider text-cap-text-soft'"
                        >
                            <i v-if="step > item.value" class="mdi mdi-check text-sm leading-none" aria-hidden="true" />
                            <template v-else>{{ item.value }}</template>
                        </span>
                        <span class="truncate" :class="step === item.value ? 'font-semibold text-gray-900 dark:text-gray-100' : 'text-cap-text-soft max-sm:sr-only'">{{ item.label }}</span>
                    </button>
                    <span v-if="index < STEPS.length - 1" class="h-px min-w-4 flex-1 bg-cap-divider" aria-hidden="true" />
                </li>
            </ol>

            <!-- 1. Tipo -->
            <ul v-if="step === 1" class="grid grid-cols-1 gap-2.5 sm:grid-cols-2" aria-label="Tipo de escrito" data-writing-step="1">
                <li v-for="type in types" :key="type.value">
                    <button
                        type="button"
                        class="flex h-full w-full items-start gap-3 rounded-cap-card border p-3.5 text-left transition duration-cap-fast ease-cap hover:-translate-y-0.5 hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome dark:focus-visible:ring-gray-100"
                        :class="form.type === type.value ? 'border-cap-chrome bg-cap-card-2 dark:border-gray-100' : 'border-cap-divider bg-cap-card'"
                        :aria-pressed="form.type === type.value"
                        @click="chooseType(type.value)"
                    >
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-cap-red-50 text-cap-brand dark:bg-cap-red-600/15 dark:text-cap-brand-soft" aria-hidden="true">
                            <i class="mdi text-lg leading-none" :class="`mdi-${ICONS[type.value] ?? 'file-document-outline'}`" />
                        </span>
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold text-gray-900 dark:text-gray-100">{{ type.label }}</span>
                            <span class="block text-xs text-cap-text-soft">{{ type.description }}</span>
                        </span>
                    </button>
                </li>
                <li v-if="errors.type" class="text-sm text-cap-status-error-ink sm:col-span-2">{{ errors.type }}</li>
            </ul>

            <!-- 2. Datos -->
            <div v-else-if="step === 2" class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2" data-writing-step="2">
                <p class="flex items-center gap-2 text-sm text-gray-900 dark:text-gray-100 md:col-span-2">
                    <i class="mdi text-lg leading-none text-cap-brand dark:text-cap-brand-soft" :class="`mdi-${ICONS[form.type] ?? 'file-document-outline'}`" aria-hidden="true" />
                    <span class="font-semibold">{{ selectedType?.label }}</span>
                    <button type="button" class="text-xs text-cap-text-soft underline underline-offset-2" @click="step = 1">Cambiar</button>
                </p>
                <FormField label="Empresa proveedora" required :error="errors.company"><TextInput v-model="form.company" maxlength="255" /></FormField>
                <FormField label="Institución destinataria" required :error="errors.recipient"><TextInput v-model="form.recipient" maxlength="255" placeholder="Secretaría de Salud" /></FormField>
                <FormField label="Proceso o contrato" optional :error="errors.process" class="md:col-span-2"><TextInput v-model="form.process" maxlength="255" placeholder="LPN-SESAL-007-2026" /></FormField>
                <FormField label="Hechos y solicitud" required help="Fechas, montos, lo ocurrido y lo que pides. Mínimo 20 caracteres." :error="errors.context" class="md:col-span-2"><TextArea v-model="form.context" rows="7" maxlength="5000" /></FormField>
                <CapAlert v-if="failure" class="md:col-span-2" status="error" title="No se pudo generar el borrador" :detail="failure" />
            </div>

            <!-- 3. Borrador en una hoja -->
            <div v-else class="flex flex-col gap-4" data-writing-step="3">
                <CapAlert v-if="result" status="warning" title="Revisa el borrador antes de usarlo" :detail="disclaimer" />
                <div class="rounded-cap-control bg-cap-card-2 p-3 sm:p-6">
                    <article class="writing-sheet mx-auto w-full max-w-2xl rounded-sm bg-white px-6 py-8 text-gray-900 shadow-md dark:bg-white dark:text-gray-900 sm:px-12 sm:py-12" :aria-busy="sending ? 'true' : undefined" aria-label="Vista previa del escrito">
                        <header class="mb-6 flex items-start justify-between gap-4 border-b border-gray-200 pb-4 dark:border-gray-200">
                            <div class="min-w-0">
                                <p class="truncate font-display text-sm font-semibold text-gray-900 dark:text-gray-900">{{ form.company || companyName }}</p>
                                <p class="text-xs text-gray-600 dark:text-gray-600">{{ today }}</p>
                            </div>
                            <span class="shrink-0 rounded-full border border-gray-300 px-2 py-0.5 text-xs text-gray-600 dark:border-gray-300 dark:text-gray-600">Borrador</span>
                        </header>
                        <template v-if="sending">
                            <div class="flex flex-col items-center gap-2 py-6 text-center">
                                <Mascot state="writing" :size="96" label="Lici redactando el escrito" />
                                <p class="text-sm font-medium text-gray-900 dark:text-gray-900" role="status">Lici está redactando tu {{ selectedType?.label?.toLowerCase() || 'escrito' }}…</p>
                            </div>
                            <div class="flex flex-col gap-2" aria-hidden="true">
                                <span v-for="n in 7" :key="n" class="h-2 animate-cap-pulse rounded-full bg-gray-200 dark:bg-gray-200" :class="n % 3 === 0 ? 'w-2/3' : 'w-full'" />
                            </div>
                        </template>
                        <template v-else-if="result">
                            <h3 class="mb-4 text-center font-display text-base font-semibold uppercase tracking-wide text-gray-900 dark:text-gray-900">{{ result.title }}</h3>
                            <!-- formatMessage escapa todo el HTML antes de dar formato: el texto de la IA es dato. -->
                            <div class="formatted-text break-words text-sm leading-relaxed" data-writing-content v-html="formatMessage(result.content)" />
                        </template>
                    </article>
                </div>
            </div>
        </div>

        <template #footer>
            <template v-if="step === 1">
                <AppButton @click="visible = false">Cancelar</AppButton>
                <AppButton variant="primary" icon="arrow-right" :disabled="!form.type" @click="step = 2">Continuar</AppButton>
            </template>
            <template v-else-if="step === 2">
                <AppButton icon="arrow-left" class="mr-auto" :disabled="sending" @click="step = 1">Atrás</AppButton>
                <AppButton variant="primary" type="submit" icon="file-document-edit-outline" :loading="sending">Generar borrador</AppButton>
            </template>
            <template v-else>
                <AppButton icon="pencil-outline" class="mr-auto" :disabled="sending" @click="restart">Editar datos</AppButton>
                <AppButton icon="content-copy" :disabled="!result || sending" @click="copy">Copiar</AppButton>
                <AppButton variant="primary" icon="file-pdf-box" :loading="exporting" :disabled="!result || sending" @click="exportPdf">Exportar PDF</AppButton>
            </template>
        </template>
    </BaseModal>
</template>

<style scoped>
.formatted-text :deep(p + p),
.formatted-text :deep(p + ul),
.formatted-text :deep(p + ol),
.formatted-text :deep(ul + p),
.formatted-text :deep(ol + p),
.formatted-text :deep(blockquote) {
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
.formatted-text :deep(blockquote) {
    border-left: 3px solid var(--cap-gray-300, currentcolor);
    padding-left: 0.75rem;
    font-style: italic;
}
.formatted-text :deep(.legal-cite) {
    background: none;
    color: inherit;
    font-weight: 600;
}
.writing-sheet {
    font-family: Georgia, 'Times New Roman', serif;
}
</style>
