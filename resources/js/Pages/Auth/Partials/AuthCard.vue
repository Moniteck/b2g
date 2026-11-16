<script setup>
// Tarjeta de vidrio con dos caras: «Ingresar» y «Crear cuenta».
//  - Escritorio: se voltea en 3D (gira hasta quedar de canto, cambia de cara y termina el giro).
//  - Celular: las caras van una junto a otra y se pasa de derecha a izquierda con el dedo (el
//    arrastre sigue al dedo y vuelve si no pasa el umbral) o con las pestañas.
//  - Movimiento reducido: un fundido corto, sin giro ni desplazamiento.
// La cara oculta queda `inert` y `aria-hidden`; al cambiar, el foco pasa al título de la nueva cara.
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { prefersReducedMotion } from '@/Modules/Auth/motion.js';
import { useSwipe } from '@/Modules/Auth/useSwipe.js';

const FACES = ['login', 'register'];
const LABELS = { login: 'Ingresar', register: 'Crear cuenta' };

const props = defineProps({
    face: { type: String, default: 'login' },
    mobile: { type: Boolean, default: false },
});
const emit = defineEmits(['update:face', 'settled', 'drag']);

const root = ref(null);
const track = ref(null);
const faceEls = reactive({ login: null, register: null });
const heights = reactive({ login: 0, register: 0 });
const shown = ref(props.face);
const moving = ref(false);
defineExpose({ root });

const index = computed(() => FACES.indexOf(shown.value));
const canGo = (direction) => (direction === 'next' ? index.value < FACES.length - 1 : index.value > 0);
const swipe = useSwipe(track, {
    enabled: () => props.mobile,
    canGo,
    onSwipe: (direction) => go(FACES[index.value + (direction === 'next' ? 1 : -1)]),
});

const width = ref(0);
const progress = computed(() => {
    const raw = index.value - (width.value ? swipe.offset.value / width.value : 0);
    return Math.max(0, Math.min(1, raw));
});
watch(progress, (value) => emit('drag', value));

const trackStyle = computed(() => (props.mobile ? { transform: `translate3d(calc(${-index.value * 100}% + ${swipe.offset.value}px), 0, 0)` } : undefined));
const viewportStyle = computed(() => {
    if (!props.mobile) return undefined;
    const height = swipe.dragging.value || moving.value ? Math.max(heights.login, heights.register) : heights[shown.value];
    return height ? { height: `${height}px` } : undefined;
});
const pillStyle = computed(() => ({ transform: `translateX(${(progress.value * 100).toFixed(2)}%)` }));

function go(next) {
    if (FACES.includes(next) && next !== props.face) emit('update:face', next);
}

function onTabKey(event) {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
    event.preventDefault();
    const next = event.key === 'ArrowLeft' || event.key === 'Home' ? 'login' : 'register';
    go(next);
    nextTick(() => root.value?.querySelector(`#auth-tab-${next}`)?.focus());
}

let queue = Promise.resolve();
watch(
    () => props.face,
    (next) => {
        queue = queue.then(() => transitionTo(next));
    },
);

async function transitionTo(next) {
    if (next === shown.value) return;
    const card = root.value;
    const reduced = prefersReducedMotion();
    moving.value = true;

    if (props.mobile) {
        shown.value = next;
        if (reduced) {
            await faceEls[next]?.animate?.([{ opacity: 0 }, { opacity: 1 }], { duration: 200, easing: 'linear' }).finished.catch(() => {});
        } else {
            await new Promise((resolve) => window.setTimeout(resolve, 440));
        }
    } else if (reduced || !card?.animate) {
        await card?.animate?.([{ opacity: 1 }, { opacity: 0 }], { duration: 120, easing: 'linear', fill: 'forwards' }).finished.catch(() => {});
        shown.value = next;
        await nextTick();
        await card?.animate?.([{ opacity: 0 }, { opacity: 1 }], { duration: 180, easing: 'linear' }).finished.catch(() => {});
        card?.getAnimations().forEach((animation) => animation.cancel());
    } else {
        const turn = next === 'register' ? 1 : -1;
        card.classList.add('auth-card--turning');
        await card
            .animate(
                [
                    { transform: 'perspective(1600px) rotateY(0deg) scale(1)' },
                    { transform: `perspective(1600px) rotateY(${90 * turn}deg) scale(0.92)` },
                ],
                { duration: 300, easing: 'cubic-bezier(0.45, 0, 0.9, 0.5)', fill: 'forwards' },
            )
            .finished.catch(() => {});
        shown.value = next;
        await nextTick();
        card.getAnimations().forEach((animation) => animation.cancel());
        await card
            .animate(
                [
                    { transform: `perspective(1600px) rotateY(${-90 * turn}deg) scale(0.92)` },
                    { transform: `perspective(1600px) rotateY(${10 * turn}deg) scale(1.01)`, offset: 0.72 },
                    { transform: 'perspective(1600px) rotateY(0deg) scale(1)' },
                ],
                { duration: 560, easing: 'cubic-bezier(0.1, 0.6, 0.3, 1)' },
            )
            .finished.catch(() => {});
        card.classList.remove('auth-card--turning');
    }

    moving.value = false;
    await nextTick();
    faceEls[next]?.querySelector('[data-face-title]')?.focus({ preventScroll: props.mobile });
    emit('settled', next);
}

let observer = null;
onMounted(() => {
    observer = new ResizeObserver(() => {
        FACES.forEach((name) => {
            if (faceEls[name]) heights[name] = faceEls[name].offsetHeight;
        });
        width.value = track.value?.parentElement?.offsetWidth ?? 0;
    });
    FACES.forEach((name) => faceEls[name] && observer.observe(faceEls[name]));
    if (track.value?.parentElement) observer.observe(track.value.parentElement);
});
onBeforeUnmount(() => observer?.disconnect());

const hidden = (name) => name !== shown.value && !(props.mobile && (swipe.dragging.value || moving.value));
</script>

<template>
    <div ref="root" class="auth-card cap-glass--light" :class="mobile ? 'auth-card--slide' : 'auth-card--flip'">
        <div v-if="mobile" class="auth-tabs" role="tablist" aria-label="Elige qué quieres hacer">
            <span class="auth-tabs__pill" :style="pillStyle" aria-hidden="true" />
            <button
                v-for="name in FACES"
                :id="`auth-tab-${name}`"
                :key="name"
                class="auth-tabs__tab"
                type="button"
                role="tab"
                :aria-selected="String(face === name)"
                :aria-controls="`auth-face-${name}`"
                :tabindex="face === name ? 0 : -1"
                @click="go(name)"
                @keydown="onTabKey"
            >
                {{ LABELS[name] }}
            </button>
        </div>

        <div class="auth-card__viewport" :style="viewportStyle">
            <div ref="track" class="auth-card__track" :class="{ 'is-dragging': swipe.dragging.value }" :style="trackStyle">
                <section
                    v-for="name in FACES"
                    :id="`auth-face-${name}`"
                    :key="name"
                    :ref="(element) => (faceEls[name] = element)"
                    class="auth-card__face"
                    :class="{ 'auth-card__face--hidden': !mobile && name !== shown }"
                    :role="mobile ? 'tabpanel' : undefined"
                    :aria-labelledby="mobile ? `auth-tab-${name}` : undefined"
                    :aria-hidden="hidden(name) ? 'true' : undefined"
                    :inert="hidden(name) || (mobile && name !== shown)"
                >
                    <slot :name="name" />
                </section>
            </div>
        </div>
    </div>
</template>
