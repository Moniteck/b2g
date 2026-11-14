// Movimiento entre páginas y del marco.
//
// Cada página de Inertia monta su propio `AppLayout`, así que el marco se vuelve a montar al navegar.
// Para que se sienta continuo:
//   - al empezar una visita a otra página, el contenido actual sale (se atenúa y sube un poco) y el
//     indicador activo del sidebar (y de la barra móvil) empieza a deslizarse hacia el destino;
//   - al montar la página nueva, el contenido entra en cascada (`motion.css`), el indicador termina
//     su recorrido desde donde iba y el scroll del menú se conserva.
// Las visitas que conservan estado (filtros, paginación, recargas parciales) no animan nada.
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, watch } from 'vue';
import { pop, ring } from './animate.js';
import { isReducedMotion } from './preference.js';
import { DURATION, EASE } from './tokens.js';
import { countUpText } from './useCountUp.js';

const ACTIVE = '[aria-current="page"]';
const INDICATORS = [
    { container: '.cap-shell-sidebar__nav', items: '.cap-shell-sidebar__item, .cap-shell-sidebar__child' },
    { container: '.cap-shell-tabbar', items: '.cap-shell-tabbar__tab' },
];

// Estado entre el marco que se va y el que llega (módulo: sobrevive al re-montaje).
let pending = null; // { startedAt, from: [{ index, rect }] , path }
let sidebarScroll = 0;
let lastUnread = null;
let mounted = false;

const pathOf = (url) => new URL(url, window.location.href).pathname;

function isPageVisit(visit) {
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

function slide(container, target, from, elapsed = 0) {
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
    animation.currentTime = Math.min(elapsed, DURATION.spring - 1);
    const done = () => {
        target.removeAttribute('data-m-ghosting');
        ghost.remove();
    };
    animation.finished.then(done, done);
}

function captureIndicators() {
    return INDICATORS.map(({ container, items }) => {
        const box = document.querySelector(container);
        const active = box?.querySelector(`:is(${items})${ACTIVE}`);
        return box && active ? relativeRect(box, active) : null;
    });
}

function startIndicators(path) {
    INDICATORS.forEach(({ container, items }, index) => {
        const box = document.querySelector(container);
        if (!box) return;
        const target = [...box.querySelectorAll(items)].find((item) => item.getAttribute('href') && pathOf(item.getAttribute('href')) === path);
        const active = box.querySelector(`:is(${items})${ACTIVE}`);
        if (target && pending.from[index]) {
            active?.setAttribute('data-m-ghosting', '');
            slide(box, target, pending.from[index]);
        }
    });
    // Píldora de secciones de la cabecera: se mueve sola cuando cambia `aria-current` (pill-nav.js).
    const sections = [...document.querySelectorAll('.cap-shell-header__nav-item')];
    const section = sections.find((item) => item.getAttribute('href') && pathOf(item.getAttribute('href')) === path);
    if (section) {
        pending.sections = sections.map((item) => [item, item.getAttribute('aria-current')]);
        sections.forEach((item) => (item === section ? item.setAttribute('aria-current', 'page') : item.removeAttribute('aria-current')));
    }
}

function finishIndicators() {
    if (!pending || isReducedMotion()) return;
    const elapsed = performance.now() - pending.startedAt;
    INDICATORS.forEach(({ container, items }, index) => {
        const box = document.querySelector(container);
        const active = box?.querySelector(`:is(${items})${ACTIVE}`);
        if (active && pending.from[index]) slide(box, active, pending.from[index], elapsed);
    });
    const tab = document.querySelector(`.cap-shell-tabbar__tab${ACTIVE} .mdi`);
    if (tab) pop(tab, 1.18);
}

function restoreAfterCancel() {
    document.querySelectorAll('[data-m-ghosting]').forEach((element) => element.removeAttribute('data-m-ghosting'));
    document.querySelectorAll('.m-nav-ghost').forEach((ghost) => ghost.remove());
    pending?.sections?.forEach(([item, value]) => (value ? item.setAttribute('aria-current', value) : item.removeAttribute('aria-current')));
}

// ───────────────────────── Visitas de Inertia ─────────────────────────

let installed = false;
let leaving = null;

export function installPageTransitions() {
    if (installed || typeof window === 'undefined') return;
    installed = true;

    router.on('start', (event) => {
        const visit = event.detail.visit;
        if (!isPageVisit(visit)) return;
        const content = document.querySelector('.cap-shell__content');
        sidebarScroll = document.querySelector('.cap-shell-sidebar__nav')?.scrollTop ?? 0;
        pending = { startedAt: performance.now(), from: captureIndicators(), path: pathOf(visit.url) };
        if (isReducedMotion()) return;
        leaving = content;
        content?.setAttribute('data-m-leaving', '');
        startIndicators(pending.path);
    });

    router.on('navigate', () => {
        // Misma página con otros datos (p. ej. ficha → ficha): el contenido no se re-monta; se repite la entrada.
        if (leaving?.isConnected) {
            leaving.removeAttribute('data-m-leaving');
            const page = leaving.querySelector(':scope > .m-page');
            if (page) {
                page.classList.remove('m-page');
                void page.offsetWidth;
                page.classList.add('m-page');
            }
        }
        leaving = null;
    });

    router.on('finish', (event) => {
        const visit = event.detail.visit;
        if (visit.completed) return;
        if (visit.cancelled || visit.interrupted || !visit.completed) {
            leaving?.removeAttribute('data-m-leaving');
            leaving = null;
            restoreAfterCancel();
            pending = null;
        }
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

/**
 * Enlaza el movimiento del marco al `AppLayout` montado: indicadores, sombra de la cabecera al hacer
 * scroll, scroll del menú y campana. `unread` es una función que devuelve los avisos sin leer.
 */
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

export function useShellMotion({ unread = () => 0 } = {}) {
    let onScroll = null;
    let stopFigures = () => {};

    onMounted(() => {
        const nav = document.querySelector('.cap-shell-sidebar__nav');
        if (nav && sidebarScroll) nav.scrollTop = sidebarScroll;
        if (pending) finishIndicators();
        pending = null;

        stopFigures = watchFigures(document.querySelector('.cap-shell__content > .m-page'));

        const headers = document.querySelectorAll('.cap-shell-header, .cap-shell-topbar');
        onScroll = () => {
            const scrolled = window.scrollY > 8;
            headers.forEach((header) => header.toggleAttribute('data-m-scrolled', scrolled));
        };
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });

        const count = unread();
        if (!mounted && count > 0) {
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
        mounted = true;
    });

    watch(unread, (count) => {
        if (lastUnread !== null && count > lastUnread) ringBell();
        lastUnread = count;
    });

    onBeforeUnmount(() => {
        stopFigures();
        if (onScroll) window.removeEventListener('scroll', onScroll);
        sidebarScroll = document.querySelector('.cap-shell-sidebar__nav')?.scrollTop ?? sidebarScroll;
    });
}

export function ringBell() {
    document.querySelectorAll('.mdi-bell-outline').forEach((icon) => {
        if (icon.closest('.cap-shell-header, .cap-shell-topbar')) ring(icon);
    });
    document.querySelectorAll('.cap-shell-header__badge-dot').forEach((dot) => pop(dot, 1.6));
}
