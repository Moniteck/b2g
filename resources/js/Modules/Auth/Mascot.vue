<script setup>
// «Lici», el búho con lentes de la pantalla de acceso. Personaje propio dibujado en SVG con los
// colores de la marca (variables --cap-*), así cambia solo con la marca instalada.
//
//   <Mascot state="watch" :look="{ x: 0.4, y: -0.2 }" />
//
// Estados: idle (respira y parpadea), watch (sigue con la mirada `look` e inclina la cabeza),
// shy (se tapa los ojos), peek (espía con un ojo), worried (se preocupa y sacude), thinking (espera),
// happy (se alegra), surprised (se sorprende), sleepy (se duerme si nadie hace nada), celebrate
// (salta y celebra) y wave (saluda). Estados de asistente (Agente Legal): reading (lee un libro de
// leyes), writing (escribe con pluma) y searching (busca con una lupa). Las alas, los ojos y la cabeza pasan de un estado a otro con
// transiciones. Los estilos viven en mascot.css (no son `scoped`, para que la cortina de salida y la
// intro puedan clonar el SVG fuera de Vue); auth.css repite los de acceso con los mismos valores.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import './mascot.css';
import { prefersReducedMotion } from './motion.js';

const props = defineProps({
    state: { type: String, default: 'idle' },
    look: { type: Object, default: () => ({ x: 0, y: 0 }) },
    size: { type: Number, default: 132 },
    label: { type: String, default: 'Lici, el búho de la pantalla de acceso' },
});

const root = ref(null);
defineExpose({ root });

const FOLLOWS = ['watch', 'peek', 'idle', 'happy', 'worried'];
const clamp = (value) => Math.max(-1, Math.min(1, Number(value) || 0));
const lookX = computed(() => (FOLLOWS.includes(props.state) ? clamp(props.look.x) : 0));
const lookY = computed(() => (FOLLOWS.includes(props.state) ? clamp(props.look.y) : 0));
const pupils = computed(() => {
    if (props.state === 'thinking') return 'translate(-3px, -4px)';
    if (props.state === 'sleepy') return 'translate(0px, 3px)';
    if (['reading', 'writing'].includes(props.state)) return 'translate(0px, 4px)';
    return `translate(${(lookX.value * 4.5).toFixed(2)}px, ${(lookY.value * 3.5).toFixed(2)}px)`;
});
const face = computed(() => `translate(${(lookX.value * 2).toFixed(2)}px, ${(lookY.value * 1.5).toFixed(2)}px)`);
const tilt = computed(() => {
    if (props.state === 'sleepy') return 'rotate(7deg)';
    if (props.state === 'thinking') return 'rotate(-6deg)';
    return `rotate(${(lookX.value * 7).toFixed(2)}deg)`;
});
const happy = computed(() => ['celebrate', 'wave', 'happy'].includes(props.state));

// Parpadeo con la Web Animations API cada 3-6 s (no una animación CSS infinita que repinte el SVG
// en cada fotograma). No parpadea con la pestaña oculta, con movimiento reducido ni con los ojos
// tapados o dormida.
let blinkTimer = 0;
function scheduleBlink() {
    blinkTimer = window.setTimeout(blink, 3000 + Math.random() * 3000);
}
function blink() {
    if (!document.hidden && !prefersReducedMotion() && !['shy', 'sleepy', 'celebrate', 'wave', 'happy'].includes(props.state)) {
        root.value?.querySelectorAll('.lici__lid').forEach((lid) =>
            lid.animate?.([{ transform: 'scaleY(0)' }, { transform: 'scaleY(1)', offset: 0.45 }, { transform: 'scaleY(0)' }], { duration: 190, easing: 'ease-in-out' }),
        );
    }
    scheduleBlink();
}
onMounted(scheduleBlink);
onBeforeUnmount(() => window.clearTimeout(blinkTimer));
</script>

<template>
    <svg
        ref="root"
        class="lici"
        :class="`lici--${state}`"
        :width="size"
        :height="Math.round(size * 0.94)"
        viewBox="0 0 160 150"
        role="img"
        :aria-label="label"
        focusable="false"
    >
        <ellipse class="lici__shadow" cx="80" cy="145" rx="40" ry="4" />
        <g class="lici__hop">
            <g class="lici__tilt" :style="{ transform: tilt }">
                <g class="lici__body">
                    <path class="lici__tuft lici__tuft--left" d="M42 58 L30 20 L64 46 Z" />
                    <path class="lici__tuft lici__tuft--right" d="M118 58 L130 20 L96 46 Z" />
                    <ellipse class="lici__fill-brand" cx="80" cy="92" rx="54" ry="51" />
                    <ellipse class="lici__fill-belly" cx="80" cy="112" rx="33" ry="30" />
                    <path class="lici__feather" d="M68 108 q4 4 8 0 M84 108 q4 4 8 0 M76 120 q4 4 8 0" />
                    <path class="lici__tie" d="M80 101 L68 94 L68 108 Z M80 101 L92 94 L92 108 Z" />
                    <circle class="lici__tie-knot" cx="80" cy="101" r="3.2" />
                    <ellipse class="lici__foot" cx="64" cy="141" rx="9" ry="4.5" />
                    <ellipse class="lici__foot" cx="96" cy="141" rx="9" ry="4.5" />
                </g>

                <g class="lici__face" :style="{ transform: face }">
                    <circle class="lici__disc" cx="60" cy="70" r="23" />
                    <circle class="lici__disc" cx="100" cy="70" r="23" />

                    <g class="lici__eyes" :class="{ 'lici__eyes--hidden': happy }">
                        <circle class="lici__sclera" cx="60" cy="70" r="13.5" />
                        <circle class="lici__sclera" cx="100" cy="70" r="13.5" />
                        <g class="lici__pupils" :style="{ transform: pupils }">
                            <g class="lici__pupil-size">
                                <circle class="lici__pupil" cx="60" cy="71" r="6.5" />
                                <circle class="lici__pupil" cx="100" cy="71" r="6.5" />
                                <circle class="lici__glint" cx="62.5" cy="68" r="2.1" />
                                <circle class="lici__glint" cx="102.5" cy="68" r="2.1" />
                            </g>
                        </g>
                        <ellipse class="lici__lid" cx="60" cy="70" rx="14.5" ry="14.5" />
                        <ellipse class="lici__lid" cx="100" cy="70" rx="14.5" ry="14.5" />
                    </g>
                    <path class="lici__happy" :class="{ 'lici__happy--on': happy }" d="M50 73 q10 -12 20 0 M90 73 q10 -12 20 0" />

                    <g class="lici__glasses">
                        <circle cx="60" cy="70" r="17" />
                        <circle cx="100" cy="70" r="17" />
                        <path d="M77 69 q3 -5 6 0 M43 66 L36 62 M117 66 L124 62" />
                        <path class="lici__lens-glint" d="M50 60 q4 -4 9 -4 M90 60 q4 -4 9 -4" />
                    </g>
                    <path class="lici__brow" d="M45 53 L67 45 M115 53 L93 45" />
                    <path class="lici__brow-up" d="M46 47 q14 -9 26 -2 M114 47 q-14 -9 -26 -2" />
                    <path class="lici__beak" d="M73 84 Q80 80 87 84 L80 97 Z" />
                    <ellipse class="lici__mouth" cx="80" cy="101" rx="4" ry="5" />
                    <ellipse class="lici__cheek" cx="43" cy="88" rx="6" ry="3.5" />
                    <ellipse class="lici__cheek" cx="117" cy="88" rx="6" ry="3.5" />
                    <path class="lici__sweat" d="M128 46 q5 8 0 11 q-5 -3 0 -11 Z" />
                    <g class="lici__dots">
                        <circle cx="130" cy="30" r="3" />
                        <circle cx="140" cy="22" r="3.6" />
                        <circle cx="151" cy="13" r="4.2" />
                    </g>
                    <g class="lici__zzz">
                        <text x="122" y="40">z</text>
                        <text x="134" y="26">z</text>
                        <text x="146" y="12">Z</text>
                    </g>
                    <g class="lici__sparkles">
                        <path d="M20 40 l3 -9 l3 9 l9 3 l-9 3 l-3 9 l-3 -9 l-9 -3 Z" />
                        <path d="M136 52 l2 -6 l2 6 l6 2 l-6 2 l-2 6 l-2 -6 l-6 -2 Z" />
                    </g>
                </g>

                <g class="lici__wing lici__wing--left">
                    <path class="lici__wing-shape" d="M28 82 C29 58 48 42 72 41 C83 41 92 45 98 52 L91 57 L99 63 L91 68 L97 75 L88 78 L91 85 L80 86 C68 95 50 101 34 102 C26 101 24 92 28 82 Z" />
                    <path class="lici__wing-feather" d="M46 58 C58 52 72 52 86 56 M42 72 C56 66 72 66 88 69 M42 86 C56 80 70 80 82 81" />
                </g>
                <g class="lici__wing lici__wing--right">
                    <path class="lici__wing-shape" d="M132 82 C131 58 112 42 88 41 C77 41 68 45 62 52 L69 57 L61 63 L69 68 L63 75 L72 78 L69 85 L80 86 C92 95 110 101 126 102 C134 101 136 92 132 82 Z" />
                    <path class="lici__wing-feather" d="M114 58 C102 52 88 52 74 56 M118 72 C104 66 88 66 72 69 M118 86 C104 80 90 80 78 81" />
                </g>
                <!-- Utilería de los estados de asistente; oculta en el resto. -->
                <g class="lici__prop lici__book" aria-hidden="true">
                    <path class="lici__book-cover" d="M47 109 L80 115 L113 109 L113 138 L80 143 L47 138 Z" />
                    <path class="lici__book-page" d="M80 116 Q65 108 51 111 L51 135 Q66 133 80 139 Z" />
                    <path class="lici__book-page" d="M80 116 Q95 108 109 111 L109 135 Q94 133 80 139 Z" />
                    <path class="lici__book-lines" d="M56 117 L74 120 M56 123 L74 126 M56 129 L70 131 M86 120 L104 117 M86 126 L104 123 M86 131 L100 129" />
                    <path class="lici__book-mark" d="M98 110 L98 121 L101 118 L104 121 L104 110 Z" />
                </g>
                <g class="lici__prop lici__write" aria-hidden="true">
                    <path class="lici__paper" d="M50 114 L106 110 L109 140 L53 143 Z" />
                    <path class="lici__paper-lines" d="M57 121 L99 118 M58 128 L100 125 M59 135 L86 133" />
                    <g class="lici__quill">
                        <path class="lici__quill-vane" d="M113 131 C118 116 128 104 140 98 C136 110 128 122 116 133 Z" />
                        <path class="lici__quill-shaft" d="M108 137 L138 101" />
                    </g>
                </g>
                <g class="lici__prop lici__magnifier" aria-hidden="true">
                    <path class="lici__lens-handle" d="M111 84 L128 104" />
                    <circle class="lici__lens-ring" cx="100" cy="72" r="16" />
                    <path class="lici__lens-shine" d="M91 66 q4 -6 11 -6" />
                </g>
            </g>
        </g>
    </svg>
</template>
