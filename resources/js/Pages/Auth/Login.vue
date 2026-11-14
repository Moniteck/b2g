<script setup>
// Pantalla de acceso animada: «Ingresar» y «Crear cuenta» (solicitud de acceso) en una tarjeta que
// se voltea en escritorio (y se inclina en 3D con el puntero) y se desliza con el dedo en celular.
// Intro de marca al abrir, fondo vivo con partículas y parallax, campos con etiqueta flotante,
// botones con onda y brillo, y Lici (el búho) que sigue lo que escribes, se tapa los ojos con la
// contraseña, se alegra, se sorprende, se preocupa, se duerme si nadie hace nada, celebra la
// solicitud y saluda al entrar. Con `prefers-reduced-motion` no hay intro, partículas, giros ni
// saltos: solo fundidos cortos. Las animaciones se pausan con la pestaña oculta.
//
// Rutas: /login (cara «Ingresar») y /solicitar-acceso (cara «Crear cuenta»); la URL sigue a la cara.
import { interceptors } from '@inertiajs/core';
import { Head, router } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { company } from '../../../design/company.js';
import { toggleTheme } from '../../../design/shell/js/theme.js';
import { SYSTEM_NAME } from '../../config/navigation.js';
import AuthBackdrop from '../../Modules/Auth/AuthBackdrop.vue';
import { playLoginExit } from '../../Modules/Auth/exitCurtain.js';
import IntroSplash from '../../Modules/Auth/IntroSplash.vue';
import Mascot from '../../Modules/Auth/Mascot.vue';
import { confetti, prefersReducedMotion, useMedia } from '../../Modules/Auth/motion.js';
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
const INTRO_KEY = 'crm.acceso.intro';
const SLEEP_AFTER = 15000;

const mobile = useMedia('(max-width: 1023.98px)');
const face = ref(props.mode === 'register' ? 'register' : 'login');
const announcement = ref('');
const prefill = ref('');
const dark = ref(false);
const hiddenTab = ref(false);

// Intro: completa la primera vez de la sesión, breve después; nunca con movimiento reducido.
const intro = ref(null); // null | 'full' | 'brief'
const waiting = ref(false);

// Escena: elementos para la salida animada, la inclinación y la mirada de Lici.
const scene = ref(null);
const tiltEl = ref(null);
const glareEl = ref(null);
const cardRef = ref(null);
const mascotRef = ref(null);

// ---- Estado de Lici ----------------------------------------------------------------------------
const focus = ref(null); // 'field' | 'secret' | null
const revealed = ref(false);
const busy = ref(false);
const mood = ref(null); // estado pasajero: worried, celebrate, wave, thinking, happy, surprised
const sleepy = ref(false);
const look = ref({ x: 0, y: 0 });
let moodTimer = null;
let sleepTimer = null;

const mascotState = computed(() => {
    if (mood.value) return mood.value;
    if (busy.value) return 'thinking';
    if (focus.value === 'secret') return revealed.value ? 'peek' : 'shy';
    if (focus.value === 'field') return 'watch';
    if (sleepy.value) return 'sleepy';
    return 'idle';
});

function setMood(value, duration) {
    window.clearTimeout(moodTimer);
    mood.value = value;
    if (duration) moodTimer = window.setTimeout(() => (mood.value = null), duration);
}

// Si nadie toca nada un rato, Lici se duerme; cualquier gesto la despierta (sorprendida).
let lastWake = 0;
function wake() {
    const now = performance.now();
    if (!sleepy.value && now - lastWake < 1000) return;
    lastWake = now;
    if (sleepy.value) {
        sleepy.value = false;
        if (!mood.value) setMood('surprised', 700);
    }
    window.clearTimeout(sleepTimer);
    sleepTimer = window.setTimeout(() => (sleepy.value = true), SLEEP_AFTER);
}

// Medidas en caché: el puntero no lee el layout en cada evento (eso forzaba un reflow por
// fotograma). Se vuelven a medir al cargar, al cambiar el tamaño, al desplazar y al cambiar de cara.
let rects = null;
let depthItems = [];
function measure() {
    const mascot = mascotRef.value?.root?.getBoundingClientRect();
    const card = tiltEl.value?.getBoundingClientRect();
    rects = {
        mascot: mascot ? { x: mascot.left + mascot.width / 2, y: mascot.top + mascot.height * 0.45 } : null,
        card: card ? { left: card.left, top: card.top, width: card.width, height: card.height } : null,
    };
    depthItems = scene.value ? [...scene.value.querySelectorAll('[data-depth]')].map((element) => ({ element, depth: Number(element.dataset.depth) || 1 })) : [];
}

function mascotCenter() {
    if (!rects) measure();
    return rects?.mascot ?? null;
}

function lookAt(x, y) {
    const center = mascotCenter();
    if (!center) return;
    // Redondeado: Lici solo se vuelve a pintar cuando la mirada cambia de verdad.
    const next = { x: Math.round(((x - center.x) / Math.max(window.innerWidth / 3, 200)) * 25) / 25, y: Math.round(((y - center.y) / 320) * 25) / 25 };
    if (next.x !== look.value.x || next.y !== look.value.y) look.value = next;
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
    wake();
}
function onFocusOut() {
    window.requestAnimationFrame(() => {
        const active = document.activeElement;
        if (!(active instanceof HTMLInputElement || active instanceof HTMLSelectElement) || !scene.value?.contains(active)) focus.value = null;
    });
}
function onInput(event) {
    if (event.target instanceof HTMLInputElement && event.target.type !== 'checkbox') lookAtField(event.target);
    wake();
}

// Escritorio: la mirada, los manchones y la tarjeta (inclinación 3D con brillo) siguen al puntero.
// Un solo requestAnimationFrame con suavizado; nada se recalcula si el puntero no se mueve.
let frame = 0;
let pointer = null;
const tilt = { x: 0, y: 0, tx: 0, ty: 0, gx: 50, gy: 30 };
let pointerDirty = false;
function onPointerMove(event) {
    wake();
    if (mobile.value || event.pointerType !== 'mouse') return;
    pointer = { x: event.clientX, y: event.clientY };
    pointerDirty = true;
    if (!frame) frame = window.requestAnimationFrame(animatePointer);
}

// Todo el trabajo del puntero en un requestAnimationFrame, con lecturas de la caché y sin
// lecturas de layout.
function applyPointer() {
    pointerDirty = false;
    if (!rects) measure();
    if (!focus.value) lookAt(pointer.x, pointer.y);
    const rect = rects?.card;
    if (rect) {
        const inside = Math.abs(pointer.x - (rect.left + rect.width / 2)) < rect.width * 0.75 && Math.abs(pointer.y - (rect.top + rect.height / 2)) < rect.height * 0.75;
        tilt.tx = inside ? ((pointer.y - (rect.top + rect.height / 2)) / rect.height) * -7 : 0;
        tilt.ty = inside ? ((pointer.x - (rect.left + rect.width / 2)) / rect.width) * 9 : 0;
        tilt.gx = ((pointer.x - rect.left) / rect.width) * 100;
        tilt.gy = ((pointer.y - rect.top) / rect.height) * 100;
    }
    parallax.tx = pointer.x / window.innerWidth - 0.5;
    parallax.ty = pointer.y / window.innerHeight - 0.5;
}

// Parallax suavizado aquí (no con transiciones CSS, que se reiniciarían en cada fotograma).
const parallax = { x: 0, y: 0, tx: 0, ty: 0 };
function applyParallax() {
    parallax.x += (parallax.tx - parallax.x) * 0.08;
    parallax.y += (parallax.ty - parallax.y) * 0.08;
    depthItems.forEach(({ element, depth }) => {
        element.style.translate = `${(parallax.x * 22 * depth).toFixed(1)}px ${(parallax.y * 16 * depth).toFixed(1)}px`;
    });
    return Math.abs(parallax.tx - parallax.x) > 0.001 || Math.abs(parallax.ty - parallax.y) > 0.001;
}

function animatePointer() {
    frame = 0;
    if (!scene.value || prefersReducedMotion()) return;
    if (pointer && pointerDirty) applyPointer();
    tilt.x += (tilt.tx - tilt.x) * 0.14;
    tilt.y += (tilt.ty - tilt.y) * 0.14;
    if (tiltEl.value) {
        tiltEl.value.style.transform = `perspective(1400px) rotateX(${tilt.x.toFixed(2)}deg) rotateY(${tilt.y.toFixed(2)}deg)`;
        // El brillo se mueve con transform (una variable CSS heredada recalcularía toda la tarjeta).
        if (glareEl.value && rects?.card) glareEl.value.style.transform = `translate(${((tilt.gx / 100 - 0.5) * rects.card.width).toFixed(0)}px, ${((tilt.gy / 100 - 0.5) * rects.card.height).toFixed(0)}px)`;
    }
    const moving = applyParallax();
    if (moving || Math.abs(tilt.tx - tilt.x) > 0.02 || Math.abs(tilt.ty - tilt.y) > 0.02) frame = window.requestAnimationFrame(animatePointer);
}

function onLeave() {
    tilt.tx = 0;
    tilt.ty = 0;
    if (!frame) frame = window.requestAnimationFrame(animatePointer);
}

// ---- Caras -------------------------------------------------------------------------------------
function changeFace(next) {
    if (next === face.value) return;
    face.value = next;
    focus.value = null;
    setMood('surprised', 650);
    // Solo cambia la URL (sin pedir nada al servidor) para poder enlazar cada cara.
    router.replace({ url: URLS[next], preserveState: true, preserveScroll: true, props: (current) => ({ ...current, mode: next }) });
}

function onSettled(next) {
    measure();
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
    nextTick(() => confetti(mascotRef.value?.root, { count: 72 }));
}

function onCheer() {
    if (!mood.value) setMood('happy', 900);
}

function onDrag(progress) {
    if (mobile.value && !focus.value) look.value = { x: progress * 2 - 1, y: 0 };
}

function themeToggle() {
    dark.value = toggleTheme() === 'dark';
}

function onVisibility() {
    hiddenTab.value = document.hidden;
}

function onRevealIntro() {
    waiting.value = false;
    window.setTimeout(measure, 1300);
}

function onResize() {
    rects = null;
}

function onIntroDone() {
    intro.value = null;
    setMood('wave', 1200);
}

// ---- Salida animada al entrar ------------------------------------------------------------------
// Antes de que Inertia cambie a la página de destino, los campos salen, la tarjeta se recoge, Lici
// saluda y la cortina cubre la pantalla; el cambio ocurre como mucho ~1 s después de la respuesta.
let removeInterceptor = null;
function isLoginSuccess(visit, response) {
    const url = typeof visit.url === 'string' ? new URL(visit.url, window.location.origin) : visit.url;
    return visit.method === 'post' && url?.pathname === '/login' && response?.data?.component && response.data.component !== 'Auth/Login';
}

onMounted(() => {
    dark.value = document.documentElement.classList.contains('dark');
    if (!prefersReducedMotion()) {
        let seen = false;
        try {
            seen = sessionStorage.getItem(INTRO_KEY) === '1';
            sessionStorage.setItem(INTRO_KEY, '1');
        } catch {
            seen = false;
        }
        intro.value = seen ? 'brief' : 'full';
        waiting.value = true;
    }
    wake();
    window.addEventListener('pointermove', onPointerMove, { passive: true });
    window.addEventListener('resize', onResize);
    window.addEventListener('scroll', onResize, { passive: true, capture: true });
    window.setTimeout(measure, 1300);
    window.addEventListener('keydown', wake);
    document.addEventListener('visibilitychange', onVisibility);
    removeInterceptor = interceptors.onVisitResponse(async (visit, response) => {
        if (!isLoginSuccess(visit, response)) return response;
        const name = String(response.data.props?.auth?.user?.name ?? '').trim().split(/\s+/)[0] ?? '';
        setMood('wave');
        const exit = playLoginExit({ card: tiltEl.value, mascot: mascotRef.value?.root, name });
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
    window.removeEventListener('resize', onResize);
    window.removeEventListener('scroll', onResize, { capture: true });
    window.removeEventListener('keydown', wake);
    document.removeEventListener('visibilitychange', onVisibility);
    window.cancelAnimationFrame(frame);
    window.clearTimeout(moodTimer);
    window.clearTimeout(sleepTimer);
    removeInterceptor?.();
});
</script>

<template>
    <Head :title="TITLES[face]" />
    <main
        ref="scene"
        class="auth"
        :class="{ 'auth--mobile': mobile, 'auth--waiting': waiting, 'auth--paused': hiddenTab }"
        @focusin="onFocusIn"
        @focusout="onFocusOut"
        @input="onInput"
    >
        <AuthBackdrop :density="mobile ? 0.6 : 1" />

        <BrandPanel :system-name="SYSTEM_NAME" :mark="company.mark" :tagline="TAGLINE" :owner="company.loginOwner" :compact="mobile" />

        <section class="auth__stage" @pointerleave="onLeave">
            <button class="auth__theme" type="button" :aria-label="dark ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro'" @click="themeToggle">
                <Transition name="auth-icon" mode="out-in"><i :key="String(dark)" class="mdi" :class="dark ? 'mdi-white-balance-sunny' : 'mdi-weather-night'" aria-hidden="true" /></Transition>
            </button>

            <div class="auth__column">
                <div class="auth__perch" data-depth="-0.4">
                    <Mascot ref="mascotRef" :state="mascotState" :look="look" :size="mobile ? 108 : 144" />
                </div>

                <div class="auth__card-entry">
                    <div ref="tiltEl" class="auth__tilt">
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
                                    @valid="onCheer"
                                />
                            </template>
                            <template #register>
                                <RequestForm :companies="companies" @switch="toLogin" @busy="busy = $event" @failed="onFailed" @sent="onSent" @reveal="revealed = $event" @cheer="onCheer" />
                            </template>
                        </AuthCard>
                        <span class="auth__glare-clip" aria-hidden="true"><span ref="glareEl" class="auth__glare" /></span>
                    </div>
                </div>

                <p v-if="mobile" class="auth__hint" aria-hidden="true"><i class="mdi mdi-gesture-swipe-horizontal" /> Desliza la tarjeta para cambiar</p>
            </div>
        </section>

        <p class="auth-sr-only" aria-live="polite">{{ announcement }}</p>

        <IntroSplash v-if="intro" :system-name="SYSTEM_NAME" :mark="company.mark" tagline="Del aviso de licitación al cobro." :brief="intro === 'brief'" @reveal="onRevealIntro" @done="onIntroDone" />
    </main>
</template>
