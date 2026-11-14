<script setup>
// «Lici», el búho con lentes de la pantalla de acceso. Personaje propio dibujado en SVG con los
// colores de la marca (variables --cap-*), así cambia solo con la marca instalada.
//
//   <Mascot state="watch" :look="{ x: 0.4, y: -0.2 }" />
//
// Estados: idle (respira y parpadea), watch (sigue con la mirada `look` e inclina la cabeza),
// shy (se tapa los ojos), peek (espía con un ojo), worried (se preocupa y sacude), thinking (espera),
// happy (se alegra), surprised (se sorprende), sleepy (se duerme si nadie hace nada), celebrate
// (salta y celebra) y wave (saluda). Las alas, los ojos y la cabeza pasan de un estado a otro con
// transiciones. Los estilos viven en auth.css (no son `scoped`) para que la cortina de salida y la
// intro puedan clonar el SVG fuera de Vue.
import { computed, ref } from 'vue';

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
    return `translate(${(lookX.value * 4.5).toFixed(2)}px, ${(lookY.value * 3.5).toFixed(2)}px)`;
});
const face = computed(() => `translate(${(lookX.value * 2).toFixed(2)}px, ${(lookY.value * 1.5).toFixed(2)}px)`);
const tilt = computed(() => {
    if (props.state === 'sleepy') return 'rotate(7deg)';
    if (props.state === 'thinking') return 'rotate(-6deg)';
    return `rotate(${(lookX.value * 7).toFixed(2)}deg)`;
});
const happy = computed(() => ['celebrate', 'wave', 'happy'].includes(props.state));
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

                <path class="lici__wing lici__wing--left" d="M32 84 C16 96 16 124 30 136 C40 126 44 104 32 84 Z" />
                <path class="lici__wing lici__wing--right" d="M128 84 C144 96 144 124 130 136 C120 126 116 104 128 84 Z" />
            </g>
        </g>
    </svg>
</template>
