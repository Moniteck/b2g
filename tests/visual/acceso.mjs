// Recorrido visual de la pantalla de acceso animada y la solicitud de cuenta.
//
//   node tests/visual/acceso.mjs
//
// 1. Escritorio: carga escalonada, Lici sigue al puntero, volteo a «Crear cuenta», escribir (la mirada
//    sigue el texto), contraseña (se tapa los ojos / espía), error (sacude), envío y celebración,
//    intento de entrar con la cuenta pendiente, ingreso de qa.admin con la salida animada.
// 2. qa.admin aprueba la solicitud en Usuarios y la persona nueva entra.
// 3. Celular: swipe táctil real (CDP) con arrastre corto que vuelve y arrastre que cambia de cara.
// 4. Movimiento reducido (escritorio y celular) y claro/oscuro de cada cara.
// Fotogramas en storage/app/qa/capturas/acceso/. Termina con código 1 si hay errores de consola,
// scroll horizontal o un paso que falle.
import { mkdirSync } from 'node:fs';
import { chromium } from 'playwright';

const BASE_URL = process.env.QA_URL ?? 'http://localhost:8000';
const ADMIN = { email: 'qa.admin@crm-b2g.test', password: process.env.QA_PASSWORD ?? 'QaB2g#2026' };
const OUTPUT = 'storage/app/qa/capturas/acceso';
const VIEWPORTS = { escritorio: { width: 1440, height: 900 }, movil: { width: 390, height: 844 } };
const stamp = Date.now();
const APPLICANT = { name: 'Paola Solicitante', email: `qa.solicitud.${stamp}@crm-b2g.test`, password: 'Licitar2026Segura!' };

mkdirSync(OUTPUT, { recursive: true });
const problems = [];
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function openContext(browser, viewport, theme, { reduced = false } = {}) {
    const mobile = viewport === 'movil';
    const context = await browser.newContext({
        viewport: VIEWPORTS[viewport],
        deviceScaleFactor: 1,
        isMobile: mobile,
        hasTouch: mobile,
        locale: 'es-HN',
        timezoneId: 'America/Tegucigalpa',
        reducedMotion: reduced ? 'reduce' : 'no-preference',
    });
    await context.addInitScript((value) => {
        try {
            localStorage.setItem('theme', value);
        } catch {
            // Sin almacenamiento el tema sale del sistema.
        }
    }, theme === 'oscuro' ? 'dark' : 'light');
    const page = await context.newPage();
    const label = `${viewport}-${theme}${reduced ? '-reducido' : ''}`;
    page.on('console', (message) => {
        if (message.type() === 'error') problems.push(`[${label}] consola: ${message.text()}`);
    });
    page.on('pageerror', (error) => problems.push(`[${label}] excepción: ${error.message}`));
    return { context, page };
}

const shot = (page, name) => page.screenshot({ path: `${OUTPUT}/${name}.png` });
/** Acercamiento a Lici para revisar cada estado. */
const mascotShot = (page, name) => page.locator('.auth__perch').screenshot({ path: `${OUTPUT}/lici-${name}.png` }).catch(() => {});

/** Abre la ruta y espera a que la escena esté montada (reintenta si Vite recarga en medio). */
async function open(page, path) {
    for (let attempt = 1; attempt <= 3; attempt++) {
        try {
            await page.goto(`${BASE_URL}${path}`, { waitUntil: 'domcontentloaded' });
            await page.waitForSelector('.auth .auth-card', { timeout: 15000 });
            await page.evaluate(() => document.fonts.ready);
            await page.waitForSelector('.auth-intro', { state: 'detached', timeout: 8000 });
            return;
        } catch (error) {
            if (attempt === 3) throw error;
            await wait(1500);
        }
    }
}

async function overflow(page, label) {
    const extra = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    if (extra > 0) problems.push(`[${label}] scroll horizontal de ${extra}px`);
}

async function step(name, action) {
    try {
        await action();
        console.log(`ok  ${name}`);
    } catch (error) {
        problems.push(`[paso] ${name}: ${error.message.split('\n')[0]}`);
        console.log(`MAL ${name}: ${error.message.split('\n')[0]}`);
    }
}

async function mascotState(page) {
    return page.evaluate(() => [...(document.querySelector('.auth .lici')?.classList ?? [])].find((name) => name.startsWith('lici--'))?.slice(6));
}

async function expectMascot(page, expected) {
    await page.waitForFunction((value) => document.querySelector('.auth .lici')?.classList.contains(`lici--${value}`), expected, { timeout: 4000 });
}

async function chooseCompany(page, text) {
    const value = await page.$eval('#request-company', (select, wanted) => [...select.options].find((option) => option.textContent.includes(wanted))?.value ?? '', text);
    if (!value) throw new Error(`No aparece la empresa «${text}» en la lista.`);
    await page.selectOption('#request-company', value);
}

/** Swipe táctil real con el protocolo de Chrome: touchStart, varios touchMove y touchEnd. */
async function swipe(page, cdp, { fromX, toX, y, steps = 10, release = true, capture = null }) {
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: fromX, y }] });
    for (let index = 1; index <= steps; index++) {
        const x = fromX + ((toX - fromX) * index) / steps;
        await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x, y }] });
        await wait(16);
        if (capture && index === Math.round(steps * 0.6)) await shot(page, capture);
    }
    if (release) await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
}

const browser = await chromium.launch();

// ---- 1. Escritorio, claro: recorrido completo --------------------------------------------------
{
    const { context, page } = await openContext(browser, 'escritorio', 'claro');

    await step('intro completa y carga escalonada', async () => {
        await page.goto(`${BASE_URL}/login`, { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('.auth-intro');
        await wait(250);
        await shot(page, '00a-intro-ondas');
        await wait(550);
        await shot(page, '00b-intro-logo');
        await wait(550);
        await shot(page, '00c-intro-nombre');
        await wait(450);
        await shot(page, '00d-intro-lici');
        await page.waitForSelector('.auth-intro', { state: 'detached', timeout: 6000 });
        await shot(page, '01-revela-acceso');
        await wait(420);
        await shot(page, '02-entrada-escalonada');
        await wait(1400);
        await shot(page, '03-carga-final');
        await mascotShot(page, '0-reposo');
        await overflow(page, 'escritorio login');
    });

    await step('intro breve en la misma sesión y se salta con una tecla', async () => {
        await page.goto(`${BASE_URL}/login`, { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('.auth-intro');
        await wait(150);
        await shot(page, '03b-intro-breve');
        await page.keyboard.press('Shift');
        await page.waitForSelector('.auth-intro', { state: 'detached', timeout: 3000 });
        await wait(1500);
    });

    await step('tarjeta 3D y Lici siguen al puntero', async () => {
        const box = await page.locator('.auth__tilt').boundingBox();
        await page.mouse.move(box.x + box.width * 0.9, box.y + box.height * 0.15, { steps: 8 });
        await wait(700);
        await shot(page, '04a-inclinacion-3d');
        const tilted = await page.$eval('.auth__tilt', (element) => element.style.transform);
        if (!tilted.includes('rotate')) throw new Error('La tarjeta no se inclinó');
        await page.mouse.move(200, 120, { steps: 6 });
        await wait(300);
        await shot(page, '04-mira-izquierda');
        await page.mouse.move(1400, 860, { steps: 6 });
        await wait(300);
        await shot(page, '05-mira-abajo-derecha');
    });

    await step('volteo a «Crear cuenta»', async () => {
        await page.click('text=Solicitar acceso');
        await wait(150);
        await shot(page, '06-volteo-150ms');
        await wait(170);
        await shot(page, '07-volteo-canto');
        await wait(260);
        await shot(page, '08-volteo-llegando');
        await page.waitForURL('**/solicitar-acceso');
        await wait(700);
        await shot(page, '09-crear-cuenta');
        const focused = await page.evaluate(() => document.activeElement?.textContent?.trim());
        if (focused !== 'Solicita tu acceso') throw new Error(`El foco quedó en «${focused}»`);
        const hidden = await page.$eval('#auth-face-login', (element) => element.inert && element.getAttribute('aria-hidden') === 'true');
        if (!hidden) throw new Error('La cara oculta no quedó inert/aria-hidden');
    });

    await step('escribir con la mirada siguiendo', async () => {
        await page.click('#request-name');
        await page.keyboard.type(APPLICANT.name, { delay: 25 });
        await expectMascot(page, 'watch');
        await shot(page, '10-escribe-nombre');
        await mascotShot(page, '1-mira');
        await page.click('#request-email');
        await page.keyboard.type(APPLICANT.email, { delay: 10 });
        await shot(page, '11-escribe-correo');
        await chooseCompany(page, 'CAP Honduras');
    });

    await step('contraseña: se tapa los ojos y espía', async () => {
        await page.click('#request-password');
        await page.keyboard.type('abc', { delay: 40 });
        await expectMascot(page, 'shy');
        await wait(400);
        await shot(page, '12-tapa-ojos');
        await mascotShot(page, '2-tapa-ojos');
        await page.click('#request-password ~ .auth-field__icon-button');
        await page.focus('#request-password');
        await expectMascot(page, 'peek');
        await wait(400);
        await shot(page, '13-espia');
        await mascotShot(page, '3-espia');
        await page.click('#request-password ~ .auth-field__icon-button');
        await page.fill('#request-password-confirmation', 'abd');
    });

    await step('error: sacude y avisa', async () => {
        await page.click('.auth-form--request button[type="submit"]');
        await page.waitForSelector('#request-position-error');
        await wait(120);
        await shot(page, '14-error-sacude');
        await mascotShot(page, '4-preocupado');
        await wait(600);
        await shot(page, '15-error-mensajes');
        const state = await mascotState(page);
        if (!['worried', 'shy', 'watch'].includes(state)) throw new Error(`Lici quedó en «${state}» tras el error`);
    });

    await step('envío y celebración', async () => {
        await page.fill('#request-position', 'Analista de licitaciones');
        await page.fill('#request-password', APPLICANT.password);
        await page.fill('#request-password-confirmation', APPLICANT.password);
        await expectMascot(page, 'happy');
        await wait(250);
        await shot(page, '16-fortaleza-excelente');
        await mascotShot(page, '6-contento');
        await page.locator('#request-position').scrollIntoViewIfNeeded();
        await page.focus('#request-position');
        await wait(350);
        await page.locator('#request-position').locator('xpath=ancestor::div[contains(@class,"auth-field")][1]').screenshot({ path: `${OUTPUT}/16b-campo-flotante-foco.png` });
        await page.route('**/solicitar-acceso', async (route) => {
            await wait(700);
            await route.continue();
        });
        await page.click('.auth-form--request button[type="submit"]');
        await wait(250);
        await page.locator('.auth-form--request .auth-submit').screenshot({ path: `${OUTPUT}/16c-boton-cargando.png` });
        await page.waitForSelector('.auth-sent');
        await page.unroute('**/solicitar-acceso');
        await wait(250);
        await shot(page, '17-celebra-confeti');
        await wait(500);
        await shot(page, '18-celebra-salto');
        await mascotShot(page, '5-celebra');
        await wait(1200);
        await shot(page, '19-solicitud-enviada');
    });

    await step('la cuenta pendiente no puede entrar', async () => {
        await page.click('text=Volver a ingresar');
        await page.waitForURL('**/login');
        await wait(900);
        const prefilled = await page.inputValue('#login-email');
        if (prefilled !== APPLICANT.email) throw new Error('El correo no quedó precargado');
        await page.fill('#login-password', APPLICANT.password);
        await page.click('.auth-card button[type="submit"]');
        await page.waitForSelector('text=Tu solicitud está en revisión');
        await wait(500);
        await shot(page, '20-pendiente');
    });

    await step('credenciales incorrectas', async () => {
        await page.fill('#login-email', ADMIN.email);
        await page.fill('#login-password', 'incorrecta-123');
        await page.click('.auth-card button[type="submit"]');
        await page.waitForSelector('text=No pudimos ingresar');
        await wait(150);
        await shot(page, '21-error-ingreso');
    });

    await step('ingreso con salida animada', async () => {
        await page.fill('#login-password', ADMIN.password);
        await page.click('.auth-card button[type="submit"]');
        await page.waitForSelector('.auth-curtain', { timeout: 10000 });
        await wait(60);
        await shot(page, '22-salida-recoge');
        await wait(150);
        await shot(page, '23-salida-onda');
        await wait(350);
        await shot(page, '24-salida-hola');
        await page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 10000 });
        await shot(page, '25-dashboard-bajo-cortina');
        await page.waitForSelector('.auth-curtain', { state: 'detached', timeout: 7000 });
        await wait(400);
        await shot(page, '26-dashboard');
    });

    // ---- 2. Aprobación como qa.admin y entrada de la persona -------------------------------------
    await step('qa.admin aprueba la solicitud', async () => {
        await page.goto(`${BASE_URL}/configuracion/usuarios`);
        const row = page.locator('#solicitudes li', { hasText: APPLICANT.email });
        await row.waitFor({ timeout: 15000 });
        await page.locator('#solicitudes').scrollIntoViewIfNeeded();
        await shot(page, '27-usuarios-solicitudes');
        await row.getByRole('button', { name: 'Aprobar' }).click();
        await page.waitForSelector('role=dialog');
        await wait(300);
        await shot(page, '28-aprobar-modal');
        await page.getByRole('button', { name: 'Aprobar acceso' }).click();
        await row.waitFor({ state: 'detached', timeout: 10000 });
        await wait(400);
        await shot(page, '29-aprobada');
    });

    await step('la campana avisó a qa.admin', async () => {
        await page.goto(`${BASE_URL}/`);
        const bell = page.locator('button[aria-controls="cap-notifications"]:visible').first();
        await bell.waitFor({ timeout: 15000 });
        await bell.click();
        await page.waitForSelector('#cap-notifications >> text=Nueva solicitud de acceso', { timeout: 8000 });
        await wait(300);
        await shot(page, '29b-campana');
        await page.keyboard.press('Escape');
    });

    await context.close();

    const fresh = await openContext(browser, 'escritorio', 'claro');
    await step('la persona aprobada entra', async () => {
        await open(fresh.page, '/login');
        await fresh.page.fill('#login-email', APPLICANT.email);
        await fresh.page.fill('#login-password', APPLICANT.password);
        await fresh.page.click('.auth-card button[type="submit"]');
        await fresh.page.waitForSelector('.auth-curtain', { timeout: 10000 });
        const curtainAt = Date.now();
        await fresh.page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 15000 });
        console.log(`    la cortina dura ${Date.now() - curtainAt} ms hasta el cambio de página (incluye cargar la página en desarrollo)`);
        await fresh.page.waitForSelector('.auth-curtain', { state: 'detached', timeout: 7000 });
        await wait(500);
        await shot(fresh.page, '30-aprobada-entra');
    });
    await fresh.context.close();
}

// ---- 3. Celular: swipe táctil real ---------------------------------------------------------------
for (const theme of ['claro', 'oscuro']) {
    const { context, page } = await openContext(browser, 'movil', theme);
    const cdp = await context.newCDPSession(page);
    await step(`celular ${theme}: swipe`, async () => {
        await page.goto(`${BASE_URL}/login`, { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('.auth-intro');
        await wait(1500);
        await shot(page, `39-movil-${theme}-intro`);
        await page.waitForSelector('.auth-intro', { state: 'detached', timeout: 6000 });
        await wait(2000);
        await shot(page, `40-movil-${theme}-login`);
        await overflow(page, `movil ${theme} login`);
        const box = await page.locator('.auth-card').boundingBox();
        const y = box.y + 130;

        // Arrastre corto: sigue al dedo y vuelve.
        await swipe(page, cdp, { fromX: 300, toX: 230, y, steps: 6, capture: `41-movil-${theme}-arrastre-corto` });
        await wait(600);
        if (new URL(page.url()).pathname !== '/login') throw new Error('El arrastre corto cambió de cara');

        // Arrastre largo de derecha a izquierda: pasa a «Crear cuenta».
        await swipe(page, cdp, { fromX: 340, toX: 60, y, steps: 12, capture: `42-movil-${theme}-arrastre` });
        await page.waitForURL('**/solicitar-acceso', { timeout: 4000 });
        await wait(700);
        await shot(page, `43-movil-${theme}-crear-cuenta`);
        await overflow(page, `movil ${theme} crear cuenta`);
        await page.screenshot({ path: `${OUTPUT}/44-movil-${theme}-crear-cuenta-completa.png`, fullPage: true });

        // De vuelta con un gesto rápido de izquierda a derecha.
        await page.evaluate(() => window.scrollTo(0, 0));
        await swipe(page, cdp, { fromX: 60, toX: 250, y, steps: 4 });
        await page.waitForURL('**/login', { timeout: 4000 });
        await wait(600);
        await shot(page, `45-movil-${theme}-vuelve`);
    });
    await context.close();
}

// ---- 4. Claro/oscuro en escritorio y movimiento reducido ----------------------------------------
{
    const { context, page } = await openContext(browser, 'escritorio', 'oscuro');
    await step('escritorio oscuro', async () => {
        await page.goto(`${BASE_URL}/login`, { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('.auth-intro');
        await wait(1300);
        await shot(page, '49-intro-oscuro');
        await page.waitForSelector('.auth-intro', { state: 'detached', timeout: 6000 });
        await wait(2000);
        await shot(page, '50-escritorio-oscuro-login');
        await page.mouse.move(5, 5);
        await wait(16500);
        await expectMascot(page, 'sleepy');
        await mascotShot(page, '7-dormido');
        await page.mouse.move(600, 400, { steps: 4 });
        await wait(120);
        await mascotShot(page, '8-sorprendido');
        await open(page, '/solicitar-acceso');
        await wait(2000);
        await shot(page, '51-escritorio-oscuro-crear-cuenta');
    });
    await context.close();
}

for (const viewport of ['escritorio', 'movil']) {
    const { context, page } = await openContext(browser, viewport, 'claro', { reduced: true });
    await step(`movimiento reducido ${viewport}`, async () => {
        await open(page, '/login');
        await wait(200);
        await shot(page, `60-reducido-${viewport}-carga`);
        await page.click(viewport === 'movil' ? '#auth-tab-register' : 'text=Solicitar acceso');
        await wait(90);
        await shot(page, `61-reducido-${viewport}-fundido`);
        await page.waitForURL('**/solicitar-acceso');
        await wait(500);
        await shot(page, `62-reducido-${viewport}-crear-cuenta`);
        const confetti = await page.evaluate(() => document.querySelectorAll('.auth-confetti, .auth-intro').length);
        if (confetti) throw new Error('Hay confeti o intro con movimiento reducido');
        if (viewport === 'escritorio') {
            await page.click('text=Ya tengo cuenta');
            await page.waitForURL('**/login');
            await page.fill('#login-email', ADMIN.email);
            await page.fill('#login-password', ADMIN.password);
            await page.click('.auth-card button[type="submit"]');
            await page.waitForSelector('.auth-curtain', { timeout: 10000 });
            await wait(150);
            await shot(page, '63-reducido-salida');
            await page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 10000 });
            await page.waitForSelector('.auth-curtain', { state: 'detached', timeout: 7000 });
        }
    });
    await context.close();
}

await browser.close();

if (problems.length) {
    console.log(`\n${problems.length} problema(s):\n${problems.join('\n')}`);
    process.exit(1);
}
console.log(`\nSin problemas. Capturas en ${OUTPUT}/`);
