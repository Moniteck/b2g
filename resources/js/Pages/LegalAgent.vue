<script setup>
// Agente Legal: chat de orientación sobre contratación pública con Lici como asistente. Historial de
// conversaciones personales (panel lateral; hoja en móvil), respuestas en streaming que aparecen
// poco a poco, sugerencias, copiar por mensaje y generador de escritos. Requiere `use_ai`.
//
// Lici cambia de estado: escucha (watch) mientras escribes, busca con la lupa (searching) mientras
// espera, escribe (writing) mientras llega el texto, se alegra (happy) al terminar y se preocupa
// (worried) si algo falla. En móvil el chat ocupa todo el espacio entre la barra superior y la
// inferior, y el campo de escritura queda fijo sobre el teclado virtual.
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import CapAlert from '@design/vue/CapAlert.vue';
import CapSkeleton from '@design/vue/CapSkeleton.vue';
import AppButton from '@/Components/AppButton.vue';
import BaseModal from '@/Components/BaseModal.vue';
import ErrorState from '@/Components/ErrorState.vue';
import IconButton from '@/Components/IconButton.vue';
import { useConfirm } from '@/composables/useConfirm.js';
import AppLayout from '@/Layouts/AppLayout.vue';
import { getJson, HttpError } from '@/lib/http.js';
import { iconButton } from '@/lib/ui.js';
import Mascot from '@/Modules/Auth/Mascot.vue';
import { prefersReducedMotion } from '@/Modules/Auth/motion.js';
import LegalChatMessage from '@/Pages/Tools/Partials/LegalChatMessage.vue';
import LegalConversationList from '@/Pages/Tools/Partials/LegalConversationList.vue';
import LegalWritingModal from '@/Pages/Tools/Partials/LegalWritingModal.vue';
import { streamLegalReply } from '@/Pages/Tools/Partials/legalStream.js';
import { deleteJson } from '@/Pages/Tools/Partials/requests.js';

const props = defineProps({
    conversations: { type: Array, default: () => [] },
    writingTypes: { type: Array, default: () => [] },
    companyName: { type: String, default: '' },
    disclaimer: { type: String, default: '' },
});

const MAX_LENGTH = 4000;
const SUGGESTIONS = [
    { icon: 'shield-check-outline', title: '¿Qué garantías pide la LCE?', detail: 'Mantenimiento de oferta, cumplimiento y anticipo', message: '¿Qué garantías exige la Ley de Contratación del Estado (mantenimiento de oferta, cumplimiento, anticipo, calidad), por qué porcentaje y por cuánto tiempo?' },
    { icon: 'calendar-clock', title: 'Plazos de impugnación', detail: 'Contra una adjudicación o descalificación', message: '¿Qué plazo tengo para impugnar una adjudicación o una descalificación y ante quién lo presento?' },
    { icon: 'comment-question-outline', title: 'Cómo responder una aclaración', detail: 'Consultas y enmiendas al pliego', message: '¿Cómo pido o respondo una aclaración del pliego y qué plazos aplican?' },
    { icon: 'cash-clock', title: 'Factura aprobada sin pagar', detail: 'Reclamo de pagos atrasados', message: 'La institución no me ha pagado una factura aprobada. ¿Qué puedo hacer y qué documentos necesito?' },
    { icon: 'truck-delivery-outline', title: 'Ampliar el plazo de entrega', detail: 'Justificación y solicitud', message: '¿Cómo pido una ampliación del plazo de entrega y qué causas la justifican?' },
    { icon: 'card-account-details-outline', title: 'Inscripción en ONCAE', detail: 'Registro de proveedores', message: '¿Qué documentos suelen pedir para inscribirse en el Registro de Proveedores de ONCAE?' },
];
const STATUS = {
    idle: 'Lista para orientarte',
    watch: 'Te escucho…',
    searching: 'Buscando en la ley y el reglamento…',
    writing: 'Escribiendo la respuesta…',
    happy: '¡Listo! Revisa la respuesta',
    worried: 'Algo salió mal',
};

const confirm = useConfirm();
const history = ref([...props.conversations]);
const activeId = ref(null);
const activeTitle = ref('');
const messages = ref([]);
const loadingConversation = ref(false);
const conversationError = ref('');
const draft = ref('');
const phase = ref('idle'); // idle | waiting | streaming
const sendError = ref(null); // { title, detail, limit }
const mood = ref('');
const composing = ref(false);
const historyOpen = ref(false);
const writingOpen = ref(false);
const panel = ref(null);
const scroller = ref(null);
const composer = ref(null);
const atBottom = ref(true);
const coarse = ref(false);
let moodTimer = 0;
let revealFrame = 0;
let mediaQuery = null;

const sending = computed(() => phase.value !== 'idle');
const remaining = computed(() => MAX_LENGTH - draft.value.length);
const canSend = computed(() => draft.value.trim().length > 0 && !sending.value && remaining.value >= 0);
const liciState = computed(() => {
    if (phase.value === 'waiting') return 'searching';
    if (phase.value === 'streaming') return 'writing';
    if (mood.value) return mood.value;
    return composing.value && draft.value ? 'watch' : 'idle';
});
const status = computed(() => STATUS[liciState.value] ?? STATUS.idle);
const empty = computed(() => !messages.value.length && !loadingConversation.value && !conversationError.value);

function setMood(value, ms = 2600) {
    window.clearTimeout(moodTimer);
    mood.value = value;
    moodTimer = window.setTimeout(() => (mood.value = ''), ms);
}

// ---- Alto del chat: escritorio hasta el borde de la ventana; móvil fijo entre barras y teclado ----

function layout() {
    const element = panel.value;
    if (!element) return;
    const mobile = window.matchMedia('(max-width: 1023.98px)').matches;
    if (!mobile) {
        element.style.cssText = '';
        const top = element.getBoundingClientRect().top + window.scrollY;
        element.style.height = `${Math.max(540, window.innerHeight - top - 16)}px`;
        return;
    }
    const viewport = window.visualViewport;
    const visibleBottom = viewport ? viewport.offsetTop + viewport.height : window.innerHeight;
    const keyboard = window.innerHeight - visibleBottom > 120;
    const topbar = document.querySelector('.cap-shell-topbar')?.getBoundingClientRect().bottom ?? 64;
    const tabbar = document.querySelector('.cap-shell-tabbar')?.getBoundingClientRect().top ?? window.innerHeight;
    const top = Math.max(topbar + 8, (viewport?.offsetTop ?? 0) + 6);
    const bottom = keyboard ? window.innerHeight - visibleBottom + 6 : window.innerHeight - tabbar + 8;
    Object.assign(element.style, { position: 'fixed', left: '10px', right: '10px', top: `${top}px`, bottom: `${Math.max(6, bottom)}px`, height: 'auto', zIndex: keyboard ? 'calc(var(--cap-z-header) + 1)' : '1' });
    if (keyboard) scrollToEnd(false);
}

// ---- Desplazamiento ----

function onScroll() {
    const element = scroller.value;
    if (!element) return;
    atBottom.value = element.scrollHeight - element.scrollTop - element.clientHeight < 80;
}

function scrollToEnd(smooth = true) {
    nextTick(() => {
        const element = scroller.value;
        if (!element) return;
        element.scrollTo({ top: element.scrollHeight, behavior: smooth && !prefersReducedMotion() ? 'smooth' : 'auto' });
        atBottom.value = true;
    });
}

// ---- Campo de escritura ----

function autosize() {
    const element = composer.value;
    if (!element) return;
    element.style.height = 'auto';
    element.style.height = `${Math.min(element.scrollHeight, 200)}px`;
}

watch(draft, () => nextTick(autosize));

function onKeydown(event) {
    if (event.key !== 'Enter' || event.shiftKey || event.isComposing) return;
    // En pantallas táctiles Enter salta de línea: se envía con el botón.
    if (coarse.value) return;
    event.preventDefault();
    if (canSend.value) send();
}

// ---- Conversaciones ----

function startNew() {
    if (sending.value) return;
    activeId.value = null;
    activeTitle.value = '';
    messages.value = [];
    conversationError.value = '';
    sendError.value = null;
    historyOpen.value = false;
    nextTick(() => composer.value?.focus());
}

async function openConversation(conversation) {
    historyOpen.value = false;
    if (sending.value) return;
    activeId.value = conversation.id;
    activeTitle.value = conversation.title;
    messages.value = [];
    sendError.value = null;
    conversationError.value = '';
    loadingConversation.value = true;
    try {
        const response = await getJson(`/agente-legal/conversaciones/${conversation.id}`);
        messages.value = response.messages;
    } catch (problem) {
        conversationError.value = problem.message;
    } finally {
        loadingConversation.value = false;
        scrollToEnd(false);
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

// ---- Enviar con streaming y revelado progresivo ----

/**
 * Revela el texto recibido a un ritmo de lectura: el modelo suele mandar la respuesta en ráfagas y
 * así se ve escribir aunque llegue de golpe. Devuelve una función para fijar el texto final y una
 * promesa que se resuelve cuando todo está a la vista.
 */
function revealer(target) {
    let received = '';
    let finished = false;
    let resolveDone;
    const done = new Promise((resolve) => (resolveDone = resolve));
    const tick = () => {
        const shown = target.content.length;
        if (shown < received.length) {
            const step = prefersReducedMotion() ? received.length : Math.max(2, Math.ceil((received.length - shown) / 24));
            target.content = received.slice(0, shown + step);
            if (atBottom.value) scrollToEnd(false);
        } else if (finished) {
            resolveDone();
            return;
        }
        revealFrame = window.requestAnimationFrame(tick);
    };
    revealFrame = window.requestAnimationFrame(tick);
    return {
        push(text) {
            received += text;
        },
        finish(finalText) {
            if (typeof finalText === 'string' && finalText.length >= received.length) received = finalText;
            finished = true;
            return done;
        },
        stop() {
            window.cancelAnimationFrame(revealFrame);
            resolveDone();
        },
    };
}

async function send(text = draft.value) {
    const message = text.trim();
    if (!message || sending.value) return;
    sendError.value = null;
    phase.value = 'waiting';
    draft.value = '';
    const question = { id: `pendiente-${Date.now()}`, role: 'user', content: message, created_at: new Date().toISOString() };
    messages.value.push(question);
    scrollToEnd();

    let answer = null;
    let reveal = null;
    try {
        const done = await streamLegalReply(
            { conversation_id: activeId.value, message },
            {
                onDelta(delta) {
                    if (!answer) {
                        messages.value.push({ id: `respuesta-${Date.now()}`, role: 'assistant', content: '', created_at: null, streaming: true });
                        answer = messages.value[messages.value.length - 1];
                        reveal = revealer(answer);
                        phase.value = 'streaming';
                        scrollToEnd();
                    }
                    reveal.push(delta);
                },
            },
        );
        const [savedQuestion, savedAnswer] = done.messages;
        if (reveal) await reveal.finish(savedAnswer.content);
        messages.value = [...messages.value.filter((item) => item.id !== question.id && item.id !== answer?.id), savedQuestion, savedAnswer];
        activeId.value = done.conversation.id;
        activeTitle.value = done.conversation.title;
        const existing = history.value.find((item) => item.id === done.conversation.id);
        const entry = { ...(existing ?? {}), id: done.conversation.id, title: done.conversation.title, updated_at: done.conversation.updated_at, messages_count: (existing?.messages_count ?? 0) + 2 };
        history.value = [entry, ...history.value.filter((item) => item.id !== entry.id)];
        phase.value = 'idle';
        setMood('happy');
        scrollToEnd();
    } catch (problem) {
        reveal?.stop();
        messages.value = messages.value.filter((item) => item.id !== question.id && item.id !== answer?.id);
        draft.value = message;
        const limited = problem instanceof HttpError && problem.status === 429;
        sendError.value = limited
            ? { limit: true, title: 'Lici necesita un respiro', detail: `${problem.message} Tu consulta quedó en el campo para enviarla luego.` }
            : { limit: false, title: 'No se pudo enviar la consulta', detail: problem.message };
        phase.value = 'idle';
        setMood('worried', 4200);
        scrollToEnd();
    }
}

function onMedia() {
    coarse.value = Boolean(mediaQuery?.matches);
}

onMounted(() => {
    mediaQuery = window.matchMedia('(pointer: coarse)');
    onMedia();
    mediaQuery.addEventListener?.('change', onMedia);
    layout();
    window.addEventListener('resize', layout);
    window.visualViewport?.addEventListener('resize', layout);
    window.visualViewport?.addEventListener('scroll', layout);
    // El marco termina de pintar la cabecera después del montaje.
    setTimeout(layout, 250);
});

onBeforeUnmount(() => {
    mediaQuery?.removeEventListener?.('change', onMedia);
    window.removeEventListener('resize', layout);
    window.visualViewport?.removeEventListener('resize', layout);
    window.visualViewport?.removeEventListener('scroll', layout);
    window.clearTimeout(moodTimer);
    window.cancelAnimationFrame(revealFrame);
});
</script>

<template>
    <AppLayout title="Agente Legal">
        <div ref="panel" class="legal-panel flex min-h-0 overflow-hidden rounded-cap-card border border-cap-divider bg-cap-card shadow-sm" data-legal-panel>
            <!-- Conversaciones (escritorio) -->
            <aside class="hidden w-72 shrink-0 flex-col gap-3 border-r border-cap-divider bg-cap-card-2/40 p-3 lg:flex xl:w-80" aria-label="Historial">
                <div class="flex items-center justify-between gap-2 px-1 pt-1">
                    <h2 class="font-display text-sm font-semibold text-gray-900 dark:text-gray-100">Conversaciones</h2>
                    <span class="text-xs tabular-nums text-cap-text-soft">{{ history.length }}</span>
                </div>
                <AppButton icon="plus" :disabled="sending" @click="startNew">Nueva conversación</AppButton>
                <LegalConversationList class="min-h-0 flex-1" :conversations="history" :active-id="activeId" :disabled="sending" @open="openConversation" @remove="remove" />
            </aside>

            <!-- Chat -->
            <section class="relative flex min-w-0 flex-1 flex-col" aria-labelledby="legal-chat-title">
                <header class="flex items-center gap-3 border-b border-cap-divider px-3 py-2.5 sm:px-5">
                    <div class="legal-lici shrink-0" aria-hidden="true"><Mascot :state="liciState" :size="52" label="" /></div>
                    <div class="min-w-0 flex-1">
                        <h1 id="legal-chat-title" class="truncate font-display text-base font-semibold text-gray-900 dark:text-gray-100">{{ activeTitle || 'Lici · Agente Legal' }}</h1>
                        <p class="flex items-center gap-1.5 truncate text-xs text-cap-text-soft" role="status" aria-live="polite" data-lici-status>
                            <span class="inline-block h-2 w-2 shrink-0 rounded-full" :class="liciState === 'worried' ? 'bg-cap-status-error' : sending ? 'animate-cap-pulse bg-cap-status-warning' : 'bg-cap-status-ok'" aria-hidden="true" />
                            {{ status }}
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-1">
                        <span class="lg:hidden"><IconButton icon="history" label="Conversaciones" @click="historyOpen = true" /></span>
                        <span class="lg:hidden"><IconButton icon="plus" label="Nueva conversación" :disabled="sending" @click="startNew" /></span>
                        <span class="hidden sm:inline-flex"><AppButton icon="file-document-edit-outline" @click="writingOpen = true">Redactar escrito</AppButton></span>
                        <span class="sm:hidden"><IconButton icon="file-document-edit-outline" label="Redactar escrito" @click="writingOpen = true" /></span>
                    </div>
                </header>

                <div ref="scroller" class="min-h-0 flex-1 overflow-y-auto overscroll-contain" data-legal-thread @scroll.passive="onScroll">
                    <div class="mx-auto flex w-full max-w-3xl flex-col gap-5 px-3 py-5 sm:px-6">
                        <CapSkeleton v-if="loadingConversation" variant="line" :rows="6" />
                        <ErrorState v-else-if="conversationError" compact title="No se pudo abrir la conversación" :description="conversationError" @retry="openConversation({ id: activeId, title: activeTitle })" />

                        <template v-else-if="empty">
                            <div class="flex flex-col items-center gap-2 pt-2 text-center sm:pt-6">
                                <Mascot :state="composing && draft ? 'watch' : 'reading'" :look="{ x: 0, y: 0.8 }" :size="112" label="Lici, la asistente del Agente Legal, leyendo un libro de leyes" />
                                <h2 class="mt-2 font-display text-xl font-semibold text-gray-900 dark:text-gray-100">Hola, soy Lici</h2>
                                <p class="max-w-md text-sm text-cap-text-soft">Te oriento sobre la Ley de Contratación del Estado, su reglamento y los pliegos. Cuéntame tu caso o empieza con una de estas preguntas.</p>
                            </div>
                            <ul class="grid grid-cols-1 gap-2.5 sm:grid-cols-2" aria-label="Preguntas sugeridas">
                                <li v-for="suggestion in SUGGESTIONS" :key="suggestion.title">
                                    <button
                                        type="button"
                                        class="group flex h-full w-full items-start gap-3 rounded-cap-card border border-cap-divider bg-cap-card p-3.5 text-left shadow-sm transition duration-cap-fast ease-cap hover:-translate-y-0.5 hover:border-cap-input-line hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome disabled:opacity-50 dark:focus-visible:ring-gray-100"
                                        :disabled="sending"
                                        data-suggestion
                                        @click="send(suggestion.message)"
                                    >
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-cap-red-50 text-cap-brand transition-colors duration-cap-fast ease-cap dark:bg-cap-red-600/15 dark:text-cap-brand-soft" aria-hidden="true">
                                            <i class="mdi text-lg leading-none" :class="`mdi-${suggestion.icon}`" />
                                        </span>
                                        <span class="min-w-0">
                                            <span class="block text-sm font-semibold text-gray-900 dark:text-gray-100">{{ suggestion.title }}</span>
                                            <span class="block text-xs text-cap-text-soft">{{ suggestion.detail }}</span>
                                        </span>
                                    </button>
                                </li>
                            </ul>
                        </template>

                        <ol v-else class="flex flex-col gap-5" aria-label="Mensajes">
                            <LegalChatMessage v-for="message in messages" :key="message.id" :message="message" />
                            <li v-if="phase === 'waiting'" class="flex items-center gap-3" data-typing>
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-cap-divider bg-cap-card-2 text-cap-brand dark:text-cap-brand-soft" aria-hidden="true"><i class="mdi mdi-owl text-lg leading-none" /></span>
                                <span class="legal-typing flex items-center gap-1 rounded-cap-card rounded-tl-md border border-cap-divider bg-cap-card px-4 py-3.5" role="status" aria-label="Lici está buscando la respuesta">
                                    <span /><span /><span />
                                </span>
                            </li>
                        </ol>

                        <CapAlert v-if="sendError" :status="sendError.limit ? 'warning' : 'error'" :title="sendError.title" :detail="sendError.detail" data-send-error />
                    </div>
                </div>

                <button
                    v-if="!atBottom && messages.length"
                    type="button"
                    :class="[iconButton, 'absolute bottom-36 left-1/2 z-10 -translate-x-1/2 border border-cap-divider bg-cap-card shadow-md']"
                    aria-label="Ir al último mensaje"
                    title="Ir al último mensaje"
                    @click="scrollToEnd()"
                >
                    <i class="mdi mdi-arrow-down text-xl leading-none" aria-hidden="true" />
                </button>

                <form class="border-t border-cap-divider bg-cap-card px-3 pb-3 pt-3 sm:px-5" @submit.prevent="send()">
                    <div class="mx-auto flex w-full max-w-3xl flex-col gap-1.5">
                        <div class="flex items-end gap-2 rounded-cap-card border border-cap-input-line bg-cap-card px-3 py-2 transition-colors duration-cap-fast ease-cap focus-within:border-cap-chrome focus-within:ring-1 focus-within:ring-cap-chrome dark:focus-within:border-gray-100 dark:focus-within:ring-gray-100">
                            <label for="legal-message" class="sr-only">Escribe tu consulta</label>
                            <textarea
                                id="legal-message"
                                ref="composer"
                                v-model="draft"
                                rows="1"
                                :maxlength="MAX_LENGTH"
                                class="max-h-52 min-h-[2.25rem] flex-1 resize-none bg-transparent py-1.5 text-sm text-gray-900 placeholder:text-gray-500 focus:outline-none dark:text-gray-100 dark:placeholder:text-gray-400"
                                :placeholder="coarse ? 'Escribe tu consulta…' : 'Describe tu caso: proceso, fechas y lo que necesitas saber…'"
                                :disabled="sending"
                                enterkeyhint="send"
                                @keydown="onKeydown"
                                @focus="composing = true"
                                @blur="composing = false"
                            />
                            <AppButton variant="primary" type="submit" icon="send-outline" :loading="sending" :disabled="!canSend" class="shrink-0" data-send>
                                <span class="max-sm:sr-only">{{ sendError ? 'Reintentar' : 'Enviar' }}</span>
                            </AppButton>
                        </div>
                        <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-0.5 px-1 text-xs text-cap-text-soft">
                            <p class="flex items-center gap-1" :title="disclaimer">
                                <i class="mdi mdi-information-outline text-sm leading-none" aria-hidden="true" />
                                Orientación general con IA, no asesoría legal.
                                <span class="sr-only">{{ disclaimer }}</span>
                            </p>
                            <p>
                                <span v-if="!coarse" class="max-sm:hidden">Enter envía · Mayús + Enter, salto de línea</span>
                                <span v-if="remaining < 500" class="ml-2 tabular-nums" :class="remaining < 100 ? 'text-cap-status-error-ink' : ''">{{ remaining }} caracteres</span>
                            </p>
                        </div>
                    </div>
                </form>
            </section>
        </div>

        <BaseModal v-model:open="historyOpen" size="md" title="Conversaciones" description="Retoma una consulta anterior.">
            <LegalConversationList :conversations="history" :active-id="activeId" :disabled="sending" @open="openConversation" @remove="remove" />
            <template #footer>
                <AppButton @click="historyOpen = false">Cerrar</AppButton>
                <AppButton variant="primary" icon="plus" :disabled="sending" @click="startNew">Nueva conversación</AppButton>
            </template>
        </BaseModal>

        <LegalWritingModal v-model:open="writingOpen" :types="writingTypes" :company-name="companyName" :disclaimer="disclaimer" />
    </AppLayout>
</template>

<style scoped>
.legal-lici {
    margin: -6px 0 -10px;
}
.legal-typing span {
    width: 0.45rem;
    height: 0.45rem;
    border-radius: 999px;
    background: var(--cap-text-soft);
    animation: legal-typing 1.1s ease-in-out infinite;
}
.legal-typing span:nth-child(2) {
    animation-delay: 150ms;
}
.legal-typing span:nth-child(3) {
    animation-delay: 300ms;
}
@keyframes legal-typing {
    0%,
    60%,
    100% {
        transform: translateY(0);
        opacity: 0.4;
    }
    30% {
        transform: translateY(-4px);
        opacity: 1;
    }
}
@media (prefers-reduced-motion: reduce) {
    .legal-typing span {
        animation: none;
    }
}
</style>
