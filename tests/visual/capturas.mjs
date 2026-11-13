// Capturas de revisión visual del marco y de las pantallas base, en escritorio y móvil, claro y oscuro.
//
//   node tests/visual/capturas.mjs                     todas las pantallas de la lista
//   node tests/visual/capturas.mjs dashboard clientes  solo las indicadas
//
// Variables: QA_URL (http://localhost:8000), QA_EMAIL, QA_PASSWORD.
// Las imágenes quedan en storage/app/qa/capturas/<pantalla>-<vista>-<tema>.png (ignorado por git).
// Termina con código 1 si hay errores de consola o scroll horizontal.
import { mkdirSync } from 'node:fs';
import { chromium } from 'playwright';

const BASE_URL = process.env.QA_URL ?? 'http://localhost:8000';
const EMAIL = process.env.QA_EMAIL ?? 'qa.admin@crm-b2g.test';
const PASSWORD = process.env.QA_PASSWORD ?? 'QaB2g#2026';
const OUTPUT = 'storage/app/qa/capturas';

const VIEWPORTS = {
    escritorio: { width: 1440, height: 900 },
    movil: { width: 390, height: 844 },
};
const THEMES = ['claro', 'oscuro'];

export const SCREENS = {
    acceso: { path: '/login', guest: true },
    dashboard: { path: '/' },
    // Mientras la ruta no exista, la página se abre en el cliente sobre el dashboard.
    componentes: { path: '/dev/componentes', component: 'Dev/Components', fallback: '/' },
    clientes: { path: '/clientes' },
    licitaciones: { path: '/licitaciones' },
    contactos: { path: '/contactos' },
    cotizaciones: { path: '/cotizaciones' },
    pipeline: { path: '/pipeline' },
};

export async function login(page) {
    await page.goto(`${BASE_URL}/login`);
    await page.fill('#login-email', EMAIL);
    await page.fill('#login-password', PASSWORD);
    await Promise.all([page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 15000 }), page.click('button[type="submit"]')]);
}

export async function openContext(browser, viewportName, theme) {
    const context = await browser.newContext({ viewport: VIEWPORTS[viewportName], deviceScaleFactor: 1, isMobile: viewportName === 'movil', hasTouch: viewportName === 'movil', locale: 'es-HN', timezoneId: 'America/Tegucigalpa' });
    await context.addInitScript((value) => {
        try {
            localStorage.setItem('theme', value);
        } catch {
            // Sin almacenamiento el tema sale de la preferencia del sistema.
        }
    }, theme === 'oscuro' ? 'dark' : 'light');
    return context;
}

/** Registra errores de consola y de página; devuelve la lista que se va llenando. */
export function watchConsole(page, label) {
    const problems = [];
    page.on('console', (message) => {
        if (message.type() === 'error') problems.push(`[${label}] consola: ${message.text()}`);
    });
    page.on('pageerror', (error) => problems.push(`[${label}] excepción: ${error.message}`));
    return problems;
}

export async function settle(page) {
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.evaluate(() => document.fonts.ready);
    await page.waitForTimeout(1200);
}

export async function horizontalOverflow(page) {
    return page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
}

/** Abre la pantalla; si su ruta aún no existe y tiene `component`, la monta con el router de Inertia. */
export async function openScreen(page, screen, label, problems, seen = []) {
    const response = await page.goto(`${BASE_URL}${screen.path}`);
    const status = response?.status() ?? 200;
    if (status === 404 && screen.component) {
        await page.goto(`${BASE_URL}${screen.fallback}`);
        await page.waitForSelector('.cap-shell');
        seen.length = 0;
        await page.evaluate(({ component, url }) => window.__inertia.router.push({ component, url }), { component: screen.component, url: screen.path });
        return;
    }
    if (status >= 400) problems.push(`[${label}] HTTP ${status}`);
}

async function main() {
    const wanted = process.argv.slice(2);
    const names = wanted.length ? wanted : Object.keys(SCREENS);
    mkdirSync(OUTPUT, { recursive: true });
    const browser = await chromium.launch();
    const problems = [];

    for (const viewportName of Object.keys(VIEWPORTS)) {
        for (const theme of THEMES) {
            const guest = await openContext(browser, viewportName, theme);
            const session = await openContext(browser, viewportName, theme);
            let loggedIn = false;
            for (const name of names) {
                const screen = SCREENS[name];
                if (!screen) {
                    problems.push(`Pantalla desconocida: ${name}`);
                    continue;
                }
                const label = `${name}-${viewportName}-${theme}`;
                const page = await (screen.guest ? guest : session).newPage();
                const seen = watchConsole(page, label);
                try {
                    if (!screen.guest && !loggedIn) {
                        await login(page);
                        loggedIn = true;
                    }
                    await openScreen(page, screen, label, problems, seen);
                    await settle(page);
                    const overflow = await horizontalOverflow(page);
                    if (overflow > 1) problems.push(`[${label}] scroll horizontal de ${overflow}px`);
                    await page.screenshot({ path: `${OUTPUT}/${label}.png`, fullPage: true, animations: 'disabled' });
                    console.log(`ok  ${label}`);
                } catch (error) {
                    problems.push(`[${label}] ${error.message.split('\n')[0]}`);
                }
                // Una pantalla abierta sin ruta deja el 404 de su primer intento: no cuenta.
                problems.push(...seen.filter((message) => !(screen.component && message.includes("404"))));
                await page.close();
            }
            await guest.close();
            await session.close();
        }
    }

    await browser.close();
    if (problems.length) {
        console.log(`\n${problems.length} problema(s):`);
        problems.forEach((problem) => console.log(`  ${problem}`));
        process.exit(1);
    }
    console.log('\nSin errores de consola ni scroll horizontal.');
}

if (process.argv[1]?.replace(/\\/g, '/').endsWith('tests/visual/capturas.mjs')) await main();
