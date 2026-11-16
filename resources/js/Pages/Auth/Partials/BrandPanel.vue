<script setup>
// Panel de marca de la pantalla de acceso: en escritorio, el panel izquierdo del marco (sin foto:
// fondo del marco con manchones que se mueven despacio); en celular, la franja superior.
// Etiqueta de vidrio con logo y sistema, la frase del sistema palabra por palabra y unas fichas de
// actividad que se turnan para mostrar de qué va el CRM.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { prefersReducedMotion } from '@/Components/Mascot/motion.js';

const props = defineProps({
    systemName: { type: String, required: true },
    mark: { type: String, default: '' },
    tagline: { type: String, required: true },
    owner: { type: String, default: '' },
    compact: { type: Boolean, default: false },
});

const ACTIVITY = [
    { icon: 'gavel', title: 'Licitación publicada', detail: 'Presentación de ofertas en 3 días' },
    { icon: 'file-document-check-outline', title: 'Cotización aprobada', detail: 'Lista para enviar a la institución' },
    { icon: 'handshake-outline', title: 'Contrato adjudicado', detail: 'Entregas y cobros en seguimiento' },
    { icon: 'calendar-check-outline', title: 'Plazo cumplido', detail: 'Aclaraciones enviadas a tiempo' },
];
const FLOATING = [
    { icon: 'file-document-outline', x: 70, y: 13, depth: 1.4, delay: 0 },
    { icon: 'scale-balance', x: 84, y: 29, depth: 0.8, delay: 1.2 },
    { icon: 'chart-timeline-variant', x: 80, y: 74, depth: 1.8, delay: 2.1 },
    { icon: 'stamper', x: 9, y: 77, depth: 1.1, delay: 0.6 },
];

const words = computed(() => props.tagline.split(' '));
const current = ref(0);
let timer = null;

onMounted(() => {
    if (props.compact || prefersReducedMotion()) return;
    timer = window.setInterval(() => (current.value = (current.value + 1) % ACTIVITY.length), 3200);
});
onBeforeUnmount(() => window.clearInterval(timer));
</script>

<template>
    <section class="auth-brand" :class="{ 'auth-brand--compact': compact }">
        <div class="auth-brand__blobs" aria-hidden="true">
            <span class="auth-brand__blob auth-brand__blob--red" data-depth="1.2" />
            <span class="auth-brand__blob auth-brand__blob--wine" data-depth="0.7" />
            <span class="auth-brand__blob auth-brand__blob--taupe" data-depth="1.6" />
        </div>

        <div class="auth-brand__tag cap-glass auth-rise" style="--i: 0">
            <span class="cap-shell-logo" aria-hidden="true">{{ mark }}</span>{{ systemName }}
        </div>

        <template v-if="!compact">
            <div class="auth-brand__floating" aria-hidden="true">
                <span
                    v-for="item in FLOATING"
                    :key="item.icon"
                    class="auth-brand__float cap-glass"
                    :data-depth="item.depth"
                    :style="{ left: `${item.x}%`, top: `${item.y}%`, '--float-delay': `${item.delay}s` }"
                >
                    <i class="mdi" :class="`mdi-${item.icon}`" />
                </span>
            </div>

            <div class="auth-brand__center">
                <p class="auth-brand__headline">
                    <template v-for="(word, position) in words" :key="position"><span class="auth-brand__word" :style="{ '--i': position + 2 }">{{ word }}</span>{{ ' ' }}</template>
                </p>

                <div class="auth-brand__ticker" aria-hidden="true">
                    <Transition name="auth-ticker" mode="out-in">
                        <div :key="current" class="auth-brand__activity cap-glass">
                            <span class="auth-brand__activity-icon"><i class="mdi" :class="`mdi-${ACTIVITY[current].icon}`" /></span>
                            <span>
                                <strong>{{ ACTIVITY[current].title }}</strong>
                                <small>{{ ACTIVITY[current].detail }}</small>
                            </span>
                        </div>
                    </Transition>
                </div>
            </div>

            <div v-if="owner" class="auth-brand__quote cap-glass auth-rise" style="--i: 6">
                <small class="auth-brand__owner">{{ owner }}</small>
            </div>
        </template>
    </section>
</template>
