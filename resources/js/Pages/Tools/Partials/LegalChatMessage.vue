<script setup>
// Burbuja de un mensaje del Agente Legal. La respuesta lleva formato (listas, negritas, citas y
// artículos resaltados), botón para copiar y, mientras llega, un cursor de escritura.
// Props: message ({ id, role, content, created_at, streaming? }).
import { ref } from 'vue';
import { useToasts } from '@design/vue/composables/useToasts.js';
import { time } from '@/lib/format.js';
import { iconButton } from '@/lib/ui.js';
import { formatMessage, plainText } from '@/Pages/Tools/Partials/formatMessage.js';

const props = defineProps({
    message: { type: Object, required: true },
});

const toasts = useToasts();
const copied = ref(false);

async function copy() {
    try {
        await navigator.clipboard.writeText(plainText(props.message.content));
        copied.value = true;
        setTimeout(() => (copied.value = false), 1600);
    } catch {
        toasts.show({ status: 'error', title: 'No se pudo copiar', detail: 'Selecciona el texto y cópialo a mano.' });
    }
}
</script>

<template>
    <li v-if="message.role === 'user'" class="flex justify-end pl-8 sm:pl-16" data-message="user">
        <div class="min-w-0 max-w-full">
            <div class="whitespace-pre-wrap break-words rounded-cap-card rounded-br-md bg-cap-red-50 px-4 py-2.5 text-sm leading-relaxed text-gray-900 dark:bg-cap-red-600/15 dark:text-gray-100">{{ message.content }}</div>
            <p v-if="message.created_at" class="mt-1 text-right text-xs text-cap-text-soft">{{ time(message.created_at) }}</p>
        </div>
    </li>

    <li v-else class="group flex gap-3 pr-2 sm:pr-10" data-message="assistant" :aria-busy="message.streaming ? 'true' : undefined">
        <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-cap-divider bg-cap-card-2 text-cap-brand dark:text-cap-brand-soft" aria-hidden="true">
            <i class="mdi mdi-owl text-lg leading-none" />
        </span>
        <div class="min-w-0 flex-1">
            <div class="rounded-cap-card rounded-tl-md border border-cap-divider bg-cap-card px-4 py-3 shadow-sm">
                <!-- formatMessage escapa todo el HTML antes de dar formato: el texto de la IA es dato. -->
                <div class="legal-answer break-words text-sm leading-relaxed text-gray-900 dark:text-gray-100" :class="{ 'legal-answer--streaming': message.streaming }" v-html="formatMessage(message.content)" />
            </div>
            <div class="mt-1 flex h-8 items-center gap-1 text-xs text-cap-text-soft">
                <span>Lici · Agente Legal<template v-if="message.created_at"> · {{ time(message.created_at) }}</template></span>
                <button
                    v-if="!message.streaming"
                    type="button"
                    :class="[iconButton, 'h-8 w-8 opacity-100 lg:opacity-0 lg:group-hover:opacity-100 lg:focus-visible:opacity-100']"
                    :aria-label="copied ? 'Respuesta copiada' : 'Copiar la respuesta'"
                    :title="copied ? 'Copiada' : 'Copiar'"
                    data-copy
                    @click="copy"
                >
                    <i class="mdi text-base leading-none" :class="copied ? 'mdi-check text-cap-status-ok' : 'mdi-content-copy'" aria-hidden="true" />
                </button>
            </div>
        </div>
    </li>
</template>

<style scoped>
.legal-answer :deep(p + p),
.legal-answer :deep(p + ul),
.legal-answer :deep(p + ol),
.legal-answer :deep(ul + p),
.legal-answer :deep(ol + p),
.legal-answer :deep(blockquote),
.legal-answer :deep(blockquote + p),
.legal-answer :deep(ul + ul),
.legal-answer :deep(ol + ol) {
    margin-top: 0.75rem;
}
.legal-answer :deep(ul) {
    list-style: disc;
    padding-left: 1.25rem;
}
.legal-answer :deep(ol) {
    list-style: decimal;
    padding-left: 1.25rem;
}
.legal-answer :deep(li + li) {
    margin-top: 0.3rem;
}
.legal-answer :deep(li::marker) {
    color: var(--cap-brand);
}
.legal-answer :deep(.legal-heading) {
    font-family: var(--cap-font-display);
}
.legal-answer :deep(blockquote) {
    border-left: 3px solid var(--cap-brand);
    border-radius: 0 var(--cap-radius-control) var(--cap-radius-control) 0;
    background: var(--cap-card-2);
    padding: 0.5rem 0.75rem;
    font-style: italic;
}
.legal-answer :deep(.legal-cite) {
    border-radius: 0.375rem;
    background: var(--cap-red-tint);
    color: inherit;
    padding: 0 0.3rem;
    font-weight: 600;
    white-space: nowrap;
}
.legal-answer--streaming > :deep(*:last-child)::after {
    content: '';
    display: inline-block;
    width: 0.5rem;
    height: 1em;
    margin-left: 0.15rem;
    vertical-align: -0.15em;
    border-radius: 2px;
    background: var(--cap-brand);
    animation: legal-caret 900ms steps(2, start) infinite;
}
@keyframes legal-caret {
    to {
        visibility: hidden;
    }
}
</style>
