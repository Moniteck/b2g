// Revisión visual del Dashboard y del arreglo de carga de Reportes.
//
//   node tests/visual/dashboard.mjs                 Dashboard (3 usuarios × 5 anchos × claro/oscuro) y Reportes
//   node tests/visual/dashboard.mjs dashboard       solo el Dashboard
//   node tests/visual/dashboard.mjs reportes        solo Reportes (navegación, filtros rápidos, exportes)
//
// Variables: QA_URL (http://localhost:8000), QA_PASSWORD.
// Capturas en storage/app/qa/capturas/dashboard/. Termina con código 1 si hay errores de consola,
// scroll horizontal o un bloque que no carga.
import { mkdirSync, writeFileSync } from 'node:fs';
import { chromium } from 'playwright';

const BASE_URL = process.env.QA_URL ?? 'http://localhost:8000';
const PASSWORD = process.env.QA_PASSWORD ?? 'QaB2g#2026';
const OUTPUT = 'storage/app/qa/capturas/dashboard';
const ALL_USERS = { admin: 'qa.admin@crm-b2g.test', lector: 'qa.lector@crm-b2g.test', adminb: 'qa.adminb@crm-b2g.test' };
// QA_USUARIOS=admin,lector  QA_ANCHOS=1440,390  QA_TEMAS=claro  acotan la corrida.
const USERS = Object.fromEntries(Object.entries(ALL_USERS).filter(([key]) => !process.env.QA_USUARIOS || process.env.QA_USUARIOS.split(',').includes(key)));
const WIDTHS = (process.env.QA_ANCHOS ?? '1920,1440,1024,390,360').split(',').map(Number);
const THEMES = (process.env.QA_TEMAS ?? 'claro,oscuro').split(',');
const only = process.argv.slice(2);
const problems = [];
const report = { dashboard: [], reportes: [] };

mkdirSync(OUTPUT, { recursive: true });

// Ruido ajeno a estas pantallas (CSP del servidor de Vite en IPv6, fuentes de terceros).
const IGNORED = [/Content Security Policy/i, /cdn\.jsdelivr/i, /\[::1\]:5173/];

function watch(page, label) {
    page.on('console', (message) => {
        if (message.type() !== 'error') return;
        const text = message.text();
        if (IGNORED.some((pattern) => pattern.test(text))) return;
        problems.push(`[${label}] consola: ${text.slice(0, 300)}`);
    });
    page.on('pageerror', (error) => problems.push(`[${label}] excepción: ${error.message}`));
}

async function login(context, email) {
    const page = await context.newPage();
    for (let attempt = 1; attempt <= 3; attempt++) {
        try {
            await page.goto(`${BASE_URL}/login`);
            await page.fill('#login-email', email);
            await page.fill('#login-password', PASSWORD);
            await Promise.all([page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 30000, waitUntil: 'commit' }), page.click('button[type="submit"]')]);
            await page.waitForLoadState('networkidle').catch(() => {});
            await page.waitForTimeout(1500);
            return page;
        } catch (error) {
            if (attempt === 3) throw error;
            await page.waitForTimeout(3000);
        }
    }
    return page;
}

/** Espera a que el Dashboard y sus bloques diferidos estén pintados; reintenta si Vite recargó en blanco. */
async function openDashboard(page, path = '/') {
    for (let attempt = 1; attempt <= 3; attempt++) {
        try {
            await page.goto(`${BASE_URL}${path}`, { waitUntil: 'domcontentloaded' });
            await page.waitForSelector('[aria-label="Indicadores del período"]', { timeout: 20000 });
            await page.waitForFunction(() => !document.querySelector('[aria-busy="true"]'), null, { timeout: 20000 });
            await page.evaluate(() => document.fonts.ready);
            await page.waitForTimeout(1400); // cifras que cuentan y gráficas que se dibujan
            return true;
        } catch {
            if (attempt === 3) return false;
            await page.waitForTimeout(2500);
        }
    }
    return false;
}

async function setTheme(page, theme) {
    await page.evaluate((value) => localStorage.setItem('theme', value), theme === 'oscuro' ? 'dark' : 'light');
}

async function dashboard(browser) {
    for (const [role, email] of Object.entries(USERS)) {
        const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'es-HN', timezoneId: 'America/Tegucigalpa' });
        const page = await login(context, email);
        watch(page, `dashboard-${role}`);
        for (const theme of THEMES) {
            await setTheme(page, theme);
            for (const width of WIDTHS) {
                await page.setViewportSize({ width, height: width < 1024 ? 844 : 900 });
                const label = `${role}-${width}-${theme}`;
                const ok = await openDashboard(page);
                if (!ok) problems.push(`[${label}] el Dashboard no terminó de cargar`);
                const metrics = await page.evaluate(() => ({
                    height: document.documentElement.scrollHeight,
                    overflow: document.documentElement.scrollWidth - window.innerWidth,
                    kpis: document.querySelectorAll('[aria-label="Indicadores del período"] > *').length,
                    attention: document.querySelectorAll('[aria-labelledby="dashboard-attention"] li').length,
                    writeButtons: [...document.querySelectorAll('main button, main a')].filter((element) => /Importar licitación|Preparar oferta|Renovar|Registrar entrega|Gestionar cobro/.test(element.textContent ?? '')).length,
                }));
                if (metrics.overflow > 1) problems.push(`[${label}] scroll horizontal de ${metrics.overflow}px`);
                if (metrics.kpis !== 4) problems.push(`[${label}] se esperaban 4 KPI y hay ${metrics.kpis}`);
                if (role === 'lector' && metrics.writeButtons > 0) problems.push(`[${label}] el lector ve ${metrics.writeButtons} acciones de escritura`);
                report.dashboard.push({ label, ...metrics });
                await page.screenshot({ path: `${OUTPUT}/${label}.png`, fullPage: true, animations: 'disabled' });
            }
            // Cambio de período (trimestre y año) y vuelta al mes, a 1440.
            if (theme === 'claro') {
                await page.setViewportSize({ width: 1440, height: 900 });
                await openDashboard(page);
                for (const [label, value] of [['Este trimestre', 'trimestre'], ['Este año', 'anio']]) {
                    await page.getByRole('button', { name: label }).click();
                    await page.waitForURL((url) => url.search.includes(`periodo=${value}`), { timeout: 30000 }).catch(() => {});
                    await page.waitForFunction(() => !document.querySelector('[aria-busy="true"]'), null, { timeout: 20000 }).catch(() => problems.push(`[${role}] el período «${label}» no terminó`));
                    await page.waitForTimeout(1200);
                    await page.screenshot({ path: `${OUTPUT}/${role}-1440-periodo-${value}.png`, fullPage: true, animations: 'disabled' });
                }
                if (!page.url().includes('periodo=anio')) problems.push(`[${role}] la URL no refleja el período (${page.url()})`);
                // Móvil: «Ver más del tablero».
                await page.setViewportSize({ width: 390, height: 844 });
                await openDashboard(page);
                const more = page.getByRole('button', { name: 'Ver más del tablero' });
                if (await more.isVisible()) {
                    await more.click();
                    await page.waitForTimeout(800);
                    await page.screenshot({ path: `${OUTPUT}/${role}-390-ver-mas.png`, fullPage: true, animations: 'disabled' });
                }
            }
        }
        await context.close();
    }
}

async function reportes(browser) {
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'es-HN', timezoneId: 'America/Tegucigalpa' });
    const page = await login(context, USERS.admin);
    watch(page, 'reportes');
    const requests = [];
    page.on('request', (request) => request.url().includes('/reportes') && requests.push({ url: request.url().replace(BASE_URL, ''), partial: request.headers()['x-inertia-partial-data'] ?? '' }));
    page.on('requestfailed', (request) => request.url().includes('/reportes') && requests.push({ url: request.url().replace(BASE_URL, ''), failed: request.failure()?.errorText }));

    await openDashboard(page);
    // «Reportes» puede ser un subítem plegado del menú: se despliega su grupo si hace falta.
    let link = page.locator('a[href$="/reportes"]:visible').first();
    if (!(await link.count())) {
        await page.locator('.cap-shell-sidebar').getByRole('button', { name: /Reportes/ }).first().click().catch(() => {});
        await page.waitForTimeout(400);
        link = page.locator('a[href$="/reportes"]:visible').first();
    }
    const start = Date.now();
    await link.click();
    const shots = [];
    for (const at of [150, 600, 1500, 3000]) {
        const wait = at - (Date.now() - start);
        if (wait > 0) await page.waitForTimeout(wait);
        await page.screenshot({ path: `${OUTPUT}/reportes-navegacion-t${at}.png` });
        shots.push({ at, url: new URL(page.url()).pathname, skeleton: await page.locator('[aria-busy="true"]').count() });
    }
    await page.waitForSelector('text=Indicadores del período', { timeout: 30000 });
    await page.waitForFunction(() => !document.querySelector('[aria-busy="true"]'), null, { timeout: 30000 });
    report.reportes.push({ navegacion_ms: Date.now() - start, shots });
    await page.waitForTimeout(800);
    await page.screenshot({ path: `${OUTPUT}/reportes-1440.png`, fullPage: true, animations: 'disabled' });

    // Cambios rápidos de período: solo debe quedar la última petición y la cifra final debe ser la del último filtro.
    const select = page.locator('select').first();
    requests.length = 0;
    for (const value of ['quarter', 'year', 'last-year', 'month', 'ytd']) {
        await select.selectOption(value);
        await page.waitForTimeout(60);
    }
    await page.waitForFunction(() => !document.querySelector('[aria-busy="true"]'), null, { timeout: 30000 });
    await page.waitForTimeout(600);
    const dates = await page.locator('input[type="date"]').evaluateAll((inputs) => inputs.map((input) => input.value));
    report.reportes.push({ cambios_rapidos: requests, url: page.url().replace(BASE_URL, ''), fechas: dates });
    if (!page.url().includes('from=2026-01-01')) problems.push(`[reportes] tras los cambios rápidos la URL no es la del último filtro: ${page.url()}`);
    await page.screenshot({ path: `${OUTPUT}/reportes-tras-cambios.png`, fullPage: true, animations: 'disabled' });

    // Módulo: filtra la tabla (y el exporte); las tarjetas siguen el período.
    const moduleSelect = page.locator('select').nth(1);
    await moduleSelect.selectOption('cotizaciones');
    await page.waitForFunction(() => !document.querySelector('[aria-busy="true"]'), null, { timeout: 30000 });
    await page.waitForTimeout(600);
    await page.screenshot({ path: `${OUTPUT}/reportes-modulo-cotizaciones.png`, fullPage: true, animations: 'disabled' });

    // Exportes con el filtro aplicado.
    for (const format of ['pdf', 'xlsx', 'csv']) {
        const response = await page.request.get(`${BASE_URL}/reportes/${format}?from=2026-01-01&to=${dates[1] || '2026-12-31'}&module=cotizaciones`);
        report.reportes.push({ exporte: format, status: response.status(), type: response.headers()['content-type'] });
        if (response.status() !== 200) problems.push(`[reportes] exporte ${format}: ${response.status()}`);
    }

    // Fila clicable (B-28) y oscuro/móvil.
    for (const [width, theme] of [[390, 'claro'], [1440, 'oscuro'], [390, 'oscuro']]) {
        await setTheme(page, theme);
        await page.setViewportSize({ width, height: width < 1024 ? 844 : 900 });
        await page.goto(`${BASE_URL}/reportes`);
        await page.waitForSelector('text=Indicadores del período', { timeout: 30000 });
        await page.waitForFunction(() => !document.querySelector('[aria-busy="true"]'), null, { timeout: 30000 });
        await page.waitForTimeout(800);
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        if (overflow > 1) problems.push(`[reportes-${width}-${theme}] scroll horizontal de ${overflow}px`);
        await page.screenshot({ path: `${OUTPUT}/reportes-${width}-${theme}.png`, fullPage: true, animations: 'disabled' });
    }
    await context.close();
}

const browser = await chromium.launch();
try {
    if (!only.length || only.includes('dashboard')) await dashboard(browser);
    if (!only.length || only.includes('reportes')) await reportes(browser);
} finally {
    await browser.close();
}

writeFileSync(`${OUTPUT}/resultado.json`, JSON.stringify({ report, problems }, null, 2));
console.log(JSON.stringify(report, null, 2));
if (problems.length) {
    console.error(`\n${problems.length} problemas:\n${problems.join('\n')}`);
    process.exit(1);
}
console.log('\nSin problemas.');
