// Validación del sistema de movimiento en todas las pantallas internas.
//
//   node tests/visual/movimiento.mjs              todo (pantallas × 1440/390 × claro/oscuro, reducido y fotogramas clave)
//   node tests/visual/movimiento.mjs fotogramas   solo los fotogramas a mitad de transición y las mediciones
//   node tests/visual/movimiento.mjs pantallas    solo el recorrido de pantallas
//
// Capturas en storage/app/qa/capturas/movimiento/. Termina con código 1 si hay errores de consola,
// scroll horizontal o una transición de página por encima del umbral.
import { mkdirSync, writeFileSync } from 'node:fs';
import { chromium } from 'playwright';

const BASE = process.env.QA_URL ?? 'http://localhost:8000';
const EMAIL = process.env.QA_EMAIL ?? 'qa.admin@crm-b2g.test';
const PASSWORD = process.env.QA_PASSWORD ?? 'QaB2g#2026';
const OUT = 'storage/app/qa/capturas/movimiento';
const STATE = `${OUT}/.sesion.json`;
const mode = process.argv[2] ?? 'todo';
mkdirSync(`${OUT}/pantallas`, { recursive: true });
mkdirSync(`${OUT}/fotogramas`, { recursive: true });

export const SCREENS = {
    dashboard: '/',
    calendario: '/calendario',
    reportes: '/reportes',
    'agente-legal': '/agente-legal',
    pipeline: '/pipeline',
    cotizaciones: '/cotizaciones',
    clientes: '/clientes',
    inventario: '/inventario',
    licitaciones: '/licitaciones',
    'ia-licitaciones': '/ia-licitaciones',
    historial: '/historial',
    directorio: '/directorio',
    'post-adjudicacion': '/post-adjudicacion',
    competencia: '/inteligencia-competitiva',
    'crm-politico': '/relaciones-institucionales',
    'presupuesto-revolvente': '/presupuesto-revolvente',
    'flujo-caja': '/flujo-caja',
    'alertas-poa': '/alertas-poa',
    consorcios: '/consorcios',
    'matriz-documental': '/matriz-cumplimiento',
    calculadora: '/calculadora-oferta',
    'generador-documentos': '/generador-documentos',
    firmas: '/firmas',
    instituciones: '/instituciones',
    contactos: '/contactos',
    cobros: '/cobros',
    kpis: '/kpis',
    empresas: '/empresas',
    usuarios: '/configuracion/usuarios',
    mcp: '/configuracion/mcp',
    auditoria: '/configuracion/auditoria',
    diagnostico: '/configuracion/diagnostico',
    recordatorios: '/notificaciones',
    plantillas: '/notificaciones/plantillas',
    configuracion: '/configuracion',
    sops: '/sops',
    capacitacion: '/capacitacion',
    'mi-cuenta': '/mi-cuenta',
};

const VIEWPORTS = { escritorio: { width: 1440, height: 900 }, movil: { width: 390, height: 844 } };
const problems = [];
const report = { transiciones: [], tareasLargas: [] };

function watch(page, label) {
    page.on('console', (message) => {
        if (message.text().startsWith('animaciones sin terminar')) console.log(message.text());
        if (message.type() === 'error') problems.push(`[${label}] consola: ${message.text()}`);
    });
    page.on('pageerror', (error) => problems.push(`[${label}] excepción: ${error.message}`));
}

async function login(browser) {
    const context = await browser.newContext({ viewport: VIEWPORTS.escritorio });
    const page = await context.newPage();
    for (let attempt = 0; attempt < 3; attempt += 1) {
        try {
            await page.goto(`${BASE}/login`);
            await page.waitForSelector('#login-email', { state: 'visible', timeout: 20000 });
            await page.fill('#login-email', EMAIL);
            await page.fill('#login-password', PASSWORD);
            await Promise.all([page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 20000 }), page.click('button[type="submit"]')]);
            break;
        } catch (error) {
            if (attempt === 2) throw error;
        }
    }
    await context.storageState({ path: STATE });
    await context.close();
}

async function open(browser, { viewport = 'escritorio', theme = 'claro', reduced = false } = {}) {
    const context = await browser.newContext({
        viewport: VIEWPORTS[viewport],
        isMobile: viewport === 'movil',
        hasTouch: viewport === 'movil',
        storageState: STATE,
        reducedMotion: reduced ? 'reduce' : 'no-preference',
        locale: 'es-HN',
        timezoneId: 'America/Tegucigalpa',
    });
    await context.addInitScript((dark) => {
        try {
            localStorage.setItem('theme', dark ? 'dark' : 'light');
            sessionStorage.setItem('m-bell-rung', '1');
        } catch {
            // sin almacenamiento
        }
    }, theme === 'oscuro');
    const page = await context.newPage();
    return { context, page };
}

/** Abre una ruta y espera a que la página esté estable (reintenta si Vite recarga). */
async function visit(page, path) {
    for (let attempt = 0; attempt < 3; attempt += 1) {
        try {
            await page.goto(`${BASE}${path}`, { waitUntil: 'domcontentloaded' });
            await page.waitForSelector('.cap-shell__content > .m-page', { timeout: 15000 });
            await page.waitForLoadState('networkidle', { timeout: 8000 }).catch(() => {});
            await page.evaluate(() => document.fonts.ready);
            await page.waitForTimeout(1300);
            return true;
        } catch (error) {
            if (attempt === 2) {
                problems.push(`[${path}] no cargó: ${error.message.split('\n')[0]}`);
                return false;
            }
            await page.waitForTimeout(1500);
        }
    }
    return false;
}

const overflow = (page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);

async function screens(browser) {
    const combos = [
        { viewport: 'escritorio', theme: 'claro' },
        { viewport: 'escritorio', theme: 'oscuro' },
        { viewport: 'movil', theme: 'claro' },
        { viewport: 'movil', theme: 'oscuro' },
        { viewport: 'escritorio', theme: 'claro', reduced: true },
        { viewport: 'movil', theme: 'oscuro', reduced: true },
    ];
    for (const combo of combos) {
        const label = `${combo.viewport}-${combo.theme}${combo.reduced ? '-reducido' : ''}`;
        const { context, page } = await open(browser, combo);
        watch(page, label);
        for (const [name, path] of Object.entries(SCREENS)) {
            if (!(await visit(page, path))) continue;
            const extra = await overflow(page);
            if (extra > 1) problems.push(`[${label}] ${name}: scroll horizontal de ${extra}px`);
            // Nada queda invisible al terminar las animaciones.
            const hidden = await page.evaluate(() => [...document.querySelectorAll('.m-page > *')].filter((element) => Number(getComputedStyle(element).opacity) < 0.99).length);
            if (hidden) problems.push(`[${label}] ${name}: ${hidden} bloques siguen transparentes`);
            await page.screenshot({ path: `${OUT}/pantallas/${name}-${label}.png` });
        }
        await context.close();
        console.log(`✓ ${label}`);
    }
}

/** Fotograma a los `ms` indicados tras la acción. */
async function frame(page, name, action, times = [60, 160]) {
    const start = Date.now();
    try {
        await action();
    } catch (error) {
        problems.push(`[fotograma ${name}] ${error.message.split(String.fromCharCode(10))[0]}`);
        return;
    }
    for (const ms of times) {
        const wait = ms - (Date.now() - start);
        if (wait > 0) await page.waitForTimeout(wait);
        await page.screenshot({ path: `${OUT}/fotogramas/${name}-${ms}ms.png` });
    }
}

async function measureNavigation(page, href) {
    await page.evaluate(() => {
        window.__long = [];
        window.__po?.disconnect();
        window.__po = new PerformanceObserver((list) => list.getEntries().forEach((entry) => window.__long.push(Math.round(entry.duration))));
        window.__po.observe({ type: 'longtask', buffered: false });
    });
    const result = await page.evaluate(async (target) => {
        const cap = (promise, ms = 4000) => Promise.race([promise, new Promise((resolve) => setTimeout(() => resolve('tiempo'), ms))]);
        const link = document.querySelector(`.cap-shell-sidebar a[href="${target}"]`);
        if (!link) return { error: `sin enlace ${target}` };
        const clickedAt = performance.now();
        let firstFeedback = null;
        const leaving = new MutationObserver(() => {
            if (firstFeedback === null && document.querySelector('[data-m-leaving], .m-nav-ghost')) firstFeedback = performance.now() - clickedAt;
        });
        leaving.observe(document.body, { attributes: true, childList: true, subtree: true });
        link.click();
        const oldPage = document.querySelector('.cap-shell__content > .m-page');
        // Espera a que el contenido nuevo esté montado.
        await cap(new Promise((resolve) => {
            const check = () => {
                const current = document.querySelector('.cap-shell__content > .m-page');
                if (current && current !== oldPage) resolve();
                else requestAnimationFrame(check);
            };
            check();
        }));
        const mountedAt = performance.now() - clickedAt;
        const content = document.querySelector('.cap-shell__content');
        const animations = document.getAnimations().filter((animation) => content.contains(animation.effect?.target) && animation.effect.getComputedTiming().iterations !== Infinity);
        // La entrada «principal»: el primer bloque de la página.
        const first = content.querySelector('.m-page > *');
        const firstAnimations = first ? first.getAnimations() : [];
        const t0 = performance.now();
        await cap(Promise.all(firstAnimations.map((animation) => animation.finished.catch(() => {}))));
        const firstDone = performance.now() - t0;
        const pending = await cap(Promise.all(animations.map((animation) => animation.finished.catch(() => {}))));
        if (pending === 'tiempo') console.log('animaciones sin terminar', animations.filter((a) => a.playState !== 'finished').map((a) => `${a.animationName ?? 'waapi'}@${a.effect.target?.className}:${a.playState}`).slice(0, 5).join(' | '));
        const allDone = performance.now() - t0;
        leaving.disconnect();
        return { mountedAt: Math.round(mountedAt), feedback: firstFeedback === null ? null : Math.round(firstFeedback), firstBlock: Math.round(firstDone), allBlocks: Math.round(allDone) };
    }, href);
    await page.waitForTimeout(300);
    result.longTasks = (await page.evaluate(() => window.__long)) ?? [];
    return result;
}

async function keyFrames(browser) {
    const { context, page } = await open(browser, { viewport: 'escritorio', theme: 'claro' });
    watch(page, 'fotogramas');
    await visit(page, '/');

    // 1. Cambio de página (salida, indicador deslizándose, entrada) y mediciones.
    await frame(page, 'pagina-dashboard-a-licitaciones', () => page.click('.cap-shell-sidebar a[href="/licitaciones"]'), [40, 120, 220, 360]);
    await page.waitForTimeout(1200);
    for (const href of ['/cotizaciones', '/pipeline', '/calendario', '/reportes', '/clientes', '/']) {
        await page.waitForTimeout(900);
        const measure = await measureNavigation(page, href);
        report.transiciones.push({ destino: href, ...measure });
        if (measure.error) problems.push(`[medición] ${measure.error}`);
        else if (measure.firstBlock > 360) problems.push(`[medición] ${href}: el primer bloque tardó ${measure.firstBlock} ms en entrar`);
        if (measure.longTasks.length) report.tareasLargas.push({ destino: href, tareas: measure.longTasks });
    }

    // 2. Modal (BaseModal): abrir una desde Cotizaciones o Licitaciones.
    await visit(page, '/licitaciones');
    const create = page.locator('.m-page .cap-button--primary').first();
    if (await create.count()) {
        await frame(page, 'modal-entrada', () => create.click(), [60, 140, 260]);
        await page.waitForTimeout(500);
        await frame(page, 'modal-salida', () => page.keyboard.press('Escape'), [50, 120]);
    }

    // 3. Pestañas: ficha de una licitación.
    await visit(page, '/licitaciones');
    const firstTender = page.locator('.m-page table tbody tr').first();
    if (await firstTender.count()) {
        await firstTender.click();
        await page.waitForTimeout(1500);
        const tabs = page.locator('[role="tab"]');
        if ((await tabs.count()) > 1) await frame(page, 'pestanas', () => tabs.nth(1).click(), [60, 160, 320]);
    }

    // 4. Kanban: arrastre de una tarjeta.
    await visit(page, '/pipeline');
    const card = page.locator('.cap-kanban-card').first();
    if (await card.count()) {
        await card.hover();
        await page.waitForTimeout(300);
        await page.screenshot({ path: `${OUT}/fotogramas/kanban-hover.png` });
        const box = await card.boundingBox();
        await page.mouse.move(box.x + box.width / 2, box.y + 20);
        await page.mouse.down();
        await page.mouse.move(box.x + box.width / 2 + 120, box.y + 60, { steps: 8 });
        await page.waitForTimeout(150);
        await page.screenshot({ path: `${OUT}/fotogramas/kanban-arrastrando.png` });
        await page.mouse.move(box.x + box.width / 2, box.y + 20, { steps: 6 });
        await page.mouse.up();
    }

    // 5. Calendario: cambio de mes.
    await visit(page, '/calendario');
    const next = page.locator('button[aria-label="Mes siguiente"]');
    if (await next.count()) await frame(page, 'calendario-mes-siguiente', () => next.click(), [60, 180, 420]);

    // 6. Toast y celebración (importa los módulos desde Vite).
    await page.waitForTimeout(800);
    await frame(
        page,
        'celebracion-y-toast',
        () =>
            page.evaluate(async () => {
                const motion = await import('http://localhost:5173/resources/js/lib/motion/confetti.js');
                motion.celebrate();
                const toasts = await import('http://localhost:5173/resources/design/vue/composables/useToasts.js');
                toasts.useToasts().show({ status: 'ok', title: 'Oferta registrada como Ganada' });
            }),
        [120, 320, 700],
    );

    // 7. Menú de usuario y preferencia «Animaciones».
    await visit(page, '/');
    await frame(page, 'menu-usuario', () => page.click('.app-header-avatar'), [40, 120, 260]);
    const motionItem = page.locator('[role="menuitem"]', { hasText: 'Animaciones' });
    await motionItem.click();
    const reduced = await page.evaluate(() => document.documentElement.dataset.motion);
    if (reduced !== 'reduced') problems.push(`[preferencia] tras elegir «reducidas» data-motion=${reduced}`);
    await page.keyboard.press('Escape');
    await frame(page, 'reducido-cambio-pagina', () => page.click('.cap-shell-sidebar a[href="/licitaciones"]'), [60, 200]);
    await page.waitForTimeout(1000);
    const ghosts = await page.locator('.m-nav-ghost').count();
    if (ghosts) problems.push('[preferencia] con «reducidas» aparece el indicador deslizante');
    await page.click('.app-header-avatar');
    await page.locator('[role="menuitem"]', { hasText: 'Animaciones' }).click();
    const full = await page.evaluate(() => document.documentElement.dataset.motion);
    if (full !== 'full') problems.push(`[preferencia] al volver a «completas» data-motion=${full}`);

    // 8. Móvil: modal que sube y hoja «Más».
    await context.close();
    const mobile = await open(browser, { viewport: 'movil', theme: 'oscuro' });
    watch(mobile.page, 'fotogramas-movil');
    await visit(mobile.page, '/licitaciones');
    const mobileCreate = mobile.page.locator('.cap-shell-fab:visible, .m-page .cap-button--primary:visible').first();
    if (await mobileCreate.count()) await frame(mobile.page, 'movil-modal-sube', () => mobileCreate.click(), [80, 200, 400]);
    await mobile.page.keyboard.press('Escape');
    await mobile.page.waitForTimeout(400);
    const more = mobile.page.locator('.cap-shell-tabbar__tab--more');
    if (await more.count()) await frame(mobile.page, 'movil-hoja-mas', () => more.click(), [80, 200, 400]);
    await mobile.context.close();
}

const browser = await chromium.launch();
await login(browser);
if (mode === 'todo' || mode === 'fotogramas') await keyFrames(browser);
if (mode === 'todo' || mode === 'pantallas') await screens(browser);
await browser.close();

writeFileSync(`${OUT}/mediciones.json`, JSON.stringify(report, null, 2));
console.log('Transiciones (ms):');
console.table(report.transiciones.map(({ destino, mountedAt, feedback, firstBlock, allBlocks, longTasks }) => ({ destino, respuesta: mountedAt, reaccion: feedback, primerBloque: firstBlock, todo: allBlocks, tareasLargas: longTasks.join(',') || '—' })));
if (problems.length) {
    console.log(`\n${problems.length} problemas:\n${problems.join('\n')}`);
    process.exitCode = 1;
} else {
    console.log('\nSin problemas.');
}
