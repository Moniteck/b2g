// Cliente del chat del Agente Legal en streaming (`POST /agente-legal/chat` con
// `Accept: application/x-ndjson`): una línea JSON por evento.
//
//   const done = await streamLegalReply({ conversation_id, message }, { onDelta: (text) => … });
//   // done: { conversation, messages: [pregunta, respuesta] }
//
// Los errores previos al flujo (422, 429 del límite de uso, 403…) llegan como `HttpError` con el
// estado y el mensaje del servidor; un error a mitad del flujo, como `Error` con su mensaje.
import { HttpError } from '@/lib/http.js';

function xsrfToken() {
    const cookie = document.cookie.split('; ').find((entry) => entry.startsWith('XSRF-TOKEN='));
    return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
}

export async function streamLegalReply(payload, { onDelta = () => {}, signal } = {}) {
    const response = await fetch('/agente-legal/chat', {
        method: 'POST',
        credentials: 'same-origin',
        signal,
        headers: {
            // JSON primero: así los errores previos (422, 429) llegan como JSON; el controlador ve «x-ndjson» y transmite.
            Accept: 'application/json, application/x-ndjson',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify(payload),
    });

    const type = response.headers.get('content-type') ?? '';
    if (!response.ok || !type.includes('ndjson')) {
        const body = await response.json().catch(() => null);
        if (!response.ok) {
            const error = new HttpError(response.status, body);
            error.retryAfter = Number(response.headers.get('retry-after')) || null;
            throw error;
        }
        // Sin streaming (respuesta JSON completa): se entrega igual.
        if (body?.messages) onDelta(body.messages.at(-1)?.content ?? '');
        return body;
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    let done = null;

    const handle = (line) => {
        if (!line.trim()) return;
        let event;
        try {
            event = JSON.parse(line);
        } catch {
            return;
        }
        if (event.type === 'delta') onDelta(event.text);
        else if (event.type === 'done') done = event;
        else if (event.type === 'error') throw new Error(event.message || 'El servicio de IA no respondió.');
    };

    for (;;) {
        const { value, done: finished } = await reader.read();
        if (finished) break;
        buffer += decoder.decode(value, { stream: true });
        let index;
        while ((index = buffer.indexOf('\n')) >= 0) {
            handle(buffer.slice(0, index));
            buffer = buffer.slice(index + 1);
        }
    }
    handle(buffer + decoder.decode());

    if (!done) throw new Error('Se cortó la respuesta antes de terminar. Inténtalo de nuevo.');
    return done;
}
