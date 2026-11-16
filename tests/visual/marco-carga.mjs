// B-06 y rendimiento del marco: una visita lenta (se retrasa 1,6 s la respuesta de Inertia) no deja la
// página anterior atenuada ni congelada (a los 300 ms aparece el esqueleto), y navegar no dispara
// peticiones extra del marco (solo la visita de Inertia).
//   node tests/visual/marco-carga.mjs
import { mkdirSync, writeFileSync } from 'node:fs';
import { chromium } from 'playwright';
import { BASE_URL, USERS, login, openContext } from './auditoria/comun.mjs';

const OUT = 'storage/app/qa/capturas/marco';
mkdirSync(OUT, { recursive: true });
const result = {};

const browser = await chromium.launch();
for (const view of ['d1440', 'm390']) {
    const context = await openContext(browser, view, 'claro');
    const page = await context.newPage();
    await login(page, USERS[process.env.QA_USUARIO ?? "operador"]);
    await page.waitForSelector('.cap-shell');
    await page.waitForLoadState('networkidle').catch(() => {});

    // Peticiones por navegación (sin contar Vite).
    const requests = [];
    page.on('request', (request) => {
        const url = new URL(request.url());
        if (url.port === '5173' || url.pathname.startsWith('/@') || url.pathname.startsWith('/resources/') || url.pathname.startsWith('/node_modules/')) return;
        requests.push(`${request.method()} ${url.pathname}${request.headers()['x-inertia'] ? ' (inertia)' : ''}`);
    });
    for (const path of ['/licitaciones', '/cotizaciones', '/calendario', '/']) {
        requests.length = 0;
        await page.evaluate((target) => window.__inertia.router.visit(target), path);
        await page.waitForFunction((target) => window.location.pathname === target, path, { timeout: 90000 });
        await page.waitForLoadState('networkidle').catch(() => {});
        result[`${view} ${path}`] = [...requests];
    }

    // Visita lenta a Reportes.
    await page.route('**/reportes', async (route) => {
        await new Promise((resolve) => setTimeout(resolve, 1600));
        await route.continue();
    });
    await page.evaluate(() => window.__inertia.router.visit('/reportes'));
    await page.waitForTimeout(150);
    await page.screenshot({ path: `${OUT}/lenta-${view}-150ms.png` });
    await page.waitForTimeout(650);
    const state = await page.evaluate(() => ({
        skeleton: Boolean(document.querySelector('.app-page-skeleton')),
        opacity: getComputedStyle(document.querySelector('.cap-shell__content')).opacity,
        title: document.querySelector(window.innerWidth < 1024 ? '.cap-shell-topbar__title' : '.cap-shell-header__title')?.textContent,
    }));
    await page.screenshot({ path: `${OUT}/lenta-${view}-800ms.png` });
    await page.waitForFunction(() => window.location.pathname === '/reportes', null, { timeout: 90000 });
    await page.waitForTimeout(600);
    result[`${view} lenta`] = { ...state, skeletonAlLlegar: (await page.evaluate(() => Boolean(document.querySelector('.app-page-skeleton')))), mounts: await page.evaluate(() => window.__crmShellMounts) };
    await page.screenshot({ path: `${OUT}/lenta-${view}-final.png` });
    await context.close();
}
await browser.close();
writeFileSync(`${OUT}/resumen-carga.json`, JSON.stringify(result, null, 2));
console.log(JSON.stringify(result, null, 2));
void BASE_URL;
