// Recorrido visual y funcional de Licitaciones (rediseño): lista en tabla, kanban y calendario, hoja de
// filtros, ficha con stepper y siguiente paso, importación de la captura (subida y pegada desde el
// portapapeles) con revisión y resaltado de zonas, avisos de contradicción y confirmación sin duplicar
// la oportunidad. Con qa.admin y qa.lector, a 1920, 1440, 1024, 390 y 360, en claro y oscuro.
//
//   node tests/visual/licitaciones.mjs              recorrido completo
//   node tests/visual/licitaciones.mjs --rapido     solo 1440 y 390
//
// Variables: QA_URL (http://localhost:8000). La lectura de la captura corre en la cola: usa el
// worker del entorno y, si no responde en 25 s, ejecuta `php artisan queue:work --stop-when-empty`.
// Capturas en storage/app/qa/capturas/licitaciones/. Termina con código 1 si hay errores de consola,
// scroll horizontal o un paso falla.
import { execSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { chromium } from 'playwright';
import { horizontalOverflow, settle } from './capturas.mjs';

const BASE_URL = process.env.QA_URL ?? 'http://localhost:8000';
const PASSWORD = process.env.QA_PASSWORD ?? 'QaB2g#2026';
const ADMIN = 'qa.admin@crm-b2g.test';
const VIEWER = 'qa.lector@crm-b2g.test';
const OUTPUT = 'storage/app/qa/capturas/licitaciones';
const CAPTURE = 'tests/fixtures/honducompras/captura-cpr-sit-025-2022.png';
const FAST = process.argv.includes('--rapido');
const VIEWPORTS = FAST
    ? { d1440: { width: 1440, height: 900 }, m390: { width: 390, height: 844 } }
    : { d1920: { width: 1920, height: 1080 }, d1440: { width: 1440, height: 900 }, t1024: { width: 1024, height: 768 }, m390: { width: 390, height: 844 }, m360: { width: 360, height: 780 } };
const THEMES = ['claro', 'oscuro'];
const problems = [];

mkdirSync(OUTPUT, { recursive: true });

const isMobile = (name) => name.startsWith('m');

async function open(browser, viewport, theme) {
    const context = await browser.newContext({ viewport: VIEWPORTS[viewport], deviceScaleFactor: 1, isMobile: isMobile(viewport), hasTouch: isMobile(viewport), locale: 'es-HN', timezoneId: 'America/Tegucigalpa' });
    await context.addInitScript((value) => {
        try {
            localStorage.setItem('theme', value);
        } catch {
            // Sin almacenamiento el tema sale del sistema.
        }
    }, theme === 'oscuro' ? 'dark' : 'light');
    await context.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: BASE_URL }).catch(() => {});
    const page = await context.newPage();
    const label = `${viewport}-${theme}`;
    page.on('console', (message) => {
        if (message.type() === 'error') problems.push(`[${label}] consola: ${message.text().slice(0, 200)}`);
    });
    page.on('pageerror', (error) => problems.push(`[${label}] excepción: ${error.message}`));
    return { context, page };
}

async function login(page, email) {
    await page.goto(`${BASE_URL}/login`);
    await page.fill('#login-email', email);
    await page.fill('#login-password', PASSWORD);
    await Promise.all([page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 20000 }), page.click('button[type="submit"]')]);
}

/** Vite con HMR puede dejar la página en blanco mientras otros agentes editan: se reintenta. */
async function go(page, path) {
    for (let attempt = 1; attempt <= 3; attempt++) {
        try {
            await page.goto(`${BASE_URL}${path}`);
            await settle(page);
            if (await page.locator('.cap-shell').count()) return;
        } catch (error) {
            if (attempt === 3) throw error;
        }
        await page.waitForTimeout(1500);
    }
    throw new Error(`La página ${path} no terminó de cargar`);
}

async function shot(page, name, { full = true } = {}) {
    const overflow = await horizontalOverflow(page);
    if (overflow > 1) problems.push(`[${name}] scroll horizontal de ${overflow}px`);
    await page.screenshot({ path: `${OUTPUT}/${name}.png`, fullPage: full, animations: 'disabled' });
    console.log(`ok  ${name}`);
}

async function step(name, action) {
    try {
        await action();
    } catch (error) {
        problems.push(`[${name}] ${error.message.split('\n')[0]}`);
        console.log(`ERR ${name}: ${error.message.split('\n')[0]}`);
    }
}

function artisan(command) {
    return execSync(`php artisan ${command}`, { encoding: 'utf8' }).trim();
}

function opportunityCount(reference) {
    return Number(artisan(`tinker --execute="echo App\\Models\\Opportunity::query()->withoutGlobalScopes()->whereIn('tender_id', App\\Models\\Tender::query()->withoutGlobalScopes()->where('reference', '${reference}')->pluck('id'))->count();"`).split('\n').pop());
}

async function waitForReview(page) {
    try {
        await page.waitForSelector('[data-import-form]', { timeout: 25000 });
    } catch {
        // El worker del entorno no respondió: se procesa la cola aquí.
        artisan('queue:work --stop-when-empty');
        await page.waitForSelector('[data-import-form]', { timeout: 20000 });
    }
    await page.waitForTimeout(1200);
}

/** Lista, sus tres vistas y la ficha, en una pantalla y un tema. */
async function screens(browser, viewport, theme, user = ADMIN, who = 'admin') {
    const { context, page } = await open(browser, viewport, theme);
    const prefix = `${who}-${viewport}-${theme}`;
    await login(page, user);

    await step(`${prefix} lista`, async () => {
        await go(page, '/licitaciones');
        await shot(page, `${prefix}-lista-tabla`);
        if (isMobile(viewport)) {
            // B-13: el objeto se recorta a 2 líneas (Chrome informa `flow-root` con line-clamp: se mira la propiedad y el alto).
            const clamp = await page.locator('[data-tender-card] p.line-clamp-2').first().evaluate((el) => {
                const style = getComputedStyle(el);
                return { lines: style.webkitLineClamp, tall: el.getBoundingClientRect().height > parseFloat(style.lineHeight) * 2 + 2 };
            }).catch(() => null);
            if (clamp && (clamp.lines !== '2' || clamp.tall)) problems.push(`[${prefix}] B-13: el objeto no se recorta a 2 líneas`);
        }
    });
    await step(`${prefix} kanban`, async () => {
        await go(page, '/licitaciones?vista=kanban');
        await shot(page, `${prefix}-lista-kanban`);
    });
    await step(`${prefix} calendario`, async () => {
        await go(page, '/licitaciones?vista=calendario');
        await shot(page, `${prefix}-lista-calendario`);
    });
    await step(`${prefix} ficha`, async () => {
        await go(page, '/licitaciones');
        const href = await page.locator('a[href^="/licitaciones/"]').filter({ hasText: /-/ }).first().getAttribute('href');
        await go(page, href);
        await page.locator('[data-tender-stepper]').waitFor();
        await shot(page, `${prefix}-ficha`);
        if (who === 'lector') {
            if (await page.locator('[data-next-step]').count()) problems.push(`[${prefix}] el lector ve el botón del siguiente paso`);
            if (await page.getByRole('button', { name: 'Editar' }).count()) problems.push(`[${prefix}] el lector ve «Editar»`);
        }
        // B-25: las pestañas se desplazan en móvil, sin desbordar la página.
        for (const tab of ['Historial', 'Oportunidad y cotizaciones']) {
            await page.getByRole('tab', { name: new RegExp(tab) }).click();
            await page.waitForTimeout(900);
        }
        await shot(page, `${prefix}-ficha-oportunidad`);
    });
    await context.close();
}

/** Hoja de filtros y chips (escritorio y móvil). */
async function filters(browser, viewport, theme) {
    const { context, page } = await open(browser, viewport, theme);
    const prefix = `admin-${viewport}-${theme}`;
    await login(page, ADMIN);
    await step(`${prefix} filtros`, async () => {
        await go(page, '/licitaciones');
        await page.locator('[data-open-filters]').click();
        await page.locator('[data-filter-sheet]').waitFor();
        await page.waitForTimeout(500);
        await shot(page, `${prefix}-filtros-hoja`, { full: false });
        // B-24: los campos de fecha llevan etiqueta visible.
        for (const label of ['Desde', 'Hasta']) {
            if (!(await page.locator('[data-filter-sheet] label', { hasText: label }).count())) problems.push(`[${prefix}] B-24: falta la etiqueta «${label}»`);
        }
        await page.locator('[data-filter-sheet] button', { hasText: 'Por analizar' }).click();
        await page.getByRole('switch', { name: /vencidas/ }).click().catch(async () => page.locator('[data-filter-sheet]').getByText('Incluir las vencidas sin oferta').click());
        await page.locator('[data-apply-filters]').click();
        await page.waitForTimeout(1500);
        await shot(page, `${prefix}-filtros-chips`);
    });
    await context.close();
}

/** Importación completa: subir (o pegar), leer, revisar con zonas, contradicciones y confirmar. */
async function importFlow(browser, viewport, theme, { paste = false } = {}) {
    const { context, page } = await open(browser, viewport, theme);
    const prefix = `admin-${viewport}-${theme}`;
    await login(page, ADMIN);

    await step(`${prefix} importar`, async () => {
        // Atajo de la PWA: abre la importación directamente.
        await go(page, '/licitaciones?accion=importar');
        await page.locator('[data-import-modal]').waitFor({ timeout: 8000 });
        await shot(page, `${prefix}-importar-1-modal`, { full: false });
        if (paste) {
            // Pegar: se pone la imagen en el portapapeles y se pulsa Ctrl+V (si el navegador no deja
            // escribir imágenes en el portapapeles, se envía el mismo evento «paste» con el archivo).
            const bytes = (await import('node:fs')).readFileSync(CAPTURE).toString('base64');
            const pasted = await page.evaluate(async (base64) => {
                const binary = atob(base64);
                const blob = new Blob([Uint8Array.from(binary, (char) => char.charCodeAt(0))], { type: 'image/png' });
                try {
                    await navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]);
                    return 'clipboard';
                } catch {
                    const data = new DataTransfer();
                    data.items.add(new File([blob], 'image.png', { type: 'image/png' }));
                    window.dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true }));
                    return 'event';
                }
            }, bytes);
            if (pasted === 'clipboard') await page.keyboard.press('Control+V');
            await page.locator('[data-capture-preview]').waitFor({ timeout: 5000 });
            console.log(`    pegado desde el portapapeles (${pasted})`);
        } else {
            await page.setInputFiles('[data-capture-input]', CAPTURE);
        }
        await page.waitForTimeout(400);
        await shot(page, `${prefix}-importar-2-vista-previa`, { full: false });
        await page.getByRole('button', { name: 'Leer captura' }).click();
        await page.waitForURL(/\/licitaciones\/importar\//, { timeout: 20000 });
        if (await page.locator('[data-import-pending]').count()) await shot(page, `${prefix}-importar-3-leyendo`, { full: false });
        await waitForReview(page);
        await shot(page, `${prefix}-importar-4-revision`);
        const height = await page.evaluate(() => document.documentElement.scrollHeight);
        console.log(`    alto de la revisión: ${height}px`);

        // Ir al siguiente dudoso: la captura resalta su zona.
        await page.locator('[data-next-doubtful]').click();
        await page.waitForTimeout(900);
        if (!(await page.locator('[data-capture-highlight]').count())) problems.push(`[${prefix}] no se resaltó la zona del campo dudoso`);
        await shot(page, `${prefix}-importar-5-dudoso`, { full: false });

        // Contradicción: aclaraciones después de la recepción de ofertas.
        const clarification = page.locator('[data-field="clarification_deadline"] input').first();
        await clarification.click();
        await clarification.fill('30/07/2022');
        await clarification.press('Tab');
        await page.waitForTimeout(600);
        if (!(await page.locator('[data-contradictions]').count())) problems.push(`[${prefix}] no se avisó la contradicción de fechas`);
        await page.locator('[data-contradictions]').scrollIntoViewIfNeeded().catch(() => {});
        await shot(page, `${prefix}-importar-6-contradiccion`, { full: false });
        await clarification.fill('20/07/2022');
        await clarification.press('Tab');

        // El OCR leyó mal el correo («contratacionesEssit.gob.ho»): al confirmar, el error queda bajo el campo.
        await page.locator('[data-confirm-import]').click();
        await page.waitForTimeout(1500);
        await shot(page, `${prefix}-importar-7-error-correo`, { full: false });
        await page.locator('[data-field="contact_email"] input').fill('contrataciones@sit.gob.hn');
    });

    await step(`${prefix} confirmar sin duplicar`, async () => {
        const before = opportunityCount('CPR-SIT-025-2022');
        await page.locator('[data-confirm-import]').click();
        await page.waitForURL(/\/licitaciones\/\d+/, { timeout: 20000 });
        await settle(page);
        await shot(page, `${prefix}-importar-8-ficha`, { full: false });
        const after = opportunityCount('CPR-SIT-025-2022');
        if (after > Math.max(before, 1)) problems.push(`[${prefix}] B-18: se duplicó la oportunidad (${before} → ${after})`);
        console.log(`    oportunidades del expediente: ${before} → ${after}`);
    });
    await context.close();
}

const browser = await chromium.launch();
for (const viewport of Object.keys(VIEWPORTS)) {
    for (const theme of THEMES) await screens(browser, viewport, theme);
}
for (const viewport of FAST ? ['d1440', 'm390'] : ['d1440', 'm390', 'm360']) {
    await screens(browser, viewport, 'claro', VIEWER, 'lector');
}
for (const viewport of ['d1440', 'm390']) {
    for (const theme of THEMES) await filters(browser, viewport, theme);
}
await importFlow(browser, 'd1440', 'claro');
await importFlow(browser, 'd1440', 'oscuro', { paste: true });
await importFlow(browser, 'm390', 'claro');
await importFlow(browser, 'm390', 'oscuro', { paste: true });
await browser.close();

if (problems.length) {
    console.log(`\n${problems.length} problemas:`);
    problems.forEach((problem) => console.log(` - ${problem}`));
    process.exit(1);
}
console.log('\nSin problemas.');
