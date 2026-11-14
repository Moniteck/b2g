<script setup>
// Generador de escritos del Agente Legal, en dos pasos dentro de la misma modal: datos del caso y
// borrador (copiar, exportar a PDF o volver a los datos). El borrador no se guarda en el servidor.
import { computed, reactive, ref, watch } from 'vue';
import AppButton from '../../../Components/AppButton.vue';
import BaseModal from '../../../Components/BaseModal.vue';
import FormField from '../../../Components/FormField.vue';
import SelectInput from '../../../Components/SelectInput.vue';
import TextArea from '../../../Components/TextArea.vue';
import TextInput from '../../../Components/TextInput.vue';
import CapAlert from '../../../../design/vue/CapAlert.vue';
import { useToasts } from '../../../../design/vue/composables/useToasts.js';
import { focusFirstError } from '../../../lib/errors.js';
import { postJson } from '../../../lib/http.js';
import { formatMessage, plainText } from './formatMessage.js';
import { downloadPdf } from './requests.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    types: { type: Array, default: () => [] },
    companyName: { type: String, default: '' },
    disclaimer: { type: String, default: '' },
});
const emit = defineEmits(['update:open']);

const toasts = useToasts();
const body = ref(null);
const sending = ref(false);
const exporting = ref(false);
const failure = ref('');
const errors = ref({});
const result = ref(null);
const form = reactive({ type: '', company: '', recipient: '', process: '', context: '' });

const typeOptions = computed(() => props.types.map((type) => ({ value: type.value, label: type.label })));
const selectedType = computed(() => props.types.find((type) => type.value === form.type));
const visible = computed({ get: () => props.open, set: (value) => emit('update:open', value) });

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        failure.value = '';
        errors.value = {};
        if (!form.company) form.company = props.companyName;
    },
);

async function generate() {
    sending.value = true;
    failure.value = '';
    errors.value = {};
    try {
        result.value = await postJson('/agente-legal/escritos', { ...form });
    } catch (problem) {
        errors.value = Object.fromEntries(Object.entries(problem.errors ?? {}).map(([key, messages]) => [key, messages[0]]));
        failure.value = Object.keys(errors.value).length ? '' : problem.message;
        if (Object.keys(errors.value).length) setTimeout(() => focusFirstError(body.value));
    } finally {
        sending.value = false;
    }
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
    <BaseModal v-model:open="visible" :as="result ? 'div' : 'form'" size="xl" :title="result ? result.title : 'Redactar escrito'" :description="result ? 'Borrador para revisión profesional' : 'Prepara un borrador a partir de los hechos del caso.'" :persistent="sending" @submit="generate">
        <div v-if="!result" ref="body" class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
            <FormField label="Tipo de escrito" required :error="errors.type" :help="selectedType?.description" class="md:col-span-2"><SelectInput v-model="form.type" :options="typeOptions" placeholder="Elige el tipo de escrito" /></FormField>
            <FormField label="Empresa proveedora" required :error="errors.company"><TextInput v-model="form.company" maxlength="255" /></FormField>
            <FormField label="Institución destinataria" required :error="errors.recipient"><TextInput v-model="form.recipient" maxlength="255" placeholder="Secretaría de Salud" /></FormField>
            <FormField label="Proceso o contrato" optional :error="errors.process" class="md:col-span-2"><TextInput v-model="form.process" maxlength="255" placeholder="LPN-SESAL-007-2026" /></FormField>
            <FormField label="Hechos y solicitud" required help="Fechas, montos, lo ocurrido y lo que pides. Mínimo 20 caracteres." :error="errors.context" class="md:col-span-2"><TextArea v-model="form.context" rows="7" maxlength="5000" /></FormField>
            <CapAlert v-if="failure" class="md:col-span-2" status="error" title="No se pudo generar el borrador" :detail="failure" />
        </div>
        <div v-else class="flex flex-col gap-4">
            <CapAlert status="warning" title="Revisa el borrador antes de usarlo" :detail="disclaimer" />
            <!-- formatMessage escapa todo el HTML antes de dar formato: el texto de la IA es dato. -->
            <div class="formatted-text break-words rounded-cap-control bg-cap-card-2 p-4 text-sm leading-relaxed text-gray-900 dark:text-gray-100" v-html="formatMessage(result.content)" />
        </div>
        <template #footer>
            <template v-if="!result">
                <AppButton :disabled="sending" @click="visible = false">Cancelar</AppButton>
                <AppButton variant="primary" type="submit" icon="file-document-edit-outline" :loading="sending">{{ sending ? 'Redactando…' : 'Generar borrador' }}</AppButton>
            </template>
            <template v-else>
                <AppButton icon="pencil-outline" class="mr-auto" @click="result = null">Editar datos</AppButton>
                <AppButton icon="content-copy" @click="copy">Copiar</AppButton>
                <AppButton variant="primary" icon="file-pdf-box" :loading="exporting" @click="exportPdf">Exportar PDF</AppButton>
            </template>
        </template>
    </BaseModal>
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
