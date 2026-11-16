// Endpoints JSON de «Mi firma y sello» (routes/modules/signatures.php).
import { getJson, HttpError, postJson } from '@/lib/http.js';

export const endpoints = {
    page: '/firmas',
    state: '/firmas/estado',
    assets: '/firmas/activos',
    asset: (type) => `/firmas/activos/${type}`,
    sign: '/firmas/firmar',
    signFile: '/firmas/firmar-archivo',
    documents: '/firmas/documentos',
};

function xsrfToken() {
    const cookie = document.cookie.split('; ').find((entry) => entry.startsWith('XSRF-TOKEN='));
    return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
}

/** Mis activos, mi última posición y, con `documentId`, si ya firmé ese documento. */
export function fetchState(documentId = null) {
    return getJson(endpoints.state, { document_id: documentId ?? '' });
}

/** Firma un documento del sistema. */
export function signDocument(payload) {
    return postJson(endpoints.sign, payload);
}

/** Firma un PDF suelto (multipart). `payload.placements` va como JSON. */
export async function signFile(file, payload) {
    const form = new FormData();
    form.append('file', file);
    Object.entries(payload).forEach(([key, value]) => {
        if (value === null || value === undefined) return;
        if (key === 'placements') form.append(key, JSON.stringify(value));
        else if (typeof value === 'boolean') form.append(key, value ? '1' : '0');
        else form.append(key, value);
    });
    const response = await fetch(endpoints.signFile, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrfToken() },
        body: form,
    });
    const body = await response.json().catch(() => null);
    if (!response.ok) throw new HttpError(response.status, body);
    return body;
}
