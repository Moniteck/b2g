// Peticiones JSON del módulo de documentos (fuera del ciclo de Inertia).
// Las subidas van con `useUpload()` (Inertia): el servidor responde con un «volver atrás».
import { getJson, HttpError } from '../../lib/http.js';

export const attachmentsUrl = (entityType, entityId) => `/adjuntos/${entityType}/${entityId}`;

export function fetchAttachments(entityType, entityId) {
    return getJson(attachmentsUrl(entityType, entityId));
}

export function fetchVersions(documentId) {
    return getJson(`/documentos/${documentId}/versiones`);
}

function xsrfToken() {
    const cookie = document.cookie.split('; ').find((entry) => entry.startsWith('XSRF-TOKEN='));
    return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
}

/** Quita un adjunto de la entidad. Rechaza con `HttpError` (status y mensaje en español). */
export async function deleteAttachment(entityType, entityId, documentId) {
    const response = await fetch(`${attachmentsUrl(entityType, entityId)}/${documentId}`, {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrfToken() },
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok) throw new HttpError(response.status, payload);
    return payload;
}

/** Ícono MDI según el tipo de archivo. */
export function fileIcon(mime = '', name = '') {
    const value = `${mime} ${name}`.toLowerCase();
    if (value.includes('pdf')) return 'file-pdf-box';
    if (value.includes('image') || /\.(png|jpe?g|webp)\b/.test(value)) return 'file-image-outline';
    if (value.includes('sheet') || value.includes('excel') || /\.xlsx?\b/.test(value)) return 'file-excel-outline';
    if (value.includes('word') || /\.docx?\b/.test(value)) return 'file-word-outline';
    return 'file-document-outline';
}

/** El visor integrado solo muestra PDF e imágenes. */
export function canPreview(mime = '') {
    return mime === 'application/pdf' || String(mime).startsWith('image/');
}
