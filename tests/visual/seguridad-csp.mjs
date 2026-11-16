// Verificación de la CSP con nonce y de la sesión en el navegador (seguridad M3 y B-05).
//
//   node tests/visual/seguridad-csp.mjs
//
// Recorre, en http://127.0.0.1:8000 y http://localhost:8000: acceso (intro y fondo con canvas),
// Dashboard, campana, Configuración con el visor de PDF (pdf.js y su worker), Firmas, Calendario,
// Pipeline (kanban) y Reportes (gráficas). Falla si hay violaciones de CSP o errores de consola,
// si los íconos MDI no cargan o si el HMR de Vite no conecta. Luego cierra sesión y comprueba que
// el botón Atrás no vuelve a mostrar datos. Capturas en storage/app/qa/capturas/seguridad/.
import { mkdirSync } from 'node:fs';
import { chromium } from 'playwright';

const HOSTS = (process.env.QA_HOSTS ?? 'http://127.0.0.1:8000,http://localhost:8000').split(',');
const EMAIL = process.env.QA_EMAIL ?? 'qa.admin@crm-b2g.test';
const PASSWORD = process.env.QA_PASSWORD ?? 'QaB2g#2026';
const OUTPUT = 'storage/app/qa/capturas/seguridad';
mkdirSync(OUTPUT, { recursive: true });

const SCREENS = [
    { name: 'dashboard', path: '/' },
    { name: 'configuracion-pdf', path: '/configuracion', action: async (page) => {
        // Tras la transición de página el primer clic puede perderse: se reintenta.
        for (let attempt = 0; attempt < 3; attempt++) {
            await page.waitForTimeout(1200);
            await page.getByRole('button', { name: 'Vista previa del PDF' }).click();
            if (await page.waitForSelector('canvas', { timeout: 12000 }).then(() => true, () => false)) break;
            if (attempt === 2) throw new Error('el visor de PDF no se abrió');
        }
        await page.waitForTimeout(1500);
    } },
    { name: 'firmas', path: '/firmas' },
    { name: 'calendario', path: '/calendario' },
    { name: 'pipeline', path: '/pipeline' },
    { name: 'reportes', path: '/reportes' },
];

const problems = [];

async function stable(page, path) {
    for (let attempt = 0; attempt < 3; attempt++) {
        try {
            await page.goto(path, { waitUntil: 'networkidle', timeout: 30000 });
            await page.waitForFunction(() => document.querySelector('main, nav, header'), null, { timeout: 15000 });
            return;
        } catch (error) {
            if (attempt === 2) throw error;
            await page.waitForTimeout(1500);
        }
    }
}

async function iconsLoaded(page) {
    return page.evaluate(async () => {
        await document.fonts.ready;
        const icon = document.querySelector('.mdi');
        if (!icon) return true;
        const family = getComputedStyle(icon, '::before').fontFamily;
        return document.fonts.check(`16px ${family}`) && [...document.fonts].some((font) => /Material Design Icons/i.test(font.family) && font.status === 'loaded');
    });
}

const browser = await chromium.launch();
for (const base of HOSTS) {
    const label = new URL(base).hostname;
    const context = await browser.newContext({ baseURL: base, viewport: { width: 1440, height: 900 }, locale: 'es-HN' });
    await context.addInitScript(() => {
        window.__csp = [];
        document.addEventListener('securitypolicyviolation', (event) => window.__csp.push(`${event.violatedDirective} ← ${event.blockedURI}`));
    });
    const page = await context.newPage();
    let hmr = false;
    page.on('websocket', (socket) => {
        if (socket.url().includes(':5173')) socket.on('framereceived', () => (hmr = true));
    });
    page.on('console', (message) => {
        const text = message.text();
        if (message.type() === 'error' || /Content Security Policy/i.test(text)) problems.push(`[${label}] consola: ${text}`);
    });
    page.on('pageerror', (error) => problems.push(`[${label}] excepción: ${error.message}`));

    // Acceso con intro y canvas del fondo.
    await stable(page, '/login');
    await page.waitForTimeout(3500);
    await page.screenshot({ path: `${OUTPUT}/${label}-acceso.png` });
    if (!(await iconsLoaded(page).catch(() => true))) problems.push(`[${label}] acceso: los íconos MDI no cargaron`);
    await page.fill('#login-email', EMAIL);
    await page.fill('#login-password', PASSWORD);
    await Promise.all([page.waitForURL((url) => url.pathname === '/', { timeout: 20000 }), page.click('button[type="submit"]')]);
    await page.waitForTimeout(2500);

    for (const screen of SCREENS) {
        await stable(page, screen.path);
        if (screen.action) await screen.action(page).catch((error) => problems.push(`[${label}] ${screen.name}: ${error.message}`));
        await page.waitForTimeout(800);
        if (!(await iconsLoaded(page).catch(() => true))) problems.push(`[${label}] ${screen.name}: los íconos MDI no cargaron`);
        await page.screenshot({ path: `${OUTPUT}/${label}-${screen.name}.png` });
        const violations = await page.evaluate(() => window.__csp.splice(0)).catch(() => []);
        violations.forEach((violation) => problems.push(`[${label}] ${screen.name}: CSP ${violation}`));
    }

    // Campana de notificaciones.
    await stable(page, '/');
    const bell = page.getByRole('button', { name: /notificaciones/i }).first();
    if (await bell.count()) {
        await bell.click();
        await page.waitForTimeout(800);
        await page.screenshot({ path: `${OUTPUT}/${label}-campana.png` });
        await page.keyboard.press('Escape');
    }
    (await page.evaluate(() => window.__csp.splice(0)).catch(() => [])).forEach((violation) => problems.push(`[${label}] campana: CSP ${violation}`));
    if (!hmr) problems.push(`[${label}] el WebSocket del HMR de Vite no recibió mensajes`);

    // Cerrar sesión y volver con Atrás: no deben verse datos de la sesión.
    await stable(page, '/clientes');
    const response = await page.request.get('/clientes');
    if (!/no-store/.test(response.headers()['cache-control'] ?? '')) problems.push(`[${label}] /clientes sin Cache-Control: no-store`);
    await page.evaluate(async () => {
        const token = document.querySelector('meta[name="csrf-token"]').content;
        await fetch('/logout', { method: 'POST', headers: { 'X-CSRF-TOKEN': token, Accept: 'text/html' } });
    });
    await stable(page, '/login');
    await page.goBack({ waitUntil: 'networkidle' }).catch(() => null);
    await page.waitForTimeout(2000);
    await page.screenshot({ path: `${OUTPUT}/${label}-atras-tras-salir.png` });
    const leaked = await page.evaluate(() => location.pathname !== '/login' && !!document.querySelector('table tbody tr'));
    if (leaked) problems.push(`[${label}] tras cerrar sesión, Atrás muestra ${await page.evaluate(() => location.pathname)} con datos`);
    else console.log(`[${label}] Atrás tras salir → ${await page.evaluate(() => location.pathname)} (sin datos)`);

    await context.close();
}
await browser.close();

if (problems.length) {
    console.error(problems.join('\n'));
    process.exit(1);
}
console.log('Sin violaciones de CSP ni errores de consola; íconos y HMR correctos.');
