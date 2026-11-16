// Estado compartido de la aplicación instalable (service worker, instalación y modo standalone).
// Lo llena resources/js/pwa/index.js; lo leen PwaPrompts.vue y AccountSection.vue (Mi cuenta).
import { reactive } from 'vue';

const OFFER_KEY = 'crm-b2g:pwa-install-offer';

const ua = typeof navigator === 'undefined' ? '' : navigator.userAgent;
const isIos = /iPhone|iPad|iPod/.test(ua) || (ua.includes('Macintosh') && typeof navigator !== 'undefined' && navigator.maxTouchPoints > 1);
const isSafari = /Safari\//.test(ua) && !/CriOS|FxiOS|EdgiOS|Chrome\//.test(ua);

export const pwa = reactive({
    /** Service worker registrado (solo con compilación de producción). */
    registration: null,
    /** Hay una versión nueva esperando a que la persona pulse «Actualizar». */
    updateReady: false,
    /** Evento `beforeinstallprompt` guardado (Android y escritorio con Chromium). */
    installEvent: null,
    installed: false,
    isIos,
    isIosSafari: isIos && isSafari,
    standalone: false,
    /** Oferta de instalación de una sola vez (tarjeta flotante). */
    offerVisible: false,
});

export function detectStandalone() {
    pwa.standalone = Boolean(window.matchMedia?.('(display-mode: standalone)').matches || window.navigator.standalone === true);
    return pwa.standalone;
}

/** Se puede instalar desde un botón propio (Chromium) o con la guía de iOS. */
export function canOfferInstall() {
    return !pwa.standalone && !pwa.installed && (Boolean(pwa.installEvent) || pwa.isIosSafari);
}

export async function promptInstall() {
    const event = pwa.installEvent;
    if (!event) return 'unavailable';
    pwa.installEvent = null;
    event.prompt();
    const choice = await event.userChoice.catch(() => ({ outcome: 'dismissed' }));
    if (choice.outcome === 'accepted') pwa.installed = true;
    rememberOffer(choice.outcome === 'accepted' ? 'installed' : 'dismissed');
    return choice.outcome;
}

/** «No molestar»: la oferta flotante sale una sola vez; después queda en Mi cuenta. */
export function offerAlreadyShown() {
    try {
        return Boolean(localStorage.getItem(OFFER_KEY));
    } catch {
        return true;
    }
}

export function rememberOffer(outcome) {
    pwa.offerVisible = false;
    try {
        localStorage.setItem(OFFER_KEY, outcome);
    } catch {
        // Modo privado sin almacenamiento: no pasa nada, solo se volvería a ofrecer.
    }
}

/** Activa la versión nueva del service worker; index.js recarga al cambiar de controlador. */
export function applyUpdate() {
    const waiting = pwa.registration?.waiting;
    if (!waiting) {
        window.location.reload();
        return;
    }
    waiting.postMessage({ type: 'SKIP_WAITING' });
}
