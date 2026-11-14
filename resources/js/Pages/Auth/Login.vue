<script setup>
// Pantalla de acceso animada: «Ingresar» y «Crear cuenta» (solicitud de acceso) en una tarjeta que
// se voltea en escritorio y se desliza con el dedo en celular, con Lici (el búho) que sigue lo que
// escribes, se tapa los ojos con la contraseña, se preocupa con un error, celebra la solicitud y
// saluda al entrar. Excepción acotada a la guía (movimiento vistoso solo aquí); con
// `prefers-reduced-motion` todo pasa a fundidos cortos, sin giros, saltos ni partículas.
//
// Rutas: /login (cara «Ingresar») y /solicitar-acceso (cara «Crear cuenta"); la URL sigue a la cara.
import { interceptors } from '@inertiajs/core';
import { Head, router } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { company } from '../../../design/company.js';
import { toggleTheme } from '../../../design/shell/js/theme.js';
import { SYSTEM_NAME } from '../../config/navigation.js';
import { playLoginExit } from '../../Modules/Auth/exitCurtain.js';
import Mascot from '../../Modules/Auth/Mascot.vue';
import { confetti, useMedia } from '../../Modules/Auth/motion.js';
import '../../Modules/Auth/auth.css';
import AuthCard from './Partials/AuthCard.vue';
import BrandPanel from './Partials/BrandPanel.vue';
import LoginForm from './Partials/LoginForm.vue';
import RequestForm from './Partials/RequestForm.vue';

const props = defineProps({
    mode: { type: String, default: 'login' },
    companies: { type: Array, default: () => [] },
    helpEmail: { type: String, default: '' },
});

const URLS = { login: '/login', register: '/solicitar-acceso' };
// Cargadores de las páginas: durante la salida animada se descarga ya la página de destino, así la
// animación no suma tiempo a la navegación.
const PAGES = import.meta.glob('../**/*.vue');
const TITLES = { login: 'Ingresar', register: 'Solicitar acceso' };
const TAGLINE = 'Licitaciones, cotizaciones y contratos con el Estado, de la oportunidad al cobro.';

const mobile = useMedia('(max-width: 1023.98px)');
const face = ref(props.mode === 'register' ? 'register' : 'login');
const announcement = ref('');
const prefill = ref('');
const dark = ref(false);

// Escena: elementos para la salida animada y la mirada de Lici.
const scene = ref(null);
const cardRef = ref(null);
const mascotRef = ref(null);

// ---- Estado de Lici ----------------------------------------------------------------------------
const focus = ref(null); // 'field' | 'secret' | null
const revealed = ref(false);
const busy = ref(false);
const mood = ref(null); // 'worried' | 'celebrate' | 'wave' | 'thinking'
const look = ref({ x: 0, y: 0 });
let moodTimer = null;

const mascotState = computed(() => {
    if (mood.value) return mood.value;
    if (busy.value) return 'thinking';
    if (focus.value === 'secret') return revealed.value ? 'peek' : 'shy';
    if (focus.value === 'field') return 'watch';
    return 'idle';
});

function setMood(value, duration) {
    window.clearTimeout(moodTimer);
    mood.value = value;
    if (duration) moodTimer = window.setTimeout(() => (mood.value = null), duration);
}

function mascotCenter() {
    const rect = mascotRef.value?.root?.getBoundingClientRect();
    return rect ? { x: rect.left + rect.width / 2, y: rect.top + rect.height * 0.45 } : null;
}

function lookAt(x, y) {
    const center = mascotCenter();
    if (!center) return;
    look.value = { x: (x - center.x) / Math.max(window.innerWidth / 3, 200), y: (y - center.y) / 320 };
}

// La mirada sigue el texto: el punto de escritura aproximado dentro del campo enfocado.
function lookAtField(input) {
    const rect = input.getBoundingClientRect();
    const typed = Math.min((input.value?.length ?? 0) * 7.4, rect.width - 40);
    lookAt(rect.left + 16 + typed, rect.top + rect.height / 2);
}

function onFocusIn(event) {
    const input = event.target;
    if (!(input instanceof HTMLInputElement || input instanceof HTMLSelectElement) || input.type === 'checkbox') return;
    focus.value = input.dataset.secret !== undefined ? 'secret' : 'field';
    lookAtField(input);
}
function onFocusOut() {
    window.requestAnimationFrame(() => {
        const active = document.activeElement;
        if (!(active instanceof HTMLInputElement || active instanceof HTMLSelectElement) || !scene.value?.contains(active)) focus.value = null;
    });
}
function onInput(event) {
    if (event.target instanceof HTMLInputElement && event.target.type !== 'checkbox') lookAtField(event.target);
}

// Escritorio: sin campo enfocado, la mirada y los manchones siguen al puntero (parallax suave).
let frame = 0;
let pointer = null;
function onPointerMove(event) {
    if (mobile.value || event.pointerType !== 'mouse') return;
    pointer = { x: event.clientX, y: event.clientY };
    if (frame) return;
    frame = window.requestAnimationFrame(() => {
        frame = 0;
        if (!pointer || !scene.value) return;
        if (!focus.value) lookAt(pointer.x, pointer.y);
        const dx = pointer.x / window.innerWidth - 0.5;
        const dy = pointer.y / window.innerHeight - 0.5;
        scene.value.querySelectorAll('[data-depth]').forEach((element) => {
            const depth = Number(element.dataset.depth) || 1;
            element.style.translate = `${(dx * 22 * depth).toFixed(1)}px ${(dy * 16 * depth).toFixed(1)}px`;
        });
    });
}

// ---- Caras -------------------------------------------------------------------------------------
function changeFace(next) {
    if (next === face.value) return;
    face.value = next;
    focus.value = null;
    setMood(null);
    // Solo cambia la URL (sin pedir nada al servidor) para poder enlazar cada cara.
    router.replace({ url: URLS[next], preserveState: true, preserveScroll: true, props: (current) => ({ ...current, mode: next }) });
}

function onSettled(next) {
    announcement.value = next === 'register' ? 'Formulario para solicitar acceso' : 'Formulario para ingresar';
}

function toLogin(email) {
    if (typeof email === 'string' && email) prefill.value = email;
    changeFace('login');
}

function onFailed(kind) {
    if (kind === 'pending' || kind === 'rejected') setMood('thinking', 1800);
    else setMood('worried', 1300);
}

function onSent() {
    setMood('celebrate', 2800);
    nextTick(() => confetti(mascotRef.value?.root));
}

function onDrag(progress) {
    if (mobile.value && !focus.value) look.value = { x: progress * 2 - 1, y: 0 };
}

function themeToggle() {
    dark.value = toggleTheme() === 'dark';
}

// ---- Salida animada al entrar ------------------------------------------------------------------
// Antes de que Inertia cambie a la página de destino, la tarjeta se recoge, Lici saluda y la
// cortina cubre la pantalla; el cambio ocurre como mucho ~0,9 s después de la respuesta.
let removeInterceptor = null;
function isLoginSuccess(visit, response) {
    const url = typeof visit.url === 'string' ? new URL(visit.url, window.location.origin) : visit.url;
    return visit.method === 'post' && url?.pathname === '/login' && response?.data?.component && response.data.component !== 'Auth/Login';
}

onMounted(() => {
    dark.value = document.documentElement.classList.contains('dark');
    window.addEventListener('pointermove', onPointerMove, { passive: true });
    removeInterceptor = interceptors.onVisitResponse(async (visit, response) => {
        if (!isLoginSuccess(visit, response)) return response;
        const name = String(response.data.props?.auth?.user?.name ?? '').trim().split(/\s+/)[0] ?? '';
        setMood('wave');
        const exit = playLoginExit({ card: cardRef.value?.root, mascot: mascotRef.value?.root, name });
        const warm = Promise.resolve(PAGES[`../${response.data.component}.vue`]?.()).catch(() => null);
        await Promise.all([exit.ready, Promise.race([warm, new Promise((resolve) => window.setTimeout(resolve, 1200))])]);
        const off = router.on('navigate', () => {
            off();
            exit.finish();
        });
        window.setTimeout(() => exit.finish(), 2500);
        return response;
    });
});

onBeforeUnmount(() => {
    window.removeEventListener('pointermove', onPointerMove);
    window.cancelAnimationFrame(frame);
    window.clearTimeout(moodTimer);
    removeInterceptor?.();
});
</script>

<template>
    <Head :title="TITLES[face]" />
    <main ref="scene" class="auth" :class="{ 'auth--mobile': mobile }" @focusin="onFocusIn" @focusout="onFocusOut" @input="onInput">
        <div class="auth__canvas" aria-hidden="true">
            <span class="auth__blob auth__blob--red" data-depth="0.6" />
            <span class="auth__blob auth__blob--wine" data-depth="1" />
            <span class="auth__blob auth__blob--taupe" data-depth="1.4" />
        </div>

        <BrandPanel :system-name="SYSTEM_NAME" :mark="company.mark" :tagline="TAGLINE" :owner="company.loginOwner" :compact="mobile" />

        <section class="auth__stage">
            <button class="auth__theme" type="button" :aria-label="dark ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro'" @click="themeToggle">
                <i class="mdi" :class="dark ? 'mdi-white-balance-sunny' : 'mdi-weather-night'" aria-hidden="true" />
            </button>

            <div class="auth__column">
                <div class="auth__perch">
                    <Mascot ref="mascotRef" :state="mascotState" :look="look" :size="mobile ? 108 : 144" />
                </div>

                <div class="auth__card-entry">
                    <AuthCard ref="cardRef" :face="face" :mobile="mobile" @update:face="changeFace" @settled="onSettled" @drag="onDrag">
                        <template #login>
                            <LoginForm
                                :system-name="SYSTEM_NAME"
                                :subtitle="company.loginSubtitle"
                                :help-email="helpEmail"
                                :prefill-email="prefill"
                                @switch="changeFace('register')"
                                @busy="busy = $event"
                                @failed="onFailed"
                                @reveal="revealed = $event"
                            />
                        </template>
                        <template #register>
                            <RequestForm :companies="companies" @switch="toLogin" @busy="busy = $event" @failed="onFailed" @sent="onSent" @reveal="revealed = $event" />
                        </template>
                    </AuthCard>
                </div>

                <p v-if="mobile" class="auth__hint" aria-hidden="true"><i class="mdi mdi-gesture-swipe-horizontal" /> Desliza la tarjeta para cambiar</p>
            </div>
        </section>

        <p class="auth-sr-only" aria-live="polite">{{ announcement }}</p>
    </main>
</template>
