// Movimiento entre páginas y del marco.
//
// El marco (`Layouts/AppShell.vue`) es persistente: Inertia lo monta una vez y al navegar solo cambia
// la página de adentro. Por eso aquí ya no hay parches para conservar el estado del menú entre
// montajes. Lo que queda:
//   - el indicador activo del sidebar y de la barra móvil se desliza del ítem anterior al nuevo
//     cuando LLEGA la página (no antes: el menú nunca adelanta a la cabecera);
//   - el contenido de la página entra en cascada (`motion.css`, `.m-page > *`);
//   - durante una visita lenta la página anterior NO se atenúa ni se congela: el marco muestra la
//     barra de progreso y, pasados 300 ms, un esqueleto (`AppShell.vue`, `PageSkeleton.vue`);
//   - las cifras grandes cuentan al aparecer, la cabecera gana sombra al bajar y la campana se agita
//     con avisos nuevos.
// Las visitas que conservan estado (filtros, paginación, recargas parciales) no animan nada.
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, watch } from 'vue';
import { pop, ring } from '@/lib/motion/animate.js';
import { endFirstLoad, isFirstLoad, isReducedMotion } from '@/lib/motion/preference.js';
import { DURATION, EASE } from '@/lib/motion/tokens.js';
import { countUpText } from '@/lib/motion/useCountUp.js';

const ACTIVE = '[aria-current="page"], [data-active="true"]';
const INDICATORS = [
    { container: '.cap-shell-sidebar__nav', items: '.cap-shell-sidebar__item, .cap-shell-sidebar__child' },
    { container: '.cap-shell-tabbar', items: '.cap-shell-tabbar__tab' },
];

const pathOf = (url) => new URL(url, window.location.href).pathname;

/** Visita a OTRA página (no recargas parciales, ni envíos, ni filtros que conservan estado). */
export function isPageVisit(visit) {
    if (!visit || visit.prefetch) return false;
    if (String(visit.method ?? 'get').toLowerCase() !== 'get') return false;
    if (visit.only?.length || visit.except?.length) return false;
    if (visit.preserveState === true) return false;
    return pathOf(visit.url) !== window.location.pathname;
}

// ───────────────────────── Indicador que se desliza ─────────────────────────

const relativeRect = (container, element) => {
    const box = container.getBoundingClientRect();
    const rect = element.getBoundingClientRect();
    return { left: rect.left - box.left + container.scrollLeft, top: rect.top - box.top + container.scrollTop, width: rect.width, height: rect.height };
};

/** El indicador visible de un contenedor: el más específico (un hijo antes que su grupo). */
function activeIn(box, items) {
    const candidates = [...box.querySelectorAll(`:is(${items}):is(${ACTIVE})`)].filter((element) => element.getClientRects().length);
    return candidates.at(-1) ?? null;
}

/** Copia la pintura del ítem activo (fondo, borde, sombra y la barra `::before`) a un fantasma. */
function makeGhost(container, target) {
    const style = getComputedStyle(target);
    const ghost = document.createElement('span');
    ghost.className = 'm-nav-ghost';
    ghost.setAttribute('aria-hidden', 'true');
    Object.assign(ghost.style, {
        background: style.backgroundColor,
        backgroundImage: style.backgroundImage,
        border: `${style.borderTopWidth} solid ${style.borderTopColor}`,
        borderRadius: style.borderRadius,
        boxShadow: style.boxShadow,
    });
    const bar = getComputedStyle(target, '::before');
    if (bar.content && bar.content !== 'none' && bar.content !== 'normal' && bar.position === 'absolute') {
        const line = document.createElement('span');
        Object.assign(line.style, {
            position: 'absolute',
            top: bar.top,
            bottom: bar.bottom,
            left: bar.left,
            width: bar.width,
            borderRadius: bar.borderRadius,
            background: bar.backgroundColor,
        });
        ghost.appendChild(line);
    }
    if (getComputedStyle(container).position === 'static') container.style.position = 'relative';
    container.prepend(ghost);
    return ghost;
}

function slide(container, target, from) {
    if (!container || !target || !from) return;
    const to = relativeRect(container, target);
    const dx = from.left - to.left;
    const dy = from.top - to.top;
    if (Math.abs(dx) < 1 && Math.abs(dy) < 1) return;
    container.querySelectorAll(':scope > .m-nav-ghost').forEach((old) => old.remove());
    const ghost = makeGhost(container, target);
    Object.assign(ghost.style, { left: `${to.left}px`, top: `${to.top}px`, width: `${to.width}px`, height: `${to.height}px` });
    target.setAttribute('data-m-ghosting', '');
    const sx = to.width ? from.width / to.width : 1;
    const sy = to.height ? from.height / to.height : 1;
    const animation = ghost.animate(
        [
            { transformOrigin: '0 0', transform: `translate(${dx}px, ${dy}px) scale(${sx}, ${sy})` },
            { transformOrigin: '0 0', transform: 'none' },
        ],
        { duration: DURATION.spring, easing: EASE.spring, fill: 'both' },
    );
    const done = () => {
        target.removeAttribute('data-m-ghosting');
        ghost.remove();
    };
    animation.finished.then(done, done);
}

/** Posición de los indicadores activos antes de que cambie la página. */
export function captureIndicators() {
    return INDICATORS.map(({ container, items }) => {
        const box = document.querySelector(container);
        const active = box && activeIn(box, items);
        return active ? relativeRect(box, active) : null;
    });
}

/** Desliza los indicadores desde `from` (de captureIndicators) hasta el ítem activo actual. */
export function slideIndicators(from) {
    if (!from || isReducedMotion()) return;
    INDICATORS.forEach(({ container, items }, index) => {
        const box = document.querySelector(container);
        const active = box && activeIn(box, items);
        if (active && from[index]) slide(box, active, from[index]);
    });
    const tab = document.querySelector('.cap-shell-tabbar__tab[aria-current="page"] .mdi');
    if (tab) pop(tab, 1.18);
}

// ───────────────────────── Visitas de Inertia ─────────────────────────

let installed = false;

export function installPageTransitions() {
    if (installed || typeof window === 'undefined') return;
    installed = true;

    // Carga completa: sin entrada animada ni conteo hasta la primera navegación (LCP).
    document.documentElement.setAttribute('data-first-load', '');
    const initialPath = window.location.pathname;
    router.on('navigate', (event) => {
        if (isFirstLoad() && pathOf(event.detail.page?.url ?? '/') !== initialPath) endFirstLoad();
    });

    // Éxito de un envío: el botón que lo disparó muestra un check (si sigue en pantalla).
    let lastSubmitter = null;
    document.addEventListener(
        'click',
        (event) => {
            const button = event.target.closest?.('.cap-button');
            if (button) lastSubmitter = { button, at: performance.now() };
        },
        true,
    );
    let lastMethod = 'get';
    router.on('before', (event) => {
        lastMethod = String(event.detail.visit?.method ?? 'get').toLowerCase();
    });
    router.on('success', () => {
        if (lastMethod === 'get' || !lastSubmitter || performance.now() - lastSubmitter.at > 15000) return;
        markSuccess(lastSubmitter.button);
        lastSubmitter = null;
    });
}

/** Marca un botón con el check animado de éxito durante un momento. */
export function markSuccess(button) {
    if (!button?.isConnected || button.getAttribute('href')) return;
    button.setAttribute('data-m-success', '');
    setTimeout(() => button.removeAttribute('data-m-success'), 1100);
}

// ───────────────────────── Marco ─────────────────────────

// Cifras grandes de las páginas (StatCard con `value`, contadores de tablero): cuentan al aparecer,
// también las que llegan después con props diferidas. Las de `count`/`v-count` ya cuentan solas.
const FIGURES = '.font-display.tabular-nums:not([data-cap-count]):not([data-m-count])';

function watchFigures(root) {
    if (!root) return () => {};
    const counted = new WeakSet();
    const stops = [];
    const scan = (scope) => {
        const found = scope.matches?.(FIGURES) ? [scope] : [...(scope.querySelectorAll?.(FIGURES) ?? [])];
        found.forEach((element, index) => {
            if (counted.has(element)) return;
            counted.add(element);
            stops.push(countUpText(element, { delay: 120 + Math.min(index, 8) * 40 }));
        });
    };
    scan(root);
    const observer = new MutationObserver((records) => records.forEach((record) => record.addedNodes.forEach((node) => node.nodeType === 1 && scan(node))));
    observer.observe(root, { childList: true, subtree: true });
    return () => {
        observer.disconnect();
        stops.forEach((stop) => stop());
    };
}

/**
 * Movimiento del marco persistente: sombra de la cabecera al hacer scroll, cifras que cuentan y
 * campana. `unread` es una función que devuelve los avisos sin leer. Se llama una vez, en AppShell.
 */
export function useShellMotion({ unread = () => 0 } = {}) {
    let onScroll = null;
    let stopFigures = () => {};
    let lastUnread = null;

    onMounted(() => {
        stopFigures = watchFigures(document.querySelector('.cap-shell__content'));

        const headers = () => document.querySelectorAll('.cap-shell-header, .cap-shell-topbar');
        onScroll = () => {
            const scrolled = window.scrollY > 8;
            headers().forEach((header) => header.toggleAttribute('data-m-scrolled', scrolled));
        };
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });

        const count = unread();
        if (count > 0) {
            try {
                if (!sessionStorage.getItem('m-bell-rung')) {
                    sessionStorage.setItem('m-bell-rung', '1');
                    setTimeout(ringBell, 900);
                }
            } catch {
                // Sin almacenamiento de sesión no se agita al entrar.
            }
        }
        lastUnread = count;
    });

    watch(unread, (count) => {
        if (lastUnread !== null && count > lastUnread) ringBell();
        lastUnread = count;
    });

    onBeforeUnmount(() => {
        stopFigures();
        if (onScroll) window.removeEventListener('scroll', onScroll);
    });
}

export function ringBell() {
    document.querySelectorAll('.mdi-bell-outline').forEach((icon) => {
        if (icon.closest('.cap-shell-header, .cap-shell-topbar')) ring(icon);
    });
    document.querySelectorAll('.cap-shell-header__badge-dot').forEach((dot) => pop(dot, 1.6));
}
