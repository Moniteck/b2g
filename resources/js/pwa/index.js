// Aplicación instalable (PWA). Se importa una sola vez desde resources/js/app.js.
//
// - Registra public/sw.js solo si el servidor publica <meta name="pwa-sw"> (compilación de
//   producción, sin Vite HMR; ver App\Support\Pwa). Si no, quita un service worker que hubiera
//   quedado de una prueba, para que nunca interfiera con el desarrollo.
// - Aviso «Hay una actualización · Actualizar» y oferta de instalación de una sola vez (PwaPrompts.vue).
// - Al cerrar sesión borra las cachés del service worker y la suscripción push de este navegador.
//   El marco puede llamarlo directamente: `import { clearPwaData } from '@/pwa/pwa/index.js'` o
//   `window.crmPwa.clearPwaData()`. Además se detecta solo al llegar a /login desde la app.
// - Modo standalone: los enlaces externos se abren fuera de la app y la barra del sistema
//   sigue el tema elegido (claro u oscuro).
import { createApp, h } from 'vue';
import { router } from '@inertiajs/vue3';
import motion from '@/lib/motion/index.js';
import PwaPrompts from '@/pwa/PwaPrompts.vue';
import { syncPush } from '@/pwa/push.js';
import { canOfferInstall, detectStandalone, offerAlreadyShown, pwa, rememberOffer } from '@/pwa/state.js';

const THEME_COLORS = { light: '#F1EDE9', dark: '#151312' };
const VISITS_KEY = 'crm-b2g:pwa-visits';
const UPDATE_CHECK_MS = 30 * 60 * 1000;

let authenticated = !isAuthPath(window.location.pathname);
let reloading = false;
let lastUpdateCheck = Date.now();

function isAuthPath(path) {
    return /^\/(login|solicitar-acceso|restablecer-contrasena|olvide-contrasena)(\/|$)/.test(path);
}

/* --------------------------------------------------------------- Service worker */

function watchForUpdates(registration) {
    const flag = () => {
        if (registration.waiting && navigator.serviceWorker.controller) pwa.updateReady = true;
    };
    flag();
    registration.addEventListener('updatefound', () => {
        const worker = registration.installing;
        worker?.addEventListener('statechange', () => {
            if (worker.state === 'installed') flag();
        });
    });
    navigator.serviceWorker.addEventListener('controllerchange', () => {
        // Solo recarga si la persona pidió actualizar (o ya había una versión anterior controlando).
        if (reloading || !pwa.updateReady) return;
        reloading = true;
        window.location.reload();
    });
}

async function registerWorker() {
    if (!('serviceWorker' in navigator)) return;
    const url = document.querySelector('meta[name="pwa-sw"]')?.content;

    if (!url) {
        const registrations = await navigator.serviceWorker.getRegistrations().catch(() => []);
        await Promise.all(registrations.filter((reg) => (reg.active?.scriptURL ?? '').includes('/sw.js')).map((reg) => reg.unregister()));
        return;
    }

    try {
        const registration = await navigator.serviceWorker.register(url, { scope: '/', updateViaCache: 'none' });
        pwa.registration = registration;
        watchForUpdates(registration);
        if (authenticated) syncPush();
    } catch {
        // Sin HTTPS o bloqueado por el navegador: la app sigue funcionando como web normal.
    }

    navigator.serviceWorker.addEventListener('message', (event) => {
        if (event.data?.type === 'OPEN_URL' && typeof event.data.url === 'string' && event.data.url.startsWith('/')) {
            router.visit(event.data.url);
        }
    });
}

/** Borra las cachés del service worker y la suscripción push de este navegador (cerrar sesión). */
export async function clearPwaData() {
    try {
        navigator.serviceWorker?.controller?.postMessage({ type: 'CLEAR_CACHES' });
        if ('caches' in window) {
            const names = await caches.keys();
            await Promise.all(names.filter((name) => name.startsWith('crm-b2g-') && !name.startsWith('crm-b2g-assets-')).map((name) => caches.delete(name)));
        }
        // En un equipo compartido, la siguiente persona no debe recibir los avisos de la anterior.
        // El servidor borra la suscripción en el próximo envío (410).
        const registration = pwa.registration ?? (await navigator.serviceWorker?.getRegistration('/'));
        const subscription = await registration?.pushManager?.getSubscription();
        await subscription?.unsubscribe();
    } catch {
        // Nada que limpiar.
    }
}

/* --------------------------------------------------------------- Instalación */

function countVisit() {
    try {
        const visits = Number(localStorage.getItem(VISITS_KEY) || 0) + 1;
        localStorage.setItem(VISITS_KEY, String(visits));
        return visits;
    } catch {
        return 0;
    }
}

function maybeOffer() {
    // Una sola vez, desde la segunda visita, nunca en las pantallas de acceso.
    if (!authenticated || offerAlreadyShown() || !canOfferInstall()) return;
    if (countVisit() < 2) return;
    window.setTimeout(() => {
        if (canOfferInstall() && authenticated) pwa.offerVisible = true;
    }, 4000);
}

window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    pwa.installEvent = event;
    maybeOffer();
});

window.addEventListener('appinstalled', () => {
    pwa.installed = true;
    pwa.installEvent = null;
    rememberOffer('installed');
});

/* --------------------------------------------------------------- Standalone */

function syncThemeColor() {
    const color = document.documentElement.classList.contains('dark') ? THEME_COLORS.dark : THEME_COLORS.light;
    document.querySelectorAll('meta[name="theme-color"]').forEach((meta) => meta.setAttribute('content', color));
}

function openExternalLinksOutside(event) {
    if (!pwa.standalone || event.defaultPrevented) return;
    const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
    if (!link || link.target || link.hasAttribute('download')) return;
    const url = new URL(link.href, window.location.href);
    if (!/^https?:$/.test(url.protocol) || url.origin === window.location.origin) return;
    link.target = '_blank';
    link.rel = `${link.rel} noopener noreferrer`.trim();
}

/* --------------------------------------------------------------- Arranque */

function onNavigate(event) {
    const url = event.detail?.page?.url ?? window.location.pathname;
    const wasAuthenticated = authenticated;
    authenticated = !isAuthPath(new URL(url, window.location.origin).pathname);
    if (wasAuthenticated && !authenticated) {
        pwa.offerVisible = false;
        clearPwaData();
    }
    if (authenticated && pwa.registration && Date.now() - lastUpdateCheck > UPDATE_CHECK_MS) {
        lastUpdateCheck = Date.now();
        pwa.registration.update().catch(() => undefined);
    }
}

function boot() {
    detectStandalone();
    window.matchMedia?.('(display-mode: standalone)').addEventListener?.('change', detectStandalone);
    syncThemeColor();
    new MutationObserver(syncThemeColor).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    document.addEventListener('click', openExternalLinksOutside, true);
    router.on('navigate', onNavigate);

    const host = document.createElement('div');
    host.id = 'pwa-prompts';
    document.body.appendChild(host);
    createApp({ render: () => h(PwaPrompts) }).use(motion).mount(host);

    if (pwa.isIosSafari) maybeOffer();
    if (document.readyState === 'complete') registerWorker();
    else window.addEventListener('load', registerWorker, { once: true });
}

window.crmPwa = { clearPwaData, state: pwa };
boot();
