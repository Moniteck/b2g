// Validación de la aplicación instalable (PWA) con Playwright.
//
//   PWA_URL=http://localhost:8100 node tests/visual/pwa.mjs
//
// PWA_URL debe ser una compilación de PRODUCCIÓN aislada (APP_ENV=production, `vite build`, sin
// public/hot y con claves VAPID de prueba): el service worker no se registra con Vite HMR.
// DEV_URL (http://localhost:8000) se revisa aparte: ahí NO debe haber service worker.
//
// Usa Google Chrome (channel «chrome») con perfil persistente: el Chromium de Playwright no trae
// servicio push y los perfiles de incógnito no admiten suscripciones.
//
// Comprueba: manifest e íconos, instalabilidad (CDP), service worker activo, que la caché solo
// tenga assets estáticos (ninguna página ni JSON), página sin conexión y Reintentar, aviso de
// actualización, «Aplicación y avisos» en Mi cuenta (escritorio, móvil, claro, oscuro, iOS),
// notificación push real (FCM) recibida y que al cerrar sesión se borran las cachés.
// Capturas en storage/app/qa/capturas/pwa/. Con --screenshots también regenera las capturas del
// manifest (public/icons/screenshots/).
import { mkdirSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { chromium } from 'playwright';

const PWA_URL = process.env.PWA_URL ?? 'http://localhost:8100';
const DEV_URL = process.env.DEV_URL ?? 'http://localhost:8000';
const EMAIL = process.env.QA_EMAIL ?? 'qa.admin@crm-b2g.test';
const READER = process.env.QA_READER ?? 'qa.lector@crm-b2g.test';
const PASSWORD = process.env.QA_PASSWORD ?? 'QaB2g#2026';
const OUT = 'storage/app/qa/capturas/pwa';
const MANIFEST_SHOTS = process.argv.includes('--screenshots');
mkdirSync(OUT, { recursive: true });

const results = [];
const problems = [];
const external = new Set();
function check(name, ok, detail = '') {
    results.push({ name, ok: Boolean(ok), detail });
    console.log(`${ok ? 'OK   ' : 'FALLA'} ${name}${detail ? ` — ${detail}` : ''}`);
}
function watch(page, label) {
    page.on('console', (message) => {
        if (message.type() !== 'error' || /favicon|ERR_INTERNET_DISCONNECTED|net::ERR_FAILED|Failed to load resource/.test(message.text())) return;
        // Fuentes del marco desde un CDN que la CSP no admite: ajeno a la PWA, se informa aparte.
        if (message.text().includes('cdn.jsdelivr.net')) external.add(`[${label}] CSP bloquea fuentes de cdn.jsdelivr.net`);
        else problems.push(`[${label}] ${message.text()}`);
    });
    page.on('response', (response) => {
        if (response.status() >= 400 && !response.url().includes('favicon')) problems.push(`[${label}] ${response.status()} ${response.url()}`);
    });
    page.on('pageerror', (error) => problems.push(`[${label}] excepción: ${error.message}`));
}
async function settle(page) {
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.evaluate(() => document.fonts.ready).catch(() => {});
    await page.waitForTimeout(700);
}
async function login(page, base, email = EMAIL) {
    for (let attempt = 1; attempt <= 3; attempt++) {
        try {
            await page.goto(`${base}/login`);
            await page.fill('#login-email', email);
            await page.fill('#login-password', PASSWORD);
            await Promise.all([page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 20000 }), page.click('button[type="submit"]')]);
            await settle(page);
            return;
        } catch (error) {
            if (attempt === 3) throw error;
            await page.waitForTimeout(1500);
        }
    }
}
async function setTheme(context, theme) {
    await context.addInitScript((value) => {
        try {
            localStorage.setItem('theme', value);
        } catch {
            // Sin almacenamiento.
        }
    }, theme);
}

/* ------------------------------------------------------------ 1. Manifest e íconos */
const manifestResponse = await fetch(`${PWA_URL}/manifest.webmanifest`);
const manifest = await manifestResponse.json();
check('manifest se sirve como application/manifest+json', manifestResponse.ok && (manifestResponse.headers.get('content-type') ?? '').includes('manifest+json'), manifestResponse.headers.get('content-type'));
check('manifest: nombre, standalone, start_url y scope', manifest.name === 'CRM B2G' && manifest.display === 'standalone' && manifest.start_url === '/' && manifest.scope === '/');
check('manifest: íconos 192/512 normales y maskable', ['192x192', '512x512'].every((size) => manifest.icons.some((icon) => icon.sizes === size && icon.purpose === 'any') && manifest.icons.some((icon) => icon.sizes === size && icon.purpose === 'maskable')));
check('manifest: 4 atajos', manifest.shortcuts?.length === 4, manifest.shortcuts?.map((item) => item.short_name).join(', '));
const images = [...manifest.icons, ...manifest.shortcuts.flatMap((item) => item.icons), ...(MANIFEST_SHOTS ? [] : manifest.screenshots), { src: '/icons/apple-touch-icon.png' }, { src: '/favicon.ico' }];
const missing = [];
for (const image of images) {
    const response = await fetch(`${PWA_URL}${image.src}`);
    if (!response.ok || Number(response.headers.get('content-length') ?? 1) === 0) missing.push(image.src);
}
check('todas las imágenes del manifest responden 200', missing.length === 0, missing.join(', '));

/* ------------------------------------------------------------ 2. Desarrollo: sin service worker */
{
    const browser = await chromium.launch();
    const page = await browser.newPage();
    watch(page, 'desarrollo');
    const response = await page.goto(`${DEV_URL}/login`).catch(() => null);
    if (response) {
        await settle(page);
        const meta = await page.locator('meta[name="pwa-sw"]').count();
        const registrations = await page.evaluate(async () => (await navigator.serviceWorker.getRegistrations()).length);
        check('desarrollo (Vite HMR): no se publica ni registra el service worker', meta === 0 && registrations === 0, `meta=${meta}, registros=${registrations}`);
        check('desarrollo: manifest y viewport-fit en el head', (await page.locator('link[rel="manifest"]').count()) === 1 && (await page.getAttribute('meta[name="viewport"]', 'content')).includes('viewport-fit=cover'));
    } else {
        check('desarrollo: servidor en DEV_URL', false, 'no responde');
    }
    await browser.close();
}

/* ------------------------------------------------------------ 3. Producción aislada: Chrome con perfil */
const profile = mkdtempSync(join(tmpdir(), 'crm-pwa-'));
const context = await chromium.launchPersistentContext(profile, {
    channel: 'chrome',
    headless: true,
    viewport: { width: 1440, height: 900 },
    locale: 'es-HN',
    timezoneId: 'America/Tegucigalpa',
});
await context.grantPermissions(['notifications'], { origin: PWA_URL });
const page = context.pages()[0] ?? (await context.newPage());
watch(page, 'producción');
const cdp = await context.newCDPSession(page);

await login(page, PWA_URL);
const sw = await page.evaluate(async () => {
    const registration = await navigator.serviceWorker.ready;
    return { script: registration.active?.scriptURL ?? '', scope: registration.scope };
});
check('service worker registrado y activo con versión de la compilación', /\/sw\.js\?v=[a-f0-9]{12}$/.test(sw.script) && sw.scope === `${PWA_URL}/`, sw.script);
await page.reload();
await settle(page);
check('la página queda controlada por el service worker', await page.evaluate(() => Boolean(navigator.serviceWorker.controller)));

const installability = await cdp.send('Page.getInstallabilityErrors');
check('instalable en escritorio (CDP: sin errores de instalabilidad)', installability.installabilityErrors.length === 0, JSON.stringify(installability.installabilityErrors));
const appManifest = await cdp.send('Page.getAppManifest');
check('Chrome lee el manifest sin errores', (appManifest.errors ?? []).length === 0, JSON.stringify(appManifest.errors ?? []));

// Recorrido para poblar la caché: navegaciones completas y visitas Inertia.
for (const path of ['/licitaciones', '/calendario', '/pipeline', '/cotizaciones', '/mi-cuenta']) {
    await page.goto(`${PWA_URL}${path}`);
    await settle(page);
}
await page.evaluate(() => window.__inertia?.router?.visit?.('/'));
await page.goto(`${PWA_URL}/`);
await settle(page);
if (MANIFEST_SHOTS) {
    await page.setViewportSize({ width: 1280, height: 800 });
    await settle(page);
    await page.screenshot({ path: 'public/icons/screenshots/dashboard-wide.png' });
    await page.setViewportSize({ width: 1440, height: 900 });
}

const cached = await page.evaluate(async () => {
    const entries = [];
    for (const name of await caches.keys()) {
        const cache = await caches.open(name);
        for (const request of await cache.keys()) {
            const response = await cache.match(request);
            entries.push({ cache: name, url: request.url, type: response?.headers.get('content-type') ?? '' });
        }
    }
    return entries;
});
const allowed = (entry) => {
    const url = new URL(entry.url);
    if (url.origin === 'https://fonts.bunny.net') return true;
    return url.pathname.startsWith('/build/assets/') || ['/offline.html', '/favicon.ico'].includes(url.pathname) || url.pathname.startsWith('/icons/');
};
const forbidden = cached.filter((entry) => !allowed(entry) || (/json|pdf/.test(entry.type) && !entry.url.includes('/build/')) || (entry.type.includes('text/html') && !entry.url.endsWith('/offline.html')));
check(`caché del service worker: solo assets estáticos (${cached.length} entradas)`, cached.length > 0 && forbidden.length === 0, forbidden.map((entry) => entry.url).join(', '));
check('caché: incluye offline.html y assets con hash', cached.some((entry) => entry.url.endsWith('/offline.html')) && cached.some((entry) => /\/build\/assets\/.+-[A-Za-z0-9_-]{6,}\.(js|css)$/.test(entry.url)));

// Sin conexión.
await context.setOffline(true);
await page.goto(`${PWA_URL}/licitaciones`).catch(() => {});
await page.waitForTimeout(500);
const offlineText = await page.textContent('body').catch(() => '');
check('sin conexión: se muestra offline.html con Lici y Reintentar', offlineText.includes('Sin conexión') && (await page.locator('a.retry').count()) === 1);
await page.screenshot({ path: `${OUT}/sin-conexion-escritorio.png` });
await page.setViewportSize({ width: 390, height: 844 });
await page.screenshot({ path: `${OUT}/sin-conexion-movil.png` });
await page.setViewportSize({ width: 1440, height: 900 });
await context.setOffline(false);
await Promise.all([page.waitForLoadState('load'), page.click('a.retry')]);
await settle(page);
check('Reintentar vuelve a la pantalla al recuperar la conexión', new URL(page.url()).pathname === '/licitaciones' && !(await page.textContent('body')).includes('Lici no logra comunicarse'));

// Aviso de actualización: se instala un service worker «nuevo» (otra versión) que queda en espera.
await page.goto(`${PWA_URL}/`);
await settle(page);
await page.evaluate(() => navigator.serviceWorker.register('/sw.js?v=000000nueva0', { scope: '/', updateViaCache: 'none' }));
const toast = page.locator('[data-pwa="update"]');
await toast.waitFor({ timeout: 15000 }).catch(() => {});
check('aviso «Hay una actualización · Actualizar» al haber versión nueva', await toast.isVisible().catch(() => false));
await page.screenshot({ path: `${OUT}/aviso-actualizacion-escritorio.png` });
await Promise.all([page.waitForEvent('load', { timeout: 20000 }).catch(() => null), toast.getByRole('button', { name: 'Actualizar' }).click().catch(() => {})]);
await settle(page);
const controller = await page.evaluate(() => navigator.serviceWorker.controller?.scriptURL ?? '');
check('Actualizar activa la versión nueva y recarga', controller.includes('v=000000nueva0'), controller);
// La siguiente carga vuelve a registrar la versión real (simula el siguiente despliegue): se actualiza también.
await page.reload();
await settle(page);
if (await toast.isVisible().catch(() => false) || (await toast.waitFor({ timeout: 10000 }).then(() => true).catch(() => false))) {
    await Promise.all([page.waitForEvent('load', { timeout: 20000 }).catch(() => null), toast.getByRole('button', { name: 'Actualizar' }).click()]);
    await settle(page);
}
check('vuelve a la versión real de la compilación', (await page.evaluate(() => navigator.serviceWorker.controller?.scriptURL ?? '')).includes(sw.script.split('?')[1]));

// Mi cuenta: instalar y avisos push (notificación real por FCM).
await page.goto(`${PWA_URL}/mi-cuenta`);
await settle(page);
const section = page.locator('[data-pwa="account"]');
await section.scrollIntoViewIfNeeded();
await section.screenshot({ path: `${OUT}/mi-cuenta-seccion-escritorio-claro.png` });
const toggle = section.getByRole('switch').first();
const toggleExists = (await toggle.count()) > 0;
check('Mi cuenta: interruptor «Recibir avisos en este dispositivo»', toggleExists && (await section.textContent()).includes('Recibir avisos en este dispositivo'));
if (toggleExists) {
    await toggle.click();
    await section.getByRole('button', { name: 'Enviar aviso de prueba' }).waitFor({ timeout: 20000 }).catch(() => {});
    const subscribed = await page.evaluate(async () => Boolean(await (await navigator.serviceWorker.ready).pushManager.getSubscription()));
    check('suscripción push creada en el navegador y en el servidor', subscribed);
    await section.screenshot({ path: `${OUT}/mi-cuenta-avisos-activos.png` });
    await section.getByRole('button', { name: 'Enviar aviso de prueba' }).click().catch(() => {});
    let notifications = [];
    for (let i = 0; i < 30 && notifications.length === 0; i++) {
        await page.waitForTimeout(1000);
        notifications = await page.evaluate(async () => (await (await navigator.serviceWorker.ready).getNotifications()).map((item) => ({ title: item.title, body: item.body, url: item.data?.url })));
    }
    check('notificación push recibida en el navegador (FCM → service worker)', notifications.some((item) => item.title === 'Aviso de prueba · CRM B2G' && item.url === '/mi-cuenta'), JSON.stringify(notifications));
}

// Cerrar sesión: se borran las cachés y la suscripción push del navegador.
const cachesBefore = await page.evaluate(async () => (await caches.keys()).length);
await page.goto(`${PWA_URL}/`);
await settle(page);
await page.getByRole('button', { name: /^Menú de usuario/ }).first().click();
await page.getByRole('menuitem', { name: 'Cerrar sesión' }).click();
// Si el marco pide confirmación, se acepta.
await page.getByRole('button', { name: 'Cerrar sesión' }).last().click({ timeout: 2000 }).catch(() => {});
await page.waitForURL((url) => url.pathname.startsWith('/login'), { timeout: 15000 }).catch(() => {});
await page.waitForTimeout(1500);
const after = await page.evaluate(async () => {
    const entries = [];
    for (const name of await caches.keys()) entries.push(...(await (await caches.open(name)).keys()).map((request) => new URL(request.url).pathname));
    return { entries, subscription: Boolean(await (await navigator.serviceWorker.ready).pushManager.getSubscription()) };
});
check('cerrar sesión: cachés vaciadas (solo queda la página sin conexión)', new URL(page.url()).pathname.startsWith('/login') && after.entries.every((path) => path === '/offline.html' || path.startsWith('/icons/') || path === '/favicon.ico'), `antes ${cachesBefore} cachés; después ${after.entries.length} entradas`);
check('cerrar sesión: suscripción push quitada de este navegador', !after.subscription);
await context.close();
rmSync(profile, { recursive: true, force: true });

/* ------------------------------------------------------------ 4. Móvil, temas e iOS */
const browser = await chromium.launch();
for (const theme of ['light', 'dark']) {
    for (const [label, viewport, mobile] of [['escritorio', { width: 1440, height: 900 }, false], ['movil', { width: 390, height: 844 }, true]]) {
        const ctx = await browser.newContext({ viewport, isMobile: mobile, hasTouch: mobile, deviceScaleFactor: mobile ? 2 : 1, locale: 'es-HN' });
        await setTheme(ctx, theme);
        const tab = await ctx.newPage();
        watch(tab, `${label}-${theme}`);
        await login(tab, PWA_URL, theme === 'dark' ? READER : EMAIL);
        if (mobile && theme === 'light') {
            const mobileCdp = await ctx.newCDPSession(tab);
            const errors = await mobileCdp.send('Page.getInstallabilityErrors');
            check('instalable en móvil (emulación 390×844)', errors.installabilityErrors.length === 0, JSON.stringify(errors.installabilityErrors));
            if (MANIFEST_SHOTS) await tab.screenshot({ path: 'public/icons/screenshots/dashboard-narrow.png' });
        }
        await tab.goto(`${PWA_URL}/mi-cuenta`);
        await settle(tab);
        const block = tab.locator('[data-pwa="account"]');
        await block.scrollIntoViewIfNeeded();
        await tab.screenshot({ path: `${OUT}/mi-cuenta-${label}-${theme === 'dark' ? 'oscuro' : 'claro'}.png`, fullPage: false });
        const overflow = await tab.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        check(`Mi cuenta ${label} ${theme === 'dark' ? 'oscuro (lector)' : 'claro'}: sin scroll horizontal`, overflow <= 0, `${overflow}px`);
        await ctx.close();
    }
}

// iPhone con Safari: guía «Compartir → Añadir a pantalla de inicio» y aviso de iOS 16.4.
{
    const ctx = await browser.newContext({
        viewport: { width: 390, height: 844 },
        isMobile: true,
        hasTouch: true,
        deviceScaleFactor: 2,
        userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
    });
    const tab = await ctx.newPage();
    watch(tab, 'ios');
    await login(tab, PWA_URL);
    await tab.goto(`${PWA_URL}/mi-cuenta`);
    await settle(tab);
    const block = tab.locator('[data-pwa="account"]');
    await block.scrollIntoViewIfNeeded();
    const text = await block.textContent();
    check('iOS: guía de instalación y aviso de que push requiere la app instalada (16.4+)', (await block.locator('[data-pwa="ios-steps"]').count()) === 1 && text.includes('16.4'));
    await block.screenshot({ path: `${OUT}/mi-cuenta-ios.png` });

    // Oferta de instalación de una sola vez (segunda visita).
    await tab.evaluate(() => localStorage.setItem('crm-b2g:pwa-visits', '1'));
    await tab.goto(`${PWA_URL}/`);
    const offer = tab.locator('[data-pwa="install-offer"]');
    await offer.waitFor({ timeout: 8000 }).catch(() => {});
    check('oferta de instalación visible una vez (iOS)', await offer.isVisible().catch(() => false));
    await tab.screenshot({ path: `${OUT}/oferta-instalacion-ios.png` });
    await offer.getByRole('button', { name: 'Ahora no' }).click().catch(() => {});
    await tab.goto(`${PWA_URL}/`);
    await tab.waitForTimeout(5000);
    check('la oferta no vuelve a salir tras «Ahora no»', !(await offer.isVisible().catch(() => false)));
    await ctx.close();
}
await browser.close();

check('cero errores de consola propios de la PWA', problems.length === 0, problems.slice(0, 8).join(' | '));
if (external.size) console.log(`AVISO (otro módulo): ${[...external].join(' · ')}`);
const failed = results.filter((item) => !item.ok);
console.log(`\n${results.length - failed.length}/${results.length} comprobaciones correctas. Capturas en ${OUT}/`);
process.exit(failed.length ? 1 : 0);
