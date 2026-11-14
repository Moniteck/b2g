// Envoltura de la Web Animations API que respeta la preferencia de movimiento.
// La WAAPI no se ve afectada por la regla de `tokens.css` que anula transiciones CSS, así que
// cada llamada decide aquí qué hacer con movimiento reducido:
//   reduced: 'skip' (por defecto: no anima), 'fade' (fundido corto de opacidad) o 'run' (anima igual).
import { DURATION, EASE } from './tokens.js';
import { isReducedMotion } from './preference.js';

/**
 * Anima `element` con `keyframes`. Devuelve la `Animation` o null si no se animó.
 * Solo usar `transform`, `opacity` y, puntualmente, `filter`.
 */
export function play(element, keyframes, { reduced = 'skip', ...options } = {}) {
    if (!element?.animate) return null;
    if (isReducedMotion()) {
        if (reduced === 'skip') return null;
        if (reduced === 'fade') {
            const hasOpacity = (Array.isArray(keyframes) ? keyframes : [keyframes]).some((frame) => 'opacity' in frame);
            if (!hasOpacity) return null;
            return element.animate([{ opacity: 0 }, { opacity: 1 }], { duration: DURATION.fast, easing: 'linear', fill: options.fill });
        }
    }
    return element.animate(keyframes, { duration: DURATION.base, easing: EASE.standard, ...options });
}

/** Sacudida corta horizontal (error de validación). */
export function shake(element) {
    return play(
        element,
        [
            { transform: 'translateX(0)' },
            { transform: 'translateX(-6px)' },
            { transform: 'translateX(5px)' },
            { transform: 'translateX(-3px)' },
            { transform: 'translateX(2px)' },
            { transform: 'translateX(0)' },
        ],
        { duration: 380, easing: EASE.standard },
    );
}

/** Salto pequeño de un contador o insignia. */
export function pop(element, scale = 1.35) {
    return play(element, [{ transform: 'scale(1)' }, { transform: `scale(${scale})` }, { transform: 'scale(1)' }], { duration: DURATION.spring, easing: EASE.spring });
}

/** Campana que se agita (avisos nuevos). */
export function ring(element) {
    return play(
        element,
        [0, 16, -14, 10, -6, 2, 0].map((degrees) => ({ transformOrigin: '50% 0', transform: `rotate(${degrees}deg)` })),
        { duration: 720, easing: 'ease-out' },
    );
}

/** Resalte breve de un elemento recién insertado (fila nueva, tarjeta movida). */
export function highlight(element) {
    return play(element, [{ opacity: 0, transform: 'translateY(-6px) scale(0.98)' }, { opacity: 1, transform: 'none' }], { duration: DURATION.spring, easing: EASE.spring, reduced: 'fade' });
}

/** FLIP: anima un elemento desde el rectángulo `from` hasta donde está ahora. */
export function flipFrom(element, from, options = {}) {
    if (!element || !from) return null;
    const to = element.getBoundingClientRect();
    const dx = from.left - to.left;
    const dy = from.top - to.top;
    const sx = to.width ? from.width / to.width : 1;
    const sy = to.height ? from.height / to.height : 1;
    if (Math.abs(dx) < 1 && Math.abs(dy) < 1 && Math.abs(1 - sx) < 0.01 && Math.abs(1 - sy) < 0.01) return null;
    return play(element, [{ transformOrigin: '0 0', transform: `translate(${dx}px, ${dy}px) scale(${sx}, ${sy})` }, { transformOrigin: '0 0', transform: 'none' }], {
        duration: DURATION.spring,
        easing: EASE.spring,
        ...options,
    });
}
