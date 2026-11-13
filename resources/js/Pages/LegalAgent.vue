<script setup>
// Agente Legal: chat de orientación sobre contratación pública con historial de conversaciones
// personales (lista, nueva, retomar, eliminar) y generador de escritos. Requiere `use_ai`.
import { computed, nextTick, ref } from 'vue';
import AppButton from '../Components/AppButton.vue';
import BaseModal from '../Components/BaseModal.vue';
import EmptyState from '../Components/EmptyState.vue';
import ErrorState from '../Components/ErrorState.vue';
import IconButton from '../Components/IconButton.vue';
import PageHeader from '../Components/PageHeader.vue';
import CapAlert from '../../design/vue/CapAlert.vue';
import CapSkeleton from '../../design/vue/CapSkeleton.vue';
import { useConfirm } from '../composables/useConfirm.js';
import AppLayout from '../Layouts/AppLayout.vue';
import { relativeTime, time } from '../lib/format.js';
import { getJson, postJson, HttpError } from '../lib/http.js';
import { inputClass } from '../lib/ui.js';
import LegalWritingModal from './Tools/Partials/LegalWritingModal.vue';
import { formatMessage } from './Tools/Partials/formatMessage.js';
import { deleteJson } from './Tools/Partials/requests.js';

const props = defineProps({
    conversations: { type: Array, default: () => [] },
    writingTypes: { type: Array, default: () => [] },
    companyName: { type: String, default: '' },
    disclaimer: { type: String, default: '' },
});

const MAX_LENGTH = 4000;
const SUGGESTIONS = [
    '¿Qué plazo tengo para impugnar una adjudicación?',
    'La institución no me ha pagado una factura aprobada. ¿Qué puedo hacer?',
    '¿Qué garantías puede exigir un pliego y por cuánto tiempo?',
    '¿Cómo pido una ampliación del plazo de entrega?',
    '¿Qué documentos suelen pedir para inscribirse en ONCAE?',
    '¿Puedo modificar un contrato vigente?',
];

const confirm = useConfirm();
const history = ref([...props.conversations]);
const activeId = ref(null);
const activeTitle = ref('');
const messages = ref([]);
const loadingConversation = ref(false);
const conversationError = ref('');
const draft = ref('');
const sending = ref(false);
const sendError = ref('');
const pending = ref(null);
const historyOpen = ref(false);
const writingOpen = ref(false);
const thread = ref(null);
const composer = ref(null);

const remaining = computed(() => MAX_LENGTH - draft.value.length);
const canSend = computed(() => draft.value.trim().length > 0 && !sending.value && remaining.value >= 0);

function scrollToEnd() {
    nextTick(() => thread.value?.lastElementChild?.scrollIntoView({ behavior: 'smooth', block: 'end' }));
}

function startNew() {
    activeId.value = null;
    activeTitle.value = '';
    messages.value = [];
    conversationError.value = '';
    sendError.value = '';
    historyOpen.value = false;
    nextTick(() => composer.value?.focus());
}

async function openConversation(conversation) {
    historyOpen.value = false;
    if (sending.value) return;
    activeId.value = conversation.id;
    activeTitle.value = conversation.title;
    messages.value = [];
    sendError.value = '';
    conversationError.value = '';
    loadingConversation.value = true;
    try {
        const response = await getJson(`/agente-legal/conversaciones/${conversation.id}`);
        messages.value = response.messages;
        scrollToEnd();
    } catch (problem) {
        conversationError.value = problem.message;
    } finally {
        loadingConversation.value = false;
    }
}

async function send(text = draft.value) {
    const message = text.trim();
    if (!message || sending.value) return;
    sending.value = true;
    sendError.value = '';
    pending.value = message;
    draft.value = '';
    scrollToEnd();
    try {
        const response = await postJson('/agente-legal/chat', { conversation_id: activeId.value, message });
        messages.value.push(...response.messages);
        activeId.value = response.conversation.id;
        activeTitle.value = response.conversation.title;
        const existing = history.value.find((item) => item.id === response.conversation.id);
        const entry = { ...(existing ?? {}), id: response.conversation.id, title: response.conversation.title, updated_at: response.conversation.updated_at, messages_count: (existing?.messages_count ?? 0) + 2 };
        history.value = [entry, ...history.value.filter((item) => item.id !== entry.id)];
    } catch (problem) {
        draft.value = message;
        sendError.value = problem instanceof HttpError && problem.status === 429 ? 'Hiciste muchas consultas seguidas. Espera un minuto y vuelve a intentarlo.' : problem.message;
    } finally {
        pending.value = null;
        sending.value = false;
        scrollToEnd();
    }
}

function onKeydown(event) {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        if (canSend.value) send();
    }
}

function remove(conversation) {
    confirm({
        verb: 'Eliminar',
        object: `la conversación «${conversation.title}»`,
        noun: 'conversación',
        message: 'Se borrarán todos sus mensajes. No se puede deshacer.',
        icon: 'trash-can-outline',
        action: async () => {
            await deleteJson(`/agente-legal/conversaciones/${conversation.id}`);
            history.value = history.value.filter((item) => item.id !== conversation.id);
            if (activeId.value === conversation.id) startNew();
        },
    });
}
</script>

<template>
    <AppLayout title="Agente Legal">
        <PageHeader description="Orientación sobre la Ley de Contratación del Estado, su reglamento y los pliegos.">
            <template #actions>
                <div class="lg:hidden"><AppButton icon="history" @click="historyOpen = true">Conversaciones</AppButton></div>
                <AppButton icon="file-document-edit-outline" @click="writingOpen = true">Redactar escrito</AppButton>
                <AppButton icon="plus" :disabled="sending" @click="startNew">Nueva conversación</AppButton>
            </template>
        </PageHeader>

        <CapAlert status="info" title="Orientación, no asesoría legal" :detail="disclaimer" />

        <div class="grid gap-6 lg:grid-cols-[288px_minmax(0,1fr)]">
            <aside class="hidden lg:block">
                <section class="rounded-cap-card border border-cap-divider bg-cap-card p-4">
                    <h2 class="mb-3 px-1 font-display text-base font-semibold text-gray-900 dark:text-gray-100">Conversaciones</h2>
                    <p v-if="!history.length" class="px-1 text-sm text-cap-text-soft">Tus consultas aparecerán aquí para retomarlas después.</p>
                    <ul v-else class="flex flex-col gap-1">
                        <li v-for="conversation in history" :key="conversation.id" class="flex items-center gap-1 rounded-cap-control border-l-[3px] transition-colors duration-cap-fast ease-cap hover:bg-cap-card-2" :class="conversation.id === activeId ? 'border-cap-brand bg-cap-card-2 dark:border-cap-brand-soft' : 'border-transparent'">
                            <button type="button" class="min-w-0 flex-1 rounded-cap-control px-3 py-2 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome dark:focus-visible:ring-gray-100" :aria-current="conversation.id === activeId ? 'true' : undefined" @click="openConversation(conversation)">
                                <span class="block truncate text-sm font-medium text-gray-900 dark:text-gray-100" :title="conversation.title">{{ conversation.title }}</span>
                                <span class="block text-xs text-cap-text-soft">{{ relativeTime(conversation.updated_at) }} · {{ conversation.messages_count }} mensajes</span>
                            </button>
                            <IconButton icon="trash-can-outline" :label="`Eliminar la conversación ${conversation.title}`" @click="remove(conversation)" />
                        </li>
                    </ul>
                </section>
            </aside>

            <section class="flex min-w-0 flex-col rounded-cap-card border border-cap-divider bg-cap-card">
                <header class="flex items-center gap-3 border-b border-cap-divider px-5 py-4">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-cap-red-50 text-cap-brand dark:bg-cap-red-600/15 dark:text-cap-brand-soft" aria-hidden="true"><i class="mdi mdi-scale-balance text-xl leading-none" /></span>
                    <div class="min-w-0">
                        <h2 class="truncate font-display text-base font-semibold text-gray-900 dark:text-gray-100">{{ activeTitle || 'Nueva conversación' }}</h2>
                        <p class="text-xs text-cap-text-soft">Decreto 74-2001 y su reglamento</p>
                    </div>
                </header>

                <div class="flex flex-col gap-4 px-5 py-6" aria-live="polite">
                    <CapSkeleton v-if="loadingConversation" variant="line" :rows="5" />
                    <ErrorState v-else-if="conversationError" compact title="No se pudo abrir la conversación" :description="conversationError" @retry="openConversation({ id: activeId, title: activeTitle })" />
                    <template v-else-if="!messages.length && !pending">
                        <EmptyState compact icon="scale-balance" title="¿En qué te puedo orientar?" description="Escribe tu consulta o elige una de estas para empezar." />
                        <div class="grid gap-2 sm:grid-cols-2">
                            <button v-for="suggestion in SUGGESTIONS" :key="suggestion" type="button" class="rounded-cap-control border border-cap-divider bg-cap-card px-4 py-3 text-left text-sm text-gray-800 transition-colors duration-cap-fast ease-cap hover:bg-cap-card-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome disabled:opacity-50 dark:text-gray-200 dark:focus-visible:ring-gray-100" :disabled="sending" @click="send(suggestion)">{{ suggestion }}</button>
                        </div>
                    </template>
                    <ol v-else ref="thread" class="flex flex-col gap-4">
                        <li v-for="message in messages" :key="message.id" class="flex gap-3" :class="message.role === 'user' ? 'justify-end' : ''">
                            <span v-if="message.role !== 'user'" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-cap-card-2 text-cap-text-soft" aria-hidden="true"><i class="mdi mdi-scale-balance text-base leading-none" /></span>
                            <div class="min-w-0 max-w-[85%]">
                                <div v-if="message.role === 'user'" class="whitespace-pre-wrap break-words rounded-cap-control bg-cap-card-2 px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{{ message.content }}</div>
                                <!-- formatMessage escapa todo el HTML antes de dar formato: el contenido es dato. -->
                                <div v-else class="legal-answer break-words rounded-cap-control border border-cap-divider px-4 py-3 text-sm leading-relaxed text-gray-900 dark:text-gray-100" v-html="formatMessage(message.content)" />
                                <p class="mt-1 text-xs text-cap-text-soft" :class="message.role === 'user' ? 'text-right' : ''">{{ message.role === 'user' ? 'Tú' : 'Agente Legal' }} · {{ time(message.created_at) }}</p>
                            </div>
                        </li>
                        <li v-if="pending" class="flex justify-end">
                            <div class="max-w-[85%] whitespace-pre-wrap break-words rounded-cap-control bg-cap-card-2 px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{{ pending }}</div>
                        </li>
                        <li v-if="sending" class="flex items-center gap-3" role="status">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-cap-card-2 text-cap-text-soft" aria-hidden="true"><i class="mdi mdi-scale-balance text-base leading-none" /></span>
                            <span class="animate-cap-pulse text-sm text-cap-text-soft">El agente está escribiendo…</span>
                        </li>
                    </ol>
                    <CapAlert v-if="sendError" status="error" title="No se pudo enviar la consulta" :detail="sendError" />
                </div>

                <form class="border-t border-cap-divider p-4" @submit.prevent="send()">
                    <label for="legal-message" class="sr-only">Escribe tu consulta</label>
                    <textarea
                        id="legal-message"
                        ref="composer"
                        v-model="draft"
                        rows="3"
                        :maxlength="MAX_LENGTH"
                        :class="[inputClass(), 'resize-none py-2']"
                        placeholder="Describe tu caso: proceso, fechas y lo que necesitas saber…"
                        :disabled="sending"
                        @keydown="onKeydown"
                    />
                    <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                        <p class="text-xs text-cap-text-soft">Enter envía · Mayús + Enter salta de línea · <span class="tabular-nums">{{ remaining }}</span> caracteres disponibles</p>
                        <AppButton variant="primary" type="submit" icon="send-outline" :loading="sending" :disabled="!canSend">{{ sendError ? 'Reintentar' : 'Enviar' }}</AppButton>
                    </div>
                </form>
            </section>
        </div>

        <BaseModal v-model:open="historyOpen" size="md" title="Conversaciones">
            <p v-if="!history.length" class="text-sm text-cap-text-soft">Tus consultas aparecerán aquí para retomarlas después.</p>
            <ul v-else class="flex flex-col gap-1">
                <li v-for="conversation in history" :key="conversation.id" class="flex items-center gap-1 rounded-cap-control" :class="conversation.id === activeId ? 'bg-cap-card-2' : ''">
                    <button type="button" class="min-w-0 flex-1 rounded-cap-control px-3 py-2 text-left" @click="openConversation(conversation)">
                        <span class="block truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ conversation.title }}</span>
                        <span class="block text-xs text-cap-text-soft">{{ relativeTime(conversation.updated_at) }}</span>
                    </button>
                    <IconButton icon="trash-can-outline" :label="`Eliminar la conversación ${conversation.title}`" @click="remove(conversation)" />
                </li>
            </ul>
            <template #footer>
                <AppButton @click="historyOpen = false">Cerrar</AppButton>
                <AppButton variant="primary" icon="plus" @click="startNew">Nueva conversación</AppButton>
            </template>
        </BaseModal>

        <LegalWritingModal v-model:open="writingOpen" :types="writingTypes" :company-name="companyName" :disclaimer="disclaimer" />
    </AppLayout>
</template>

<style scoped>
.legal-answer :deep(p + p),
.legal-answer :deep(p + ul),
.legal-answer :deep(p + ol),
.legal-answer :deep(ul + p),
.legal-answer :deep(ol + p) {
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
    margin-top: 0.25rem;
}
</style>
