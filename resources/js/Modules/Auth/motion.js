// Utilidades de movimiento de la pantalla de acceso. Todo con transform/opacity y la Web Animations
// API, que no depende de las transiciones CSS que `tokens.css` anula con `prefers-reduced-motion`.
import { onBeforeUnmount, ref } from 'vue';

/** Ref reactiva de una media query (`true` si coincide). */
export function useMedia(query) {
    const media = typeof window !== 'undefined' && window.matchMedia ? window.matchMedia(query) : null;
    const matches = ref(media?.matches ?? false);
    const update = (event) => (matches.value = event.matches);
    media?.addEventListener('change', update);
    onBeforeUnmount(() => media?.removeEventListener('change', update));
    return matches;
}

export const prefersReducedMotion = () => typeof window !== 'undefined' && !!window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

/** Lee una variable CSS de la marca ya resuelta (sirve para los colores del confeti). */
export function cssVar(name, element = document.documentElement) {
    return getComputedStyle(element).getPropertyValue(name).trim();
}

/** Sacude un campo con error. Con movimiento reducido no hace nada: el error ya se ve marcado. */
export function shake(element) {
    if (!element?.animate || prefersReducedMotion()) return null;
    return element.animate(
        [
            { transform: 'translateX(0)' },
            { transform: 'translateX(-8px)' },
            { transform: 'translateX(7px)' },
            { transform: 'translateX(-5px)' },
            { transform: 'translateX(3px)' },
            { transform: 'translateX(0)' },
        ],
        { duration: 420, easing: 'cubic-bezier(0.2, 0, 0, 1)' },
    );
}

/** Sacude cada campo marcado con error dentro de `container`. */
export function shakeErrors(container) {
    container?.querySelectorAll('.cap-field--error .cap-field__control').forEach((element) => shake(element));
}

/**
 * Confeti con los colores de la marca desde el centro de `origin`. Se retira solo al terminar.
 * No se lanza con movimiento reducido.
 */
export function confetti(origin, { count = 56 } = {}) {
    if (!origin || prefersReducedMotion() || !document.body.animate) return;

    const colors = ['--cap-red-600', '--cap-red-400', '--cap-red-200', '--cap-wine', '--cap-taupe', '--cap-red-100'].map((name) => cssVar(name)).filter(Boolean);
    const rect = origin.getBoundingClientRect();
    const layer = document.createElement('div');
    layer.className = 'auth-confetti';
    layer.setAttribute('aria-hidden', 'true');
    document.body.appendChild(layer);

    const x = rect.left + rect.width / 2;
    const y = rect.top + rect.height * 0.45;
    let pending = count;

    for (let index = 0; index < count; index++) {
        const piece = document.createElement('span');
        const size = 6 + Math.random() * 7;
        piece.className = `auth-confetti__piece${index % 3 === 0 ? ' auth-confetti__piece--round' : ''}`;
        piece.style.width = `${size}px`;
        piece.style.height = `${index % 3 === 0 ? size : size * 0.45}px`;
        piece.style.left = `${x}px`;
        piece.style.top = `${y}px`;
        piece.style.background = colors[index % colors.length];
        layer.appendChild(piece);

        const angle = -Math.PI / 2 + (Math.random() - 0.5) * Math.PI * 1.25;
        const power = 140 + Math.random() * 220;
        const dx = Math.cos(angle) * power;
        const dy = Math.sin(angle) * power;
        const fall = 260 + Math.random() * 260;
        const spin = (Math.random() - 0.5) * 900;
        const duration = 1300 + Math.random() * 900;

        piece
            .animate(
                [
                    { transform: 'translate(-50%, -50%) translate(0, 0) rotate(0deg) scale(0.6)', opacity: 1 },
                    { transform: `translate(-50%, -50%) translate(${dx}px, ${dy}px) rotate(${spin / 2}deg) scale(1)`, opacity: 1, offset: 0.35 },
                    { transform: `translate(-50%, -50%) translate(${dx * 1.25}px, ${dy + fall}px) rotate(${spin}deg) scale(0.9)`, opacity: 0 },
                ],
                { duration, easing: 'cubic-bezier(0.2, 0.6, 0.35, 1)', fill: 'forwards' },
            )
            .finished.catch(() => {})
            .finally(() => {
                pending -= 1;
                if (pending === 0) layer.remove();
            });
    }
}
