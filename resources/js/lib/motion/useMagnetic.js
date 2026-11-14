// Atracción magnética: el elemento se inclina unos píxeles hacia el puntero mientras está encima.
// Solo con puntero fino (no en táctil) y con movimiento completo. Usa la propiedad `translate`
// para no pisar el `transform` que el elemento ya tenga (presión de botón, hover).
//   const button = ref(null); useMagnetic(button, { strength: 6 });
import { onBeforeUnmount, onMounted, toValue } from 'vue';
import { isReducedMotion } from './preference.js';

const finePointer = () => typeof matchMedia === 'function' && matchMedia('(hover: hover) and (pointer: fine)').matches;

export function bindMagnetic(element, { strength = 6 } = {}) {
    if (!element || !finePointer()) return () => {};
    let frame = 0;
    const move = (event) => {
        if (isReducedMotion()) return;
        cancelAnimationFrame(frame);
        frame = requestAnimationFrame(() => {
            const rect = element.getBoundingClientRect();
            const x = ((event.clientX - rect.left) / rect.width - 0.5) * 2;
            const y = ((event.clientY - rect.top) / rect.height - 0.5) * 2;
            element.style.translate = `${(x * strength).toFixed(2)}px ${(y * strength * 0.6).toFixed(2)}px`;
        });
    };
    const leave = () => {
        cancelAnimationFrame(frame);
        element.style.translate = '';
    };
    element.classList.add('m-magnetic');
    element.addEventListener('pointermove', move);
    element.addEventListener('pointerleave', leave);
    return () => {
        leave();
        element.classList.remove('m-magnetic');
        element.removeEventListener('pointermove', move);
        element.removeEventListener('pointerleave', leave);
    };
}

export function useMagnetic(target, options = {}) {
    let release = () => {};
    onMounted(() => {
        const element = toValue(target);
        release = bindMagnetic(element?.$el ?? element, options);
    });
    onBeforeUnmount(() => release());
}
