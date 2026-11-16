/*
 * Service worker del CRM B2G (aplicación instalable). Mínimo y seguro: el CRM maneja datos
 * sensibles de varias empresas, así que NUNCA guarda páginas, respuestas Inertia/JSON, PDF,
 * firmas, documentos ni exportes. Solo guarda:
 *   - los assets con hash de la compilación de Vite (/build/assets/*), cache-first por versión;
 *   - las fuentes de fonts.bunny.net;
 *   - la página sin conexión y los íconos.
 * Navegación: siempre a la red; si no hay conexión, /offline.html (sin datos).
 *
 * Lo registra resources/js/pwa/index.js como /sw.js?v=<huella de public/build/manifest.json>: cada
 * compilación cambia la URL, el navegador instala el nuevo service worker en espera y la app ofrece
 * «Hay una actualización · Actualizar» (mensaje SKIP_WAITING). Al cerrar sesión la app envía
 * CLEAR_CACHES.
 */
const VERSION = new URL(self.location.href).searchParams.get('v') || 'dev';
const PREFIX = 'crm-b2g-';
const ASSETS = `${PREFIX}assets-${VERSION}`;
const FONTS = `${PREFIX}fonts-v1`;
const FONT_ORIGINS = ['https://fonts.bunny.net'];
const OFFLINE_URL = '/offline.html';
const SHELL = [OFFLINE_URL, '/icons/icon-192.png', '/icons/badge-96.png', '/icons/favicon.svg', '/icons/lici.svg', '/favicon.ico'];

/** Archivos de la compilación que se precargan: los puntos de entrada, su CSS, sus importaciones estáticas y sus fuentes. */
async function buildFiles() {
    const response = await fetch('/build/manifest.json', { cache: 'no-store', credentials: 'omit' });
    if (!response.ok) return [];
    const manifest = await response.json();
    const files = new Set();
    const visit = (key) => {
        const chunk = manifest[key];
        if (!chunk || files.has(`/build/${chunk.file}`)) return;
        files.add(`/build/${chunk.file}`);
        (chunk.css || []).forEach((file) => files.add(`/build/${file}`));
        (chunk.assets || []).filter((file) => /\.(woff2?|ttf|otf|svg|png|webp)$/i.test(file)).forEach((file) => files.add(`/build/${file}`));
        (chunk.imports || []).forEach(visit);
    };
    Object.keys(manifest).filter((key) => manifest[key].isEntry).forEach(visit);
    return [...files];
}

async function precacheShell() {
    const cache = await caches.open(ASSETS);
    await cache.addAll(SHELL.map((url) => new Request(url, { cache: 'reload', credentials: 'omit' })));
    return cache;
}

self.addEventListener('install', (event) => {
    event.waitUntil(
        (async () => {
            const cache = await precacheShell();
            const files = await buildFiles().catch(() => []);
            // Uno a uno: un asset que falte no impide instalar el resto.
            await Promise.all(files.map((url) => cache.add(new Request(url, { credentials: 'omit' })).catch(() => undefined)));
        })(),
    );
    // No se llama a skipWaiting(): la versión nueva espera a que la persona pulse «Actualizar».
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            const keep = new Set([ASSETS, FONTS]);
            const names = await caches.keys();
            await Promise.all(names.filter((name) => name.startsWith(PREFIX) && !keep.has(name)).map((name) => caches.delete(name)));
            if (self.registration.navigationPreload) await self.registration.navigationPreload.enable().catch(() => undefined);
            await self.clients.claim();
        })(),
    );
});

self.addEventListener('message', (event) => {
    const type = event.data && event.data.type;
    if (type === 'SKIP_WAITING') {
        self.skipWaiting();
    } else if (type === 'CLEAR_CACHES') {
        // Al cerrar sesión: se borra todo y solo se vuelve a guardar la página sin conexión y los íconos.
        event.waitUntil(
            (async () => {
                const names = await caches.keys();
                await Promise.all(names.filter((name) => name.startsWith(PREFIX)).map((name) => caches.delete(name)));
                await precacheShell().catch(() => undefined);
                event.source?.postMessage({ type: 'CACHES_CLEARED' });
            })(),
        );
    }
});

async function cacheFirst(request, cacheName) {
    const cache = await caches.open(cacheName);
    const cached = await cache.match(request);
    if (cached) return cached;
    const response = await fetch(request);
    // Solo respuestas correctas y sin datos de sesión: los assets estáticos no envían cookies.
    const fine = response.ok && (response.type === 'basic' || response.type === 'cors');
    // Las hojas de fuentes externas llegan «opacas» (sin CORS): se aceptan solo en la caché de fuentes.
    if ((fine || (cacheName === FONTS && response.type === 'opaque')) && !response.headers.get('Set-Cookie')) {
        cache.put(request, response.clone()).catch(() => undefined);
    }
    return response;
}

async function navigate(event) {
    try {
        const preload = await event.preloadResponse;
        if (preload) return preload;
        return await fetch(event.request);
    } catch {
        const offline = await caches.match(OFFLINE_URL);
        return offline || new Response('Sin conexión', { status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
    }
}

self.addEventListener('fetch', (event) => {
    const { request } = event;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    if (request.mode === 'navigate') {
        // Nunca se guarda: siempre a la red, y sin red la página sin conexión.
        event.respondWith(navigate(event));
        return;
    }

    if (url.origin === self.location.origin) {
        // Peticiones Inertia o JSON: siempre a la red, sin tocar.
        if (request.headers.get('X-Inertia') || request.headers.get('Accept')?.includes('application/json')) return;
        if (url.pathname.startsWith('/build/assets/') || SHELL.includes(url.pathname)) {
            event.respondWith(cacheFirst(request, ASSETS));
        }
        return;
    }

    if (FONT_ORIGINS.includes(url.origin)) {
        event.respondWith(cacheFirst(request, FONTS));
    }
});

/* ---------------------------------------------------------------- Notificaciones push */

self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch {
        data = { title: 'CRM B2G', body: event.data ? event.data.text() : '' };
    }
    const url = typeof data.url === 'string' && data.url.startsWith('/') && !data.url.startsWith('//') ? data.url : '/';
    event.waitUntil(
        self.registration.showNotification(data.title || 'CRM B2G', {
            body: data.body || '',
            icon: '/icons/icon-192.png',
            badge: '/icons/badge-96.png',
            tag: data.tag || undefined,
            renotify: Boolean(data.tag),
            lang: 'es',
            data: { url },
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = new URL(event.notification.data?.url || '/', self.location.origin);
    if (target.origin !== self.location.origin) target.href = new URL('/', self.location.origin).href;

    event.waitUntil(
        (async () => {
            const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
            const client = windows.find((item) => new URL(item.url).origin === self.location.origin);
            if (client) {
                await client.focus();
                // La app hace la visita con Inertia (sin recargar); si no responde, navegación completa.
                client.postMessage({ type: 'OPEN_URL', url: target.pathname + target.search });
                return;
            }
            await self.clients.openWindow(target.href);
        })(),
    );
});
