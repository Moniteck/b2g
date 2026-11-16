// Notificaciones push de los recordatorios de plazos: «Recibir avisos en este dispositivo».
//
//   const state = await pushStatus();   // 'unsupported' | 'ios-install' | 'server-off' | 'no-worker' | 'denied' | 'off' | 'on'
//   await enablePush(); await disablePush(); await sendTestPush();
//
// La clave pública VAPID llega en <meta name="pwa-vapid"> (resources/views/partials/pwa-head.blade.php).
// Backend: routes/modules/pwa.php.
import { pwa } from '@/pwa/state.js';

const ENDPOINT = '/pwa/push/suscripciones';

export function vapidKey() {
    return document.querySelector('meta[name="pwa-vapid"]')?.content || '';
}

function supported() {
    return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
}

function xsrfToken() {
    const cookie = document.cookie.split('; ').find((entry) => entry.startsWith('XSRF-TOKEN='));
    return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
}

async function send(method, url, body) {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrfToken() },
        body: body ? JSON.stringify(body) : undefined,
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
        const firstError = Object.values(payload.errors ?? {})[0]?.[0];
        throw new Error(firstError || payload.message || 'No se pudo completar la acción. Intente de nuevo.');
    }
    return payload;
}

function urlBase64ToUint8Array(base64) {
    const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(padded);
    return Uint8Array.from(raw, (char) => char.charCodeAt(0));
}

async function registration() {
    if (pwa.registration) return pwa.registration;
    return (await navigator.serviceWorker?.getRegistration('/')) ?? null;
}

export async function currentSubscription() {
    if (!supported()) return null;
    const reg = await registration();
    return reg ? reg.pushManager.getSubscription() : null;
}

export async function pushStatus() {
    if (pwa.isIos && !pwa.standalone) return 'ios-install';
    if (!supported()) return 'unsupported';
    if (!vapidKey()) return 'server-off';
    if (!(await registration())) return 'no-worker';
    if (Notification.permission === 'denied') return 'denied';
    return (await currentSubscription()) ? 'on' : 'off';
}

function serialize(subscription) {
    const json = subscription.toJSON();
    const encodings = window.PushManager?.supportedContentEncodings ?? ['aes128gcm'];
    return { endpoint: json.endpoint, keys: json.keys, content_encoding: encodings.includes('aes128gcm') ? 'aes128gcm' : 'aesgcm' };
}

export async function enablePush() {
    const permission = await Notification.requestPermission();
    if (permission !== 'granted') {
        throw new Error(permission === 'denied' ? 'El navegador bloqueó las notificaciones. Actívelas en la configuración del sitio.' : 'Debe permitir las notificaciones para recibir avisos.');
    }
    const reg = await registration();
    if (!reg) throw new Error('La aplicación aún no terminó de instalarse en este navegador. Recargue la página.');
    let subscription = await reg.pushManager.getSubscription();
    if (!subscription) {
        subscription = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlBase64ToUint8Array(vapidKey()) });
    }
    return send('POST', ENDPOINT, serialize(subscription));
}

export async function disablePush() {
    const subscription = await currentSubscription();
    if (!subscription) return { message: 'Este dispositivo ya no recibía avisos.' };
    const { endpoint } = subscription;
    await subscription.unsubscribe().catch(() => undefined);
    return send('DELETE', ENDPOINT, { endpoint });
}

export function sendTestPush() {
    return send('POST', '/pwa/push/prueba');
}

/**
 * Al abrir la app con permiso y suscripción, se reenvía la suscripción al servidor (idempotente):
 * así se recupera si el navegador la renovó o si la clave VAPID cambió.
 */
export async function syncPush() {
    try {
        if (!vapidKey() || !supported() || Notification.permission !== 'granted') return;
        const subscription = await currentSubscription();
        if (!subscription) return;
        const key = subscription.options?.applicationServerKey;
        const current = urlBase64ToUint8Array(vapidKey());
        if (key && new Uint8Array(key).join() !== current.join()) {
            await subscription.unsubscribe();
            return;
        }
        await send('POST', ENDPOINT, serialize(subscription));
    } catch {
        // Silencioso: Mi cuenta muestra el estado real.
    }
}
