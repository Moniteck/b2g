<script setup>
// Botón de la pantalla de acceso con micro-interacciones: brillo que cruza al pasar el puntero,
// onda desde el punto del clic y, al cargar, tres puntos que saltan y una barra que avanza.
// Mantiene `cap-button` (píldora del marco) y `aria-busy` para los lectores de pantalla.
import { ref } from 'vue';
import { prefersReducedMotion } from '../../../Modules/Auth/motion.js';

const props = defineProps({
    type: { type: String, default: 'button' },
    variant: { type: String, default: 'primary' },
    loading: { type: Boolean, default: false },
    loadingLabel: { type: String, default: 'Un momento…' },
});

const button = ref(null);

function ripple(event) {
    const element = button.value;
    if (!element?.animate || prefersReducedMotion()) return;
    const rect = element.getBoundingClientRect();
    const size = Math.max(rect.width, rect.height) * 2.2;
    const wave = document.createElement('span');
    wave.className = 'auth-button__ripple';
    wave.style.width = wave.style.height = `${size}px`;
    wave.style.left = `${(event.clientX || rect.left + rect.width / 2) - rect.left - size / 2}px`;
    wave.style.top = `${(event.clientY || rect.top + rect.height / 2) - rect.top - size / 2}px`;
    element.appendChild(wave);
    wave.animate([{ transform: 'scale(0)', opacity: 0.45 }, { transform: 'scale(1)', opacity: 0 }], { duration: 650, easing: 'cubic-bezier(0.2, 0.6, 0.3, 1)' }).finished.finally(() => wave.remove());
}
</script>

<template>
    <button
        ref="button"
        class="cap-button auth-button"
        :class="[`cap-button--${variant}`, `auth-button--${variant}`, { 'is-loading': props.loading }]"
        :type="type"
        :disabled="loading"
        :aria-busy="String(loading)"
        @pointerdown="ripple"
    >
        <span class="auth-button__shine" aria-hidden="true" />
        <span class="auth-button__label"><slot /></span>
        <span v-if="loading" class="auth-button__loading">
            <span class="auth-button__dots" aria-hidden="true"><i /><i /><i /></span>
            {{ loadingLabel }}
        </span>
        <span v-if="loading" class="auth-button__progress" aria-hidden="true" />
    </button>
</template>
