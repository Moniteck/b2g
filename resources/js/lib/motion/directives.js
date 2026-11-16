// Directivas de movimiento, registradas en toda la app por el plugin (`app.use(motion)`).
//
//   v-stagger                 los hijos entran en cascada al montar (máx. 12 animados)
//   v-stagger="clave"         …y otra vez cada vez que cambia la clave (página, filtro)
//   v-reveal                  entra al aparecer en pantalla (una sola vez)
//   v-reveal="{ delay: 80 }"
//   v-count="{ value, format }"   cifra que cuenta; el elemento va vacío en la plantilla
//   v-count="total"
//   v-shake="form.errors.name"    se sacude cuando aparece o cambia el error
//   v-draw                    trazo SVG que se dibuja (pone pathLength=1)
//   v-grow="índice"           barra que crece desde la base, escalonada por índice
//   v-magnetic / v-magnetic="8"   se inclina hacia el puntero
//   v-settle="'.cap-kanban-card'"  los elementos que entran después del montaje se acomodan con resorte
//
// Todas respetan la preferencia de movimiento: con «reducidas» el contenido aparece sin desplazarse.
import { shake } from '@/lib/motion/animate.js';
import { isReducedMotion } from '@/lib/motion/preference.js';
import { STAGGER } from '@/lib/motion/tokens.js';
import { tween, decimalsOf } from '@/lib/motion/useCountUp.js';
import { bindMagnetic } from '@/lib/motion/useMagnetic.js';

function restartStagger(element) {
    const children = [...element.children];
    children.forEach((child, index) => child.style.setProperty('--m-i', String(Math.min(index, STAGGER.max))));
    element.classList.remove('m-stagger');
    // Forzar reflow para reiniciar la animación CSS de los hijos.
    void element.offsetWidth;
    element.classList.add('m-stagger');
}

export const vStagger = {
    mounted(element) {
        restartStagger(element);
    },
    updated(element, binding) {
        if (binding.value !== undefined && binding.value !== binding.oldValue) restartStagger(element);
    },
};

let revealObserver = null;
function observer() {
    if (revealObserver || typeof IntersectionObserver !== 'function') return revealObserver;
    revealObserver = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                entry.target.classList.add('m-reveal--in');
                revealObserver.unobserve(entry.target);
            });
        },
        { rootMargin: '0px 0px -8% 0px', threshold: 0.05 },
    );
    return revealObserver;
}

export const vReveal = {
    mounted(element, binding) {
        if (isReducedMotion() || !observer()) return;
        const delay = binding.value?.delay ?? 0;
        if (delay) element.style.setProperty('--m-delay', `${delay}ms`);
        element.classList.add('m-reveal');
        revealObserver.observe(element);
        // Seguro: nada se queda invisible si el observador no llega a dispararse.
        element._mRevealTimer = setTimeout(() => element.classList.add('m-reveal--in'), 2500);
    },
    unmounted(element) {
        clearTimeout(element._mRevealTimer);
        revealObserver?.unobserve(element);
    },
};

function countOptions(value) {
    return value !== null && typeof value === 'object' ? value : { value };
}

function runCount(element, binding, from) {
    const { value, format = (v) => String(Math.round(v)), duration, delay } = countOptions(binding.value);
    const to = Number(value) || 0;
    const decimals = decimalsOf(to);
    element._mCountCancel?.();
    element._mCountCancel = tween(from, to, (current) => {
        element._mCountValue = current;
        element.textContent = format(current === to ? to : Number(current.toFixed(decimals)));
    }, { duration, delay });
}

export const vCount = {
    mounted(element, binding) {
        element.style.fontVariantNumeric = 'tabular-nums';
        element.dataset.mCount = '';
        runCount(element, binding, 0);
    },
    updated(element, binding) {
        const next = countOptions(binding.value).value;
        const previous = countOptions(binding.oldValue).value;
        if (next !== previous) runCount(element, binding, element._mCountValue ?? 0);
    },
    unmounted(element) {
        element._mCountCancel?.();
    },
};

export const vShake = {
    updated(element, binding) {
        if (binding.value && binding.value !== binding.oldValue) shake(element);
    },
};

export const vDraw = {
    mounted(element) {
        element.setAttribute('pathLength', '1');
        element.classList.add('cap-draw');
    },
};

export const vGrow = {
    mounted(element, binding) {
        element.style.setProperty('--cap-stagger-index', String(Math.min(Number(binding.value) || 0, STAGGER.max)));
        element.classList.add('cap-grow');
    },
};

export const vMagnetic = {
    mounted(element, binding) {
        if (binding.value === false || binding.value === 0) return;
        element._mMagnetic = bindMagnetic(element, { strength: Number(binding.value) || 6 });
    },
    unmounted(element) {
        element._mMagnetic?.();
    },
};

/** Tras el montaje, cada elemento nuevo que coincide con el selector (p. ej. la tarjeta que se soltó en
 *  otra columna del kanban) se acomoda con un resorte (`.m-settle` en motion.css). */
export const vSettle = {
    mounted(element, binding) {
        const selector = binding.value || '.cap-kanban-card';
        element._mSettle = new MutationObserver((records) => {
            if (isReducedMotion()) return;
            records.forEach((record) =>
                record.addedNodes.forEach((node) => {
                    if (node.nodeType !== 1 || !node.matches(selector)) return;
                    node.classList.remove('m-settle');
                    void node.offsetWidth;
                    node.classList.add('m-settle');
                    node.addEventListener('animationend', () => node.classList.remove('m-settle'), { once: true });
                }),
            );
        });
        element._mSettle.observe(element, { childList: true, subtree: true });
    },
    unmounted(element) {
        element._mSettle?.disconnect();
    },
};
