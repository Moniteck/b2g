// Validación visual del marco persistente (sidebar, cabecera, barra móvil, hoja «Más», paleta ⌘K,
// selector de fechas, páginas de error y modo soporte).
//
//   node tests/visual/marco.mjs [humo|barra|movil|paleta|fechas|errores|soporte|recorrido] [--usuario=admin] [--vistas=d1440,m390] [--temas=claro,oscuro]
//
// Guarda capturas en storage/app/qa/capturas/marco/ y un resumen JSON con los hallazgos (montajes del
// marco por navegación, errores de consola, ítem activo visible, desbordes).
import { mkdirSync, writeFileSync } from 'node:fs';
import { chromium } from 'playwright';
import { BASE_URL, USERS, VIEWPORTS, login, openContext } from './auditoria/comun.mjs';

const OUT = 'storage/app/qa/capturas/marco';
mkdirSync(OUT, { recursive: true });

const args = Object.fromEntries(process.argv.slice(2).filter((arg) => arg.startsWith('--')).map((arg) => arg.slice(2).split('=')));
const modes = process.argv.slice(2).filter((arg) => !arg.startsWith('--'));
const MODES = modes.length ? modes : ['humo'];
const USER = USERS[args.usuario ?? 'admin'];
const VIEWS = (args.vistas ?? 'd1440').split(',');
const THEMES = (args.temas ?? 'claro').split(',');

const RECORRIDO = ['/', '/calendario', '/reportes', '/kpis', '/pipeline', '/cotizaciones', '/clientes', '/inventario', '/licitaciones', '/historial', '/post-adjudicacion', '/cobros', '/directorio', '/instituciones', '/contactos', '/matriz-cumplimiento', '/inteligencia-competitiva', '/relaciones-institucionales', '/alertas-poa', '/consorcios', '/sops', '/capacitacion', '/administracion', '/empresas', '/configuracion/usuarios', '/configuracion', '/configuracion/auditoria', '/configuracion/mcp', '/mi-cuenta', '/firmas', '/notificaciones'];

const findings = [];
const note = (entry) => {
    findings.push(entry);
    console.log(JSON.stringify(entry));
};

async function stable(page) {
    await page.waitForLoadState('networkidle', { timeout: 30000 }).catch(() => {});
    await page.waitForSelector('.cap-shell, .auth-shell, main', { timeout: 30000 }).catch(() => {});
    await page.waitForTimeout(450);
}

/** Navega con Inertia (clic real en un enlace del marco si existe; si no, router.visit). */
async function inertiaGo(page, path) {
    const link = page.locator(`.cap-shell-sidebar a[href="${path}"], .cap-shell-tabbar a[href="${path}"]`).first();
    if (await link.isVisible().catch(() => false)) await link.click();
    else await page.evaluate((target) => window.__inertia.router.visit(target), path);
    await page.waitForFunction((target) => window.location.pathname === target, path, { timeout: 30000 }).catch(() => {});
    await stable(page);
}

async function sidebarState(page) {
    return page.evaluate(() => {
        const nav = document.querySelector('.cap-shell-sidebar__nav');
        const sidebar = document.querySelector('.cap-shell-sidebar');
        if (!nav || !sidebar || !sidebar.getClientRects().length) return null;
        const box = nav.getBoundingClientRect();
        const bar = sidebar.getBoundingClientRect();
        const active = [...nav.querySelectorAll('[aria-current="page"], [data-active="true"]')].filter((el) => el.getClientRects().length).at(-1);
        const rect = active?.getBoundingClientRect();
        const icons = [...nav.querySelectorAll('.cap-shell-sidebar__icon')].filter((el) => el.getClientRects().length);
        const outside = icons.filter((icon) => {
            const r = icon.getBoundingClientRect();
            return r.left < bar.left - 1 || r.right > bar.right + 1;
        }).length;
        return {
            collapsed: sidebar.dataset.collapsed,
            items: nav.querySelectorAll(':scope > .cap-shell-sidebar__item, :scope > details > summary').length,
            activeVisible: rect ? rect.top >= box.top - 1 && rect.bottom <= box.bottom + 1 : null,
            iconsOutside: outside,
            navOverflow: getComputedStyle(nav).overflowY,
        };
    });
}

async function shot(page, name) {
    await page.screenshot({ path: `${OUT}/${name}.png` });
}

async function withPage(browser, view, theme, run) {
    const context = await openContext(browser, view, theme);
    const page = await context.newPage();
    const errors = [];
    page.on('console', (message) => message.type() === 'error' && errors.push(message.text().slice(0, 300)));
    page.on('pageerror', (error) => errors.push(String(error).slice(0, 300)));
    try {
        await login(page, USER);
        await stable(page);
        await run(page);
    } catch (error) {
        note({ view, theme, error: String(error).slice(0, 400) });
        await shot(page, `error-${view}-${theme}`).catch(() => {});
    } finally {
        if (errors.length) note({ view, theme, consoleErrors: [...new Set(errors)].slice(0, 10) });
        await context.close();
    }
}

const runners = {
    async humo(page, view, theme) {
        const mountsAt = async () => page.evaluate(() => window.__crmShellMounts ?? 0);
        const start = await mountsAt();
        for (const path of ['/licitaciones', '/cotizaciones', '/configuracion/usuarios', '/']) {
            await inertiaGo(page, path);
        }
        note({ mode: 'humo', view, theme, mountsAntes: start, mountsDespues: await mountsAt(), sidebar: await sidebarState(page) });
        await shot(page, `humo-${view}-${theme}`);
    },

    async recorrido(page, view, theme) {
        const start = await page.evaluate(() => window.__crmShellMounts ?? 0);
        for (const path of RECORRIDO) {
            await inertiaGo(page, path);
            const state = await sidebarState(page);
            const scroll = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
            const title = await page.evaluate(() => document.querySelector(window.innerWidth < 1024 ? '.cap-shell-topbar__title' : '.cap-shell-header__title')?.textContent?.trim());
            if ((state && (!state.activeVisible || state.iconsOutside)) || scroll) note({ mode: 'recorrido', view, theme, path, state, scroll });
            await shot(page, `recorrido-${view}-${theme}${path.replaceAll('/', '_') || '_inicio'}`);
            if (!title) note({ mode: 'recorrido', view, theme, path, sinTitulo: true });
        }
        note({ mode: 'recorrido', view, theme, mounts: (await page.evaluate(() => window.__crmShellMounts ?? 0)) - start });
    },

    async barra(page, view, theme) {
        for (const path of ['/configuracion/auditoria', '/empresas', '/cobros', '/']) {
            await inertiaGo(page, path);
            note({ mode: 'barra', view, theme, path, sidebar: await sidebarState(page) });
            await shot(page, `barra-${view}-${theme}${path.replaceAll('/', '_')}`);
        }
        const sidebar = page.locator('.cap-shell-sidebar');
        if (await sidebar.isVisible()) {
            if (VIEWPORTS[view].touch) {
                await page.locator('.cap-shell-sidebar summary').first().tap().catch(() => {});
            } else {
                await sidebar.hover();
            }
            await page.waitForTimeout(500);
            await shot(page, `barra-${view}-${theme}-asomada`);
            await page.locator('[data-cap-collapse]').click();
            await page.mouse.move(900, 500);
            await page.waitForTimeout(500);
            await shot(page, `barra-${view}-${theme}-alterna`);
            await page.locator('[data-cap-collapse]').click();
        }
    },

    async movil(page, view, theme) {
        await inertiaGo(page, '/licitaciones');
        await shot(page, `movil-${view}-${theme}-licitaciones`);
        await page.locator('.cap-shell-tabbar__tab--more').click();
        await page.waitForTimeout(500);
        await shot(page, `movil-${view}-${theme}-mas`);
        await page.locator('.app-more-sheet summary', { hasText: 'Post-adjudicación' }).click();
        await page.waitForTimeout(300);
        await shot(page, `movil-${view}-${theme}-mas-abierto`);
        await page.locator('.app-more-sheet__child', { hasText: 'Cobros' }).click();
        await stable(page);
        await shot(page, `movil-${view}-${theme}-cobros`);
        const title = await page.locator('.cap-shell-topbar__title').textContent();
        note({ mode: 'movil', view, theme, tituloTopbar: title?.trim() });
    },

    async paleta(page, view, theme) {
        await page.keyboard.press('Control+k');
        await page.waitForSelector('.app-palette__input', { timeout: 5000 });
        await shot(page, `paleta-${view}-${theme}-vacia`);
        const started = Date.now();
        await page.fill('.app-palette__input', 'cot');
        await page.waitForResponse((response) => response.url().includes('/buscar?'), { timeout: 10000 }).catch(() => {});
        await page.waitForTimeout(300);
        note({ mode: 'paleta', view, theme, ms: Date.now() - started, opciones: await page.locator('.app-palette__option').count() });
        await shot(page, `paleta-${view}-${theme}-resultados`);
        await page.keyboard.press('ArrowDown');
        await page.keyboard.press('Enter');
        await stable(page);
        note({ mode: 'paleta', view, theme, destino: new URL(page.url()).pathname + new URL(page.url()).search });
    },

    async errores(page, view, theme) {
        for (const status of [403, 404, 419, 429, 500, 503]) {
            await page.goto(`${BASE_URL}/errores/${status}`);
            await stable(page);
            await shot(page, `error-${status}-${view}-${theme}`);
        }
        await page.goto(`${BASE_URL}/no-existe-esta-ruta`);
        await stable(page);
        await shot(page, `error-404-ruta-${view}-${theme}`);
        const response = await page.goto(`${BASE_URL}/cotizaciones/1`);
        note({ mode: 'errores', view, theme, jsonAbierta: new URL(page.url()).pathname + new URL(page.url()).search, status: response?.status() });
    },

    async soporte(page, view, theme) {
        const chip = page.locator('.app-header-chip');
        await chip.click();
        await page.waitForTimeout(300);
        await shot(page, `soporte-${view}-${theme}-selector`);
        const other = page.locator('[role="menuitem"][title^="Entrar en modo soporte"]').first();
        if (await other.count()) {
            await other.click();
            await stable(page);
            await shot(page, `soporte-${view}-${theme}-franja`);
            note({ mode: 'soporte', view, theme, franja: await page.locator('.app-support-banner').isVisible() });
            await page.locator('.app-support-banner__action').click();
            await stable(page);
            note({ mode: 'soporte', view, theme, franjaTrasSalir: await page.locator('.app-support-banner').isVisible() });
        } else {
            note({ mode: 'soporte', view, theme, sinOtrasEmpresas: true });
        }
        await chip.click();
        await page.locator('[role="menuitem"]', { hasText: 'Nueva empresa' }).click();
        await page.waitForTimeout(500);
        await shot(page, `soporte-${view}-${theme}-asistente`);
    },

    async fechas(page, view, theme) {
        await page.goto(`${BASE_URL}/dev/componentes`);
        await stable(page);
        const field = page.locator('.app-date-input').first();
        await field.scrollIntoViewIfNeeded();
        await shot(page, `fechas-${view}-${theme}-campo`);
        await field.locator('button').first().click();
        await page.waitForTimeout(400);
        await shot(page, `fechas-${view}-${theme}-calendario`);
        await page.keyboard.press('Escape');
        const input = field.locator('input').first();
        await input.fill('');
        await input.type('05032026');
        await input.press('Tab');
        note({ mode: 'fechas', view, theme, escrito: await input.inputValue() });
    },
};

const browser = await chromium.launch();
for (const mode of MODES) {
    for (const view of VIEWS) {
        for (const theme of THEMES) {
            await withPage(browser, view, theme, (page) => runners[mode](page, view, theme));
        }
    }
}
await browser.close();
writeFileSync(`${OUT}/resumen-${MODES.join('_')}-${args.usuario ?? 'admin'}.json`, JSON.stringify(findings, null, 2));
