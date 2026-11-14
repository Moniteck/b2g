<script setup>
// Intro al abrir el sistema: ondas de color de la marca que se expanden, el logotipo que se dibuja
// y se rellena, el nombre del sistema letra por letra y Lici que entra saludando; luego todo se
// acerca y se desvanece y revela la pantalla de acceso (que ya está cargada debajo: no bloquea).
// Completa (~2,6 s) la primera vez de la sesión; después, versión breve (~0,9 s). Se salta con un
// clic, una tecla o un toque. Con movimiento reducido no se muestra.
//
//   <IntroSplash :system-name="SYSTEM_NAME" :mark="company.mark" @reveal="…" @done="…" />
import { onBeforeUnmount, onMounted, ref } from 'vue';
import Mascot from './Mascot.vue';

const props = defineProps({
    systemName: { type: String, required: true },
    mark: { type: String, default: '' },
    tagline: { type: String, default: '' },
    brief: { type: Boolean, default: false },
});
const emit = defineEmits(['reveal', 'done']);

const root = ref(null);
const waves = ref([]);
const ring = ref(null);
const disk = ref(null);
const letters = ref([]);
const tag = ref(null);
const lici = ref(null);
const stage = ref(null);
const animations = [];
let revealing = false;
let timer = 0;

const run = (element, keyframes, options) => {
    if (!element?.animate) return null;
    const animation = element.animate(keyframes, { fill: 'both', ...options });
    animations.push(animation);
    return animation;
};

function play() {
    const full = !props.brief;
    const t = (ms) => (full ? ms : ms * 0.35);
    const ease = 'cubic-bezier(0.2, 0.8, 0.2, 1)';
    const spring = 'cubic-bezier(0.3, 1.5, 0.5, 1)';

    waves.value.forEach((wave, index) => run(wave, [{ transform: 'scale(0)', opacity: 0.95 }, { transform: 'scale(1)', opacity: index === 2 ? 0.9 : 0.55 }], { duration: t(1100), delay: t(index * 160), easing: ease }));
    run(ring.value, [{ strokeDashoffset: 1 }, { strokeDashoffset: 0 }], { duration: t(800), delay: t(250), easing: 'cubic-bezier(0.6, 0, 0.3, 1)' });
    run(disk.value, [{ transform: 'scale(0) rotate(-90deg)', opacity: 0 }, { transform: 'scale(1) rotate(0deg)', opacity: 1 }], { duration: t(620), delay: t(850), easing: spring });
    letters.value.forEach((letter, index) =>
        run(letter, [{ transform: 'translateY(0.8em) rotate(8deg)', opacity: 0 }, { transform: 'none', opacity: 1 }], { duration: t(520), delay: t(1050 + index * 45), easing: spring }),
    );
    run(tag.value, [{ opacity: 0, transform: 'translateY(8px)' }, { opacity: 1, transform: 'none' }], { duration: t(500), delay: t(1450), easing: ease });
    run(lici.value, [{ transform: 'translateY(-100vh) rotate(-18deg)' }, { transform: 'translateY(0) rotate(0)' }], { duration: t(760), delay: t(1250), easing: spring });

    timer = window.setTimeout(reveal, t(2150));
}

function reveal() {
    if (revealing) return;
    revealing = true;
    window.clearTimeout(timer);
    animations.forEach((animation) => animation.finish());
    if (root.value) root.value.style.pointerEvents = 'none';
    emit('reveal');
    const out = run(stage.value, [{ transform: 'scale(1)', opacity: 1 }, { transform: 'scale(1.35)', opacity: 0 }], { duration: 520, easing: 'cubic-bezier(0.5, 0, 0.75, 0)' });
    run(root.value, [{ opacity: 1 }, { opacity: 0 }], { duration: 560, delay: 120, easing: 'cubic-bezier(0.4, 0, 1, 1)' });
    const finished = out?.finished ?? Promise.resolve();
    Promise.all([finished, new Promise((resolve) => window.setTimeout(resolve, 700))]).finally(() => emit('done'));
}

function skip() {
    reveal();
}

onMounted(() => {
    play();
    window.addEventListener('keydown', skip, { once: true });
    window.addEventListener('pointerdown', skip, { once: true });
});

onBeforeUnmount(() => {
    window.clearTimeout(timer);
    window.removeEventListener('keydown', skip);
    window.removeEventListener('pointerdown', skip);
    animations.forEach((animation) => animation.cancel());
});
</script>

<template>
    <div ref="root" class="auth-intro" aria-hidden="true">
        <span v-for="index in 3" :key="index" :ref="(element) => (waves[index - 1] = element)" class="auth-intro__wave" :class="`auth-intro__wave--${index}`" />
        <div ref="stage" class="auth-intro__stage">
            <svg class="auth-intro__logo" viewBox="0 0 120 120" width="120" height="120">
                <circle ref="ring" class="auth-intro__ring" cx="60" cy="60" r="54" pathLength="1" />
                <g ref="disk" class="auth-intro__disk">
                    <circle cx="60" cy="60" r="44" />
                    <text x="60" y="60" dominant-baseline="central" text-anchor="middle">{{ mark }}</text>
                </g>
            </svg>
            <p class="auth-intro__name">
                <span v-for="(letter, index) in systemName.split('')" :key="index" :ref="(element) => (letters[index] = element)" class="auth-intro__letter">{{ letter === ' ' ? ' ' : letter }}</span>
            </p>
            <p ref="tag" class="auth-intro__tagline">{{ tagline }}</p>
            <div ref="lici" class="auth-intro__lici"><Mascot state="wave" :size="96" label="" /></div>
        </div>
        <p class="auth-intro__skip">Toca o pulsa una tecla para saltar</p>
    </div>
</template>
