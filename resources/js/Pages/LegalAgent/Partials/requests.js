// Peticiones de las herramientas que no cubre `lib/http.js`: descargar un PDF generado con POST y
// borrar con DELETE fuera del ciclo de Inertia.
import { HttpError } from '@/lib/http.js';

function xsrfToken() {
    const cookie = document.cookie.split('; ').find((entry) => entry.startsWith('XSRF-TOKEN='));
    return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
}

/** Descarga un PDF generado con POST; lanza un Error con el mensaje del servidor si no llega un PDF. */
export async function downloadPdf(url, body, filename) {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/pdf, application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrfToken() },
        body: JSON.stringify(body),
    });

    if (!response.ok || !(response.headers.get('content-type') ?? '').includes('pdf')) {
        const payload = await response.json().catch(() => null);
        throw new Error(payload?.message ?? 'No se pudo generar el PDF. Inténtalo de nuevo.');
    }

    const link = document.createElement('a');
    link.href = URL.createObjectURL(await response.blob());
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(link.href), 1000);
}

/** DELETE con respuesta JSON (p. ej. una conversación del Agente Legal). */
export async function deleteJson(url) {
    const response = await fetch(url, {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrfToken() },
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok) throw new HttpError(response.status, payload);
    return payload;
}
