// Peticiones JSON fuera del ciclo de Inertia (combos con búsqueda remota, marcar notificaciones).
// Para todo lo que cambie de página o guarde un formulario se usa `router` o `useForm` de Inertia.
import { router } from '@inertiajs/vue3';
import { httpErrorMessage } from './errors.js';

function xsrfToken() {
    const cookie = document.cookie.split('; ').find((entry) => entry.startsWith('XSRF-TOKEN='));
    return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
}

export class HttpError extends Error {
    constructor(status, body) {
        super(body?.message || httpErrorMessage(status));
        this.status = status;
        this.errors = body?.errors ?? {};
    }
}

async function request(method, url, { params, body, signal } = {}) {
    const target = new URL(url, window.location.origin);
    Object.entries(params ?? {}).forEach(([key, value]) => {
        if (value !== null && value !== undefined && value !== '') target.searchParams.set(key, value);
    });
    const response = await fetch(target, {
        method,
        signal,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(body ? { 'Content-Type': 'application/json' } : {}),
            ...(method === 'GET' ? {} : { 'X-XSRF-TOKEN': xsrfToken() }),
        },
        body: body ? JSON.stringify(body) : undefined,
    });
    const payload = response.status === 204 ? null : await response.json().catch(() => null);
    if (!response.ok) throw new HttpError(response.status, payload);
    return payload;
}

export const getJson = (url, params, options) => request('GET', url, { ...options, params });
export const postJson = (url, body = {}, options) => request('POST', url, { ...options, body });

/** Una visita de Inertia como promesa, para `useConfirm({ action })` y flujos con `await`.
 *  Resuelve en `onSuccess` y rechaza con los errores de validación en `onError`. */
export function visit(method, url, data = {}, options = {}) {
    return new Promise((resolve, reject) => {
        router.visit(url, {
            method,
            data,
            preserveScroll: true,
            ...options,
            onSuccess: (page) => {
                options.onSuccess?.(page);
                resolve(page);
            },
            onError: (errors) => {
                options.onError?.(errors);
                reject(errors);
            },
        });
    });
}
