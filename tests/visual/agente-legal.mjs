// Revisión visual y flujo real del Agente Legal (/agente-legal) con Playwright.
//
//   node tests/visual/agente-legal.mjs
//
// Como administrador: estado vacío con Lici; una conversación REAL con la IA (sugerencia, Lici busca,
// escribe en streaming y se alegra), copiar, nueva conversación y retomar la anterior; el generador de
// escritos en tres pasos con un borrador real; un 429 simulado (Lici preocupada con mensaje amable).
// En móvil: chat a pantalla completa, hoja de conversaciones y campo fijo abajo. Claro y oscuro,
// 1440 × 900 y 390 × 844. Como lector: sin acceso (403). Revisa también que la pantalla de acceso
// siga mostrando a Lici. Capturas en storage/app/qa/capturas/agente-legal/. Código 1 si hay errores
// de consola o scroll horizontal.
import { mkdirSync } from 'node:fs';
import { chromium } from 'playwright';

const BASE_URL = process.env.QA_URL ?? 'http://localhost:8000';
const PASSWORD = process.env.QA_PASSWORD ?? 'QaB2g#2026';
const ADMIN = 'qa.admin@crm-b2g.test';
const VIEWER = 'qa.lector@crm-b2g.test';
const OUTPUT = 'storage/app/qa/capturas/agente-legal';
const VIEWPORTS = { escritorio: { width: 1440, height: 900 }, movil: { width: 390, height: 844 } };
// Ruido del entorno de desarrollo que no depende de esta pantalla (CSP con [::1] de Vite).
const IGNORED = /Content Security Policy|Failed to load resource: the server responded with a status of (403|429)/;

mkdirSync(OUTPUT, { recursive: true });
const problems = [];

async function context(browser, viewport, theme) {
    const ctx = await browser.newContext({ viewport: VIEWPORTS[viewport], deviceScaleFactor: 1, isMobile: viewport === 'movil', hasTouch: viewport === 'movil', bypassCSP: true, locale: 'es-HN', timezoneId: 'America/Tegucigalpa' });
    await ctx.addInitScript((value) => localStorage.setItem('theme', value), theme === 'oscuro' ? 'dark' : 'light');
    await ctx.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: BASE_URL });
    return ctx;
}

/** Inicia sesión por HTTP (la pantalla de acceso la edita otro agente). */
async function login(page, email) {
    const request = page.context().request;
    await request.get(`${BASE_URL}/login`);
    const xsrf = decodeURIComponent((await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN')?.value ?? '');
    const response = await request.post(`${BASE_URL}/login`, { headers: { 'X-XSRF-TOKEN': xsrf, Accept: 'text/html' }, form: { email, password: PASSWORD }, maxRedirects: 0 });
    if (response.status() >= 400) throw new Error(`acceso ${response.status()}`);
}

async function openPage(page) {
    for (let attempt = 1; attempt <= 4; attempt += 1) {
        try {
            await page.goto(`${BASE_URL}/agente-legal`);
            await page.waitForSelector('[data-legal-panel]', { timeout: 15000 });
            await page.waitForLoadState('networkidle').catch(() => {});
            await page.evaluate(() => document.fonts.ready);
            await page.waitForTimeout(800);
            return;
        } catch (error) {
            if (attempt === 4) throw error;
            await page.waitForTimeout(2000);
        }
    }
}

async function shot(page, name) {
    await page.waitForTimeout(350);
    if (!(await page.locator('[data-legal-panel]').count()) && !(await page.getByRole('dialog').count())) throw new Error(`página en blanco en ${name}`);
    await page.screenshot({ path: `${OUTPUT}/${name}.png`, animations: 'allow' });
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    if (overflow > 1) problems.push(`[${name}] scroll horizontal de ${overflow}px`);
    console.log(`ok  ${name}`);
}

/** El panel del chat debe llegar al borde (escritorio) o quedar entre las barras (móvil) sin desbordar. */
async function checkLayout(page, label) {
    const box = await page.locator('[data-legal-panel]').boundingBox();
    const viewport = page.viewportSize();
    if (box.y + box.height > viewport.height + 1) problems.push(`[${label}] el chat se sale de la ventana (${Math.round(box.y + box.height)} > ${viewport.height})`);
    const form = await page.locator('#legal-message').boundingBox();
    if (form.y + form.height > viewport.height) problems.push(`[${label}] el campo de escritura no está a la vista`);
}

const browser = await chromium.launch();

async function phase(label, viewport, theme, run) {
    for (let attempt = 1; attempt <= 3; attempt += 1) {
        const ctx = await context(browser, viewport, theme);
        const page = await ctx.newPage();
        const seen = [];
        page.on('console', (message) => {
            if (message.type() === 'error' && !IGNORED.test(message.text())) seen.push(`[${label}] consola: ${message.text()}`);
        });
        page.on('pageerror', (error) => seen.push(`[${label}] excepción: ${error.message}`));
        try {
            await run(page, ctx);
            problems.push(...seen);
            await ctx.close();
            return;
        } catch (error) {
            await ctx.close();
            if (attempt === 3) problems.push(`[${label}] ${error.message.split('\n')[0]}`, ...seen);
            else console.log(`reintento ${label}: ${error.message.split('\n')[0]}`);
        }
    }
}

// 1. Escritorio claro: conversación real, nueva, retomar, escrito real y 429.
await phase('escritorio-claro', 'escritorio', 'claro', async (page) => {
    await login(page, ADMIN);
    await openPage(page);
    await checkLayout(page, 'escritorio-claro');
    await shot(page, 'vacio-escritorio-claro');

    await page.locator('#legal-message').click();
    await page.keyboard.type('Necesito saber', { delay: 15 });
    await shot(page, 'lici-escucha-escritorio-claro');
    await page.locator('#legal-message').fill('');

    await page.locator('[data-suggestion]', { hasText: 'Plazos de impugnación' }).click();
    await page.locator('[data-typing]').waitFor({ timeout: 10000 });
    await shot(page, 'lici-busca-escritorio-claro');
    await page.locator('[data-message="assistant"][aria-busy="true"]').waitFor({ timeout: 120000 });
    await shot(page, 'lici-escribe-escritorio-claro');
    await page.locator('[data-copy]').last().waitFor({ timeout: 120000 });
    const status = await page.locator('[data-lici-status]').innerText();
    if (!/Listo/.test(status)) problems.push(`[escritorio-claro] Lici no quedó contenta: «${status}»`);
    await shot(page, 'respuesta-escritorio-claro');
    const users = await page.locator('[data-message="user"]').count();
    if (users !== 1) problems.push(`[escritorio-claro] ${users} mensajes del usuario (se esperaba 1)`);

    await page.locator('[data-message="assistant"]').last().hover();
    await page.locator('[data-copy]').last().click();
    const copied = await page.evaluate(() => navigator.clipboard.readText());
    if (copied.length < 40 || copied.includes('**')) problems.push('[escritorio-claro] copiar no dejó el texto plano de la respuesta');

    // Nueva conversación y retomar la anterior desde el panel.
    await page.getByRole('button', { name: 'Nueva conversación' }).first().click();
    await page.getByText('Hola, soy Lici').waitFor();
    await page.locator('nav[aria-label="Conversaciones"] button').first().click();
    await page.locator('[data-message="assistant"]').first().waitFor({ timeout: 15000 });
    await shot(page, 'retomada-escritorio-claro');

    // Generador de escritos en tres pasos, con un borrador real.
    await page.getByRole('button', { name: 'Redactar escrito' }).click();
    const dialog = page.getByRole('dialog', { name: 'Redactar escrito' });
    await dialog.waitFor();
    await shot(page, 'escrito-paso1-escritorio-claro');
    await dialog.getByRole('button', { name: /Solicitud de aclaración/ }).click();
    await dialog.getByLabel('Institución destinataria').fill('Secretaría de Salud');
    await dialog.getByLabel('Proceso o contrato').fill('LPN-SESAL-007-2026');
    await dialog.getByLabel('Hechos y solicitud').fill('El pliego pide registro sanitario vigente para los guantes, pero no aclara si se acepta la constancia de trámite de renovación. Pedimos que lo aclaren antes del cierre del 28 de noviembre.');
    await shot(page, 'escrito-paso2-escritorio-claro');
    await dialog.getByRole('button', { name: 'Generar borrador' }).click();
    await dialog.locator('[data-writing-step="3"]').waitFor();
    await shot(page, 'escrito-redactando-escritorio-claro');
    await dialog.locator('[data-writing-content]').waitFor({ timeout: 150000 });
    await shot(page, 'escrito-hoja-escritorio-claro');
    await page.keyboard.press('Escape');
    await dialog.waitFor({ state: 'hidden' });

    // Límite de uso: se simula el 429 del servidor para no gastar el cupo real.
    await page.route('**/agente-legal/chat', (route) => route.fulfill({ status: 429, headers: { 'Content-Type': 'application/json', 'Retry-After': '42' }, body: JSON.stringify({ message: 'Hiciste muchas consultas a la IA seguidas. Espera 42 segundos e inténtalo de nuevo.' }) }));
    await page.locator('#legal-message').fill('¿Y si la institución no responde la aclaración?');
    await page.keyboard.press('Enter');
    await page.locator('[data-send-error]').waitFor();
    if (!(await page.getByText('Lici necesita un respiro').count())) problems.push('[escritorio-claro] el 429 no muestra el mensaje de Lici');
    if ((await page.locator('#legal-message').inputValue()) === '') problems.push('[escritorio-claro] tras el 429 se perdió la consulta escrita');
    await shot(page, 'limite-429-escritorio-claro');
    await page.unroute('**/agente-legal/chat');
});

// 2. Móvil claro: pantalla completa, hoja de conversaciones, campo fijo abajo.
await phase('movil-claro', 'movil', 'claro', async (page) => {
    await login(page, ADMIN);
    await openPage(page);
    await checkLayout(page, 'movil-claro');
    await shot(page, 'vacio-movil-claro');
    await page.getByRole('button', { name: 'Conversaciones' }).click();
    const sheet = page.getByRole('dialog', { name: 'Conversaciones' });
    await sheet.waitFor();
    await shot(page, 'hoja-conversaciones-movil-claro');
    await sheet.locator('nav button').first().click();
    await page.locator('[data-message="assistant"]').first().waitFor({ timeout: 15000 });
    await page.waitForTimeout(600);
    await checkLayout(page, 'movil-claro-conversacion');
    await shot(page, 'conversacion-movil-claro');
    await page.locator('#legal-message').fill('Una consulta larga para ver cómo crece el campo de escritura\ncon varias líneas\ny que siga fijo abajo.');
    await shot(page, 'campo-crece-movil-claro');
    await page.getByRole('button', { name: 'Redactar escrito' }).click();
    await page.getByRole('dialog', { name: 'Redactar escrito' }).waitFor();
    await shot(page, 'escrito-paso1-movil-claro');
});

// 3. Oscuro: escritorio y móvil.
for (const viewport of ['escritorio', 'movil']) {
    await phase(`${viewport}-oscuro`, viewport, 'oscuro', async (page) => {
        await login(page, ADMIN);
        await openPage(page);
        await shot(page, `vacio-${viewport}-oscuro`);
        if (viewport === 'movil') {
            await page.getByRole('button', { name: 'Conversaciones' }).click();
            await page.getByRole('dialog', { name: 'Conversaciones' }).locator('nav button').first().click();
        } else {
            await page.locator('nav[aria-label="Conversaciones"] button').first().click();
        }
        await page.locator('[data-message="assistant"]').first().waitFor({ timeout: 15000 });
        await shot(page, `conversacion-${viewport}-oscuro`);
        await page.getByRole('button', { name: 'Redactar escrito' }).click();
        const dialog = page.getByRole('dialog', { name: 'Redactar escrito' });
        await dialog.waitFor();
        await dialog.getByRole('button', { name: /Gestión de cobro/ }).click();
        await shot(page, `escrito-paso2-${viewport}-oscuro`);
    });
}

// 4. Lector: sin acceso a la IA.
for (const viewport of ['escritorio', 'movil']) {
    await phase(`lector-${viewport}`, viewport, 'claro', async (page) => {
        await login(page, VIEWER);
        const response = await page.goto(`${BASE_URL}/agente-legal`);
        if (response.status() !== 403) problems.push(`[lector-${viewport}] /agente-legal respondió ${response.status()} (se esperaba 403)`);
        await page.waitForTimeout(800);
        await page.screenshot({ path: `${OUTPUT}/lector-${viewport}-claro.png` });
        console.log(`ok  lector-${viewport}-claro`);
    });
}

// 5. La pantalla de acceso sigue mostrando a Lici (Mascot.vue cambió).
for (const viewport of ['escritorio', 'movil']) {
    await phase(`acceso-${viewport}`, viewport, 'claro', async (page) => {
        await page.goto(`${BASE_URL}/login`);
        await page.locator('svg.lici').first().waitFor({ timeout: 20000 });
        await page.waitForTimeout(3500);
        const box = await page.locator('.auth__perch svg.lici').boundingBox();
        if (!box || box.width < 80) problems.push(`[acceso-${viewport}] Lici no se ve en la pantalla de acceso`);
        const props = await page.locator('.auth__perch .lici__prop').evaluateAll((nodes) => nodes.map((node) => getComputedStyle(node).opacity));
        if (props.some((opacity) => Number(opacity) > 0)) problems.push(`[acceso-${viewport}] la utilería de Lici se ve en el acceso`);
        await page.screenshot({ path: `${OUTPUT}/acceso-${viewport}.png` });
        console.log(`ok  acceso-${viewport}`);
    });
}

await browser.close();

if (problems.length) {
    console.log(`\n${problems.length} problema(s):`);
    problems.forEach((problem) => console.log(`  ${problem}`));
    process.exit(1);
}
console.log('\nSin errores de consola ni scroll horizontal.');
