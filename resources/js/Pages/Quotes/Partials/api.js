// Peticiones JSON del módulo de cotizaciones (fuera del ciclo de Inertia).
// Un «sujeto» es { type: 'quote' | 'opportunity', id }: el resultado y los participantes se registran
// igual sobre una cotización o sobre una oportunidad (kanban). Ver README.md de esta carpeta.
import { getJson, HttpError } from '@/lib/http.js';

function xsrfToken() {
    const cookie = document.cookie.split('; ').find((entry) => entry.startsWith('XSRF-TOKEN='));
    return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
}

/** POST / PUT / PATCH / DELETE con JSON. Rechaza con `HttpError` (status, message, errors). */
export async function sendJson(method, url, body = undefined) {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    const payload = response.status === 204 ? null : await response.json().catch(() => null);
    if (!response.ok) throw new HttpError(response.status, payload);
    return payload;
}

/** Ruta base del sujeto: `/cotizaciones/12` o `/oportunidades/5`. */
export function subjectUrl(subject) {
    return subject?.type === 'opportunity' ? `/oportunidades/${subject.id}` : `/cotizaciones/${subject.id}`;
}

export const fetchQuote = (id) => getJson(`/cotizaciones/${id}`).then((response) => response.data);

export const fetchParticipants = (subject) => getJson(`${subjectUrl(subject)}/participantes`);
export const saveParticipants = (subject, participants) => sendJson('PUT', `${subjectUrl(subject)}/participantes`, { participants });

export const markWon = (subject, data) => sendJson('POST', `${subjectUrl(subject)}/ganada`, data);
export const markLost = (subject, data) => sendJson('POST', `${subjectUrl(subject)}/perdida`, data);
export const revertOutcome = (subject, reason) => sendJson('POST', `${subjectUrl(subject)}/revertir`, { reason });

/** Guarda el PDF generado como adjunto (para firmarlo) y devuelve `{ document }`. */
export const snapshotPdf = (quoteId) => sendJson('POST', `/cotizaciones/${quoteId}/pdf/documento`);

/** Primer mensaje de error de un `HttpError` (campo dado o cualquiera). */
export function firstError(error, field = null) {
    const errors = error?.errors ?? {};
    const value = field ? errors[field] : Object.values(errors)[0];
    return (Array.isArray(value) ? value[0] : value) || error?.message || 'No se pudo completar la acción.';
}

/** Errores de validación planos { campo: 'mensaje' }. */
export function flatErrors(error) {
    return Object.fromEntries(Object.entries(error?.errors ?? {}).map(([key, value]) => [key, Array.isArray(value) ? value[0] : value]));
}
