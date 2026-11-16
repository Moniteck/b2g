// Enlaces directos de Cotizaciones, Inventario y Post-adjudicación, a 1440 y 390, con admin y lector.
//
//   node tests/visual/enlaces-directos.mjs
//
// Variables: QA_URL, QA_QUOTE (id de una cotización de CAP Honduras), QA_OTHER_QUOTE (de otra empresa), QA_ITEM.
// Comprueba que cada enlace abre su modal, que al cerrarla se limpia el parámetro sin recargar y que las
// rutas JSON abiertas en el navegador terminan en la pantalla con la modal abierta.
// Capturas en storage/app/qa/capturas/enlaces-directos/. Termina con código 1 si algo falla.
import { mkdirSync } from 'node:fs';
import { chromium } from 'playwright';

const BASE_URL = process.env.QA_URL ?? 'http://localhost:8000';
const PASSWORD = 'QaB2g#2026';
const OUTPUT = 'storage/app/qa/capturas/enlaces-directos';
const QUOTE = process.env.QA_QUOTE ?? '1';
const OTHER_QUOTE = process.env.QA_OTHER_QUOTE ?? '5';
const ITEM = process.env.QA_ITEM ?? '1';

const USERS = { admin: 'qa.admin@crm-b2g.test', lector: 'qa.lector@crm-b2g.test' };
const VIEWPORTS = { escritorio: { width: 1440, height: 900 }, movil: { width: 390, height: 844 } };

// name, url, ruta esperada tras cargar, parámetro que debe limpiarse, roles
const CASES = [
    { name: 'cotizacion-ver', url: `/cotizaciones?ver=${QUOTE}`, path: '/cotizaciones', param: 'ver' },
    { name: 'cotizacion-json', url: `/cotizaciones/${QUOTE}`, path: '/cotizaciones', param: 'ver' },
    { name: 'cotizacion-nueva', url: '/cotizaciones?accion=nueva', path: '/cotizaciones', param: 'accion', roles: ['admin'] },
    { name: 'inventario-ver', url: `/inventario?ver=${ITEM}`, path: '/inventario', param: 'ver' },
    { name: 'inventario-json', url: `/inventario/${ITEM}`, path: '/inventario', param: 'ver' },
    { name: 'inventario-sync', url: '/inventario?panel=sincronizaciones', path: '/inventario', param: 'panel' },
    { name: 'inventario-sync-json', url: '/inventario/sincronizaciones', path: '/inventario', param: 'panel' },
    { name: 'feriados', url: '/post-adjudicacion?panel=feriados', path: '/post-adjudicacion', param: 'panel' },
    { name: 'feriados-json', url: '/post-adjudicacion/feriados', path: '/post-adjudicacion', param: 'panel' },
];

const problems = [];

async function login(page, email) {
    await page.goto(`${BASE_URL}/login`);
    await page.fill('#login-email', email);
    await page.fill('#login-password', PASSWORD);
    await Promise.all([page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 20000 }), page.click('button[type="submit"]')]);
}

async function stable(page) {
    await page.waitForLoadState('load', { timeout: 20000 }).catch(() => {});
    await page.waitForSelector('main', { timeout: 15000 });
    await page.waitForTimeout(1200);
}

async function attempt(label, fn, tries = 3) {
    for (let i = 1; i <= tries; i++) {
        try {
            return await fn();
        } catch (error) {
            if (i === tries) problems.push(`[${label}] ${error.message.split('\n')[0]}`);
        }
    }
}

async function closeDialog(page) {
    await page.keyboard.press('Escape');
    for (let i = 0; i < 25 && (await page.locator('[role="dialog"]:visible').count()); i++) await page.waitForTimeout(200);
    if (await page.locator('[role="dialog"]:visible').count()) throw new Error('la modal no se cerró con Escape');
}

const browser = await chromium.launch();
mkdirSync(OUTPUT, { recursive: true });

for (const [role, email] of Object.entries(USERS)) {
    for (const [viewport, size] of Object.entries(VIEWPORTS)) {
        const context = await browser.newContext({ viewport: size, isMobile: viewport === 'movil', hasTouch: viewport === 'movil', locale: 'es-HN', timezoneId: 'America/Tegucigalpa' });
        const page = await context.newPage();
        page.on('console', (message) => {
            if (message.type() === 'error' && !/Failed to load resource.*404/.test(message.text())) problems.push(`[${role}/${viewport}] consola: ${message.text()}`);
        });
        page.on('pageerror', (error) => problems.push(`[${role}/${viewport}] excepción: ${error.message}`));
        await login(page, email);

        for (const item of CASES.filter((entry) => !entry.roles || entry.roles.includes(role))) {
            const label = `${role}/${viewport}/${item.name}`;
            await attempt(label, async () => {
                await page.goto(`${BASE_URL}${item.url}`);
                await stable(page);
                const url = new URL(page.url());
                if (url.pathname !== item.path) throw new Error(`terminó en ${url.pathname}`);
                const dialog = page.locator('[role="dialog"]:visible').last();
                await dialog.waitFor({ state: 'visible', timeout: 10000 });
                await page.waitForTimeout(600);
                await page.screenshot({ path: `${OUTPUT}/${item.name}-${role}-${viewport}.png` });
                const scroll = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
                if (scroll) problems.push(`[${label}] scroll horizontal`);
                await closeDialog(page);
                await page.waitForTimeout(300);
                if (new URL(page.url()).searchParams.has(item.param)) throw new Error(`el parámetro ${item.param} sigue en la URL`);
            });
        }

        // Cotización de otra empresa: toast de error y lista normal sin modal.
        await attempt(`${role}/${viewport}/cotizacion-ajena`, async () => {
            await page.goto(`${BASE_URL}/cotizaciones?ver=${OTHER_QUOTE}`);
            await stable(page);
            await page.getByText('No se encontró la cotización').first().waitFor({ timeout: 8000 });
            if (await page.locator('[role="dialog"]:visible').count()) throw new Error('se abrió una modal');
            if (new URL(page.url()).searchParams.has('ver')) throw new Error('el parámetro ver sigue en la URL');
            await page.screenshot({ path: `${OUTPUT}/cotizacion-ajena-${role}-${viewport}.png` });
        });

        // Lector: el atajo de nueva cotización no abre el formulario.
        if (role === 'lector') {
            await attempt(`${role}/${viewport}/cotizacion-nueva-sin-permiso`, async () => {
                await page.goto(`${BASE_URL}/cotizaciones?accion=nueva`);
                await stable(page);
                await page.waitForTimeout(800);
                if (await page.locator('[role="dialog"]:visible').count()) throw new Error('el lector abrió el formulario');
            });
        }

        await context.close();
    }
}

await browser.close();

if (problems.length) {
    console.log(problems.join('\n'));
    process.exit(1);
}
console.log('Enlaces directos: todo correcto.');
