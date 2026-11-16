// Flujos de punta a punta y comprobaciones puntuales de la auditoría (escritorio y móvil).
//
//   node tests/visual/auditoria/flujos.mjs [acceso,barra,reportes,pipeline,importar,cotizacion,movil,raiz,teclado]
//
// Los datos que crea llevan el prefijo AUD- (se limpian con limpiar.php). Capturas en
// storage/app/qa/auditoria/flujos/ y hallazgos en storage/app/qa/auditoria/flujos.json.
import { writeFileSync } from 'node:fs';
import { chromium } from 'playwright';
import { BASE_URL, USERS, dir, login, measure, openContext, settle, watch } from './comun.mjs';

const OUT = dir('flujos');
const CAPTURE = 'tests/fixtures/honducompras/captura-cpr-sit-025-2022.png';
const wanted = process.argv.slice(2).filter((a) => !a.startsWith('--'));
const findings = [];
const events = [];
let current = '';

const note = (flow, detail, data = {}) => {
    findings.push({ flujo: flow, detalle: detail, ...data });
    console.log(`  · [${flow}] ${detail}`);
};
const shot = async (page, name, full = false) => {
    await page.screenshot({ path: `${OUT}/${name}.png`, fullPage: full, animations: 'allow', timeout: 30000 }).catch((e) => note('captura', `${name}: ${e.message.split('\n')[0]}`));
};
async function step(flow, name, action) {
    current = `${flow}/${name}`;
    try {
        await action();
        console.log(`ok  ${flow}/${name}`);
    } catch (error) {
        note(flow, `FALLO en «${name}»: ${error.message.split('\n')[0]}`);
    }
}
async function session(browser, viewport, theme, userKey, doLogin = true) {
    const context = await openContext(browser, viewport, theme);
    const page = await context.newPage();
    watch(page, events, () => current);
    if (doLogin) await login(page, USERS[userKey]);
    return { context, page };
}
const dialog = (page) => page.locator('[role="dialog"]:visible, [role="alertdialog"]:visible').last();

// ───────────────────────── Acceso: a dónde lleva iniciar sesión ─────────────────────────
async function acceso(browser) {
    const { context, page } = await session(browser, 'd1440', 'claro', 'admin', false);
    await step('acceso', 'login directo', async () => {
        await login(page, USERS.admin);
        note('acceso', `Tras iniciar sesión desde /login aterriza en ${new URL(page.url()).pathname}`);
        await shot(page, 'acceso-01-tras-login');
    });
    await step('acceso', 'logout', async () => {
        await page.goto(`${BASE_URL}/licitaciones`);
        await settle(page);
        await page.evaluate(() => window.__inertia?.router.post('/logout'));
        await page.waitForURL(/\/login/, { timeout: 15000 });
        note('acceso', `Cerrar sesión lleva a ${new URL(page.url()).pathname}`);
        await page.goBack();
        await settle(page);
        note('acceso', `Atrás tras cerrar sesión muestra ${new URL(page.url()).pathname} (¿datos en caché del navegador?: ${await page.locator('.cap-shell').count() ? 'SÍ, se ve el marco' : 'no'})`);
        await shot(page, 'acceso-02-atras-tras-logout');
    });
    await step('acceso', 'enlace profundo', async () => {
        await context.clearCookies();
        await page.goto(`${BASE_URL}/licitaciones/13`);
        await page.waitForURL(/\/login/);
        await login(page, USERS.admin);
        note('acceso', `Abrir /licitaciones/13 sin sesión e iniciar sesión lleva a ${new URL(page.url()).pathname} (el usuario pide Dashboard siempre)`);
    });
    await step('acceso', 'enlace JSON', async () => {
        await context.clearCookies();
        await page.goto(`${BASE_URL}/calendario/eventos?start=2026-11-01&end=2026-11-30`);
        await page.waitForURL(/\/login/);
        await login(page, USERS.admin);
        await settle(page);
        const body = (await page.locator('body').innerText()).slice(0, 160).replace(/\s+/g, ' ');
        note('acceso', `Sesión expirada en un endpoint JSON (/calendario/eventos) y luego iniciar sesión lleva a ${new URL(page.url()).pathname}; se ve: «${body}»`);
        await shot(page, 'acceso-03-json-tras-login');
    });
    await step('acceso', 'login movil y solicitud', async () => {
        const m = await session(browser, 'm390', 'claro', 'admin', false);
        await m.page.goto(`${BASE_URL}/login`);
        await settle(m.page);
        await shot(m.page, 'acceso-04-login-movil', true);
        m.page.setDefaultTimeout(10000);
        await m.page.goto(`${BASE_URL}/solicitar-acceso`);
        await settle(m.page);
        await shot(m.page, 'acceso-05-solicitud-movil', true);
        const submit = m.page.getByRole('button', { name: /Enviar|Solicitar/ }).first();
        await submit.click();
        await m.page.waitForTimeout(1200);
        await shot(m.page, 'acceso-06-solicitud-errores', true);
        const metrics = await measure(m.page, { touch: true });
        note('acceso', `Pantalla de solicitud móvil: tactiles<44=${metrics.tactilesChicosTotal}, overflow=${metrics.overflowX}`, { ejemplos: metrics.tactilesChicos });
        const forgot = await m.page.getByText(/olvid|recuperar|restablecer/i).count();
        note('acceso', `Enlace «¿Olvidaste tu contraseña?» en login: ${forgot ? 'existe' : 'NO existe'}`);
        await m.context.close();
    });
    await context.close();
}

// ───────────────────────── Barra lateral ─────────────────────────
async function barra(browser) {
    for (const [viewport, theme] of [['d1440', 'claro'], ['d1440', 'oscuro'], ['d1280', 'claro'], ['t1024', 'claro']]) {
        const { context, page } = await session(browser, viewport, theme, 'raiz');
        const tag = `barra-${viewport}-${theme}`;
        await step('barra', `${tag} estado inicial`, async () => {
            await page.goto(`${BASE_URL}/configuracion/usuarios`);
            await settle(page);
            const info = await page.evaluate(() => {
                const sb = document.querySelector('.cap-shell-sidebar');
                const nav = document.querySelector('.cap-shell-sidebar__nav');
                const items = [...document.querySelectorAll('.cap-shell-sidebar__item')];
                const active = document.querySelector('.cap-shell-sidebar__item[aria-current="page"]');
                const ar = active?.getBoundingClientRect();
                const nr = nav?.getBoundingClientRect();
                return {
                    collapsed: sb?.dataset.collapsed, width: sb?.getBoundingClientRect().width, height: sb?.getBoundingClientRect().height,
                    navScroll: nav ? `${nav.scrollHeight}/${nav.clientHeight}` : null, navOverflow: nav ? getComputedStyle(nav).overflowY : null,
                    items: items.length, activeVisible: ar && nr ? ar.top >= nr.top - 1 && ar.bottom <= nr.bottom + 1 : null,
                    lastItemBottom: Math.round(items.at(-1)?.getBoundingClientRect().bottom ?? 0), viewport: innerHeight,
                    hiddenBelow: items.filter((i) => i.getBoundingClientRect().top > innerHeight).length,
                };
            });
            note('barra', `${tag}: ${JSON.stringify(info)}`);
            await shot(page, `${tag}-01-inicial`);
        });
        await step('barra', `${tag} asomar`, async () => {
            await page.mouse.move(30, 300);
            await page.waitForTimeout(700);
            await shot(page, `${tag}-02-asomada`);
            const peek = await page.evaluate(() => document.querySelector('.cap-shell-sidebar')?.dataset.peek ?? 'no');
            note('barra', `${tag}: al pasar el mouse data-peek=${peek}`);
            await page.mouse.move(900, 400);
            await page.waitForTimeout(600);
        });
        await step('barra', `${tag} expandir y desplazar`, async () => {
            await page.locator('[data-cap-collapse]').click({ force: true });
            await page.waitForTimeout(700);
            await page.mouse.move(900, 400);
            await page.waitForTimeout(400);
            await shot(page, `${tag}-03-expandida`);
            await page.evaluate(() => document.querySelector('.cap-shell-sidebar__nav')?.scrollTo(0, 99999));
            await page.waitForTimeout(400);
            await shot(page, `${tag}-04-expandida-abajo`);
            // Navegar desde el menú y ver si el marco se vuelve a montar (parpadeo, scroll y estado).
            await page.evaluate(() => (document.querySelector('.cap-shell-sidebar').__audMark = 1));
            await page.locator('.cap-shell-sidebar__item', { hasText: 'Empresas' }).first().click();
            await page.waitForURL(/\/empresas/);
            await page.waitForTimeout(150);
            await shot(page, `${tag}-05-durante-navegacion`);
            await settle(page);
            const persisted = await page.evaluate(() => ({ same: document.querySelector('.cap-shell-sidebar').__audMark === 1, scroll: document.querySelector('.cap-shell-sidebar__nav')?.scrollTop }));
            note('barra', `${tag}: tras navegar, el sidebar ${persisted.same ? 'se conserva' : 'SE VUELVE A MONTAR (no hay layout persistente)'}; scrollTop=${persisted.scroll}`);
            await shot(page, `${tag}-06-tras-navegar`);
            await page.locator('[data-cap-collapse]').click({ force: true }); // deja la preferencia como estaba
            await page.waitForTimeout(500);
        });
        await context.close();
    }
    // Móvil: barra inferior, hoja «Más» y elementos por rol.
    for (const userKey of ['raiz', 'lector']) {
        for (const theme of ['claro', 'oscuro']) {
            const { context, page } = await session(browser, 'm390', theme, userKey);
            const tag = `barra-m390-${userKey}-${theme}`;
            await step('barra', `${tag} hoja Más`, async () => {
                await page.goto(`${BASE_URL}/licitaciones`);
                await settle(page);
                await shot(page, `${tag}-01-tabbar`);
                await page.getByRole('button', { name: /Más/ }).last().click();
                await page.waitForTimeout(800);
                await shot(page, `${tag}-02-mas`);
                const sheet = await page.evaluate(() => {
                    const el = document.querySelector('.cap-shell-sheet');
                    const links = [...(el?.querySelectorAll('a') ?? [])].map((a) => a.innerText.trim()).filter(Boolean);
                    const scroller = [...(el?.querySelectorAll('*') ?? [])].find((n) => n.scrollHeight > n.clientHeight + 4 && /auto|scroll/.test(getComputedStyle(n).overflowY));
                    return { links: links.length, empresas: links.some((t) => /Empresas/.test(t)), list: links.join(' | ').slice(0, 900), scroll: scroller ? `${scroller.scrollHeight}/${scroller.clientHeight}` : 'sin scroll interno' };
                });
                note('barra', `${tag}: hoja «Más» con ${sheet.links} enlaces, Empresas=${sheet.empresas}, ${sheet.scroll}`, { enlaces: sheet.list });
                await page.evaluate(() => [...document.querySelectorAll('.cap-shell-sheet *')].forEach((n) => n.scrollHeight > n.clientHeight + 4 && n.scrollTo(0, 99999)));
                await page.waitForTimeout(400);
                await shot(page, `${tag}-03-mas-abajo`);
            });
            await context.close();
        }
    }
}

// ───────────────────────── Reportes: secuencia de carga ─────────────────────────
async function reportes(browser) {
    for (const viewport of ['d1440', 'm390']) {
        const { context, page } = await session(browser, viewport, 'claro', 'admin');
        await step('reportes', `${viewport} carga`, async () => {
            await page.goto(`${BASE_URL}/calendario`);
            await settle(page);
            const t0 = Date.now();
            await page.evaluate(() => window.__inertia.router.visit('/reportes'));
            for (const ms of [150, 400, 800, 1500, 3000]) {
                await page.waitForTimeout(ms - (Date.now() - t0) > 0 ? ms - (Date.now() - t0) : 0);
                await shot(page, `reportes-${viewport}-t${ms}`);
            }
            const state = await page.evaluate(() => ({
                charts: [...document.querySelectorAll('svg')].filter((s) => s.getBoundingClientRect().width > 100).map((s) => `${Math.round(s.getBoundingClientRect().width)}x${Math.round(s.getBoundingClientRect().height)}`),
                hiddenStagger: [...document.querySelectorAll('.cap-stagger > *, [data-stagger] > *')].filter((n) => Number(getComputedStyle(n).opacity) < 0.5).length,
                zeroFills: [...document.querySelectorAll('.m-fill')].filter((n) => n.getBoundingClientRect().width < 1).length,
                fills: document.querySelectorAll('.m-fill').length,
                statValues: [...document.querySelectorAll('main [class*="font-display"]')].slice(0, 8).map((n) => n.innerText.trim()),
            }));
            note('reportes', `${viewport}: estado tras 3 s ${JSON.stringify(state)}`);
        });
        await step('reportes', `${viewport} filtro`, async () => {
            const from = page.locator('input[type="date"]').first();
            if (await from.count()) {
                await from.fill('2026-01-01');
                await page.getByRole('button', { name: /Aplicar|Filtrar/ }).first().click().catch(() => {});
                await page.waitForTimeout(250);
                await shot(page, `reportes-${viewport}-filtrando`);
                await settle(page);
                await shot(page, `reportes-${viewport}-filtrado`, true);
            } else note('reportes', `${viewport}: sin campo de fecha visible para filtrar`);
        });
        await step('reportes', `${viewport} recarga completa`, async () => {
            const t0 = Date.now();
            await page.goto(`${BASE_URL}/reportes`);
            await page.waitForTimeout(300);
            await shot(page, `reportes-${viewport}-recarga-t300`);
            await settle(page);
            note('reportes', `${viewport}: recarga completa en ${Date.now() - t0} ms`);
        });
        await context.close();
    }
}

// ───────────────────────── Pipeline: tiempos de modales ─────────────────────────
async function pipeline(browser) {
    for (const viewport of ['d1440', 'm390']) {
        const { context, page } = await session(browser, viewport, 'claro', 'admin');
        await step('pipeline', `${viewport} modales`, async () => {
            await page.goto(`${BASE_URL}/pipeline`);
            await settle(page);
            await shot(page, `pipeline-${viewport}-tablero`, true);
            await page.evaluate(() => {
                window.__long = [];
                new PerformanceObserver((list) => list.getEntries().forEach((e) => window.__long.push(Math.round(e.duration)))).observe({ type: 'longtask', buffered: false });
            });
            const timings = [];
            for (let i = 0; i < 3; i++) {
                const card = page.locator('[data-cap-kanban-card]').locator('visible=true').nth(i);
                if (!(await card.count())) break;
                const t0 = Date.now();
                await card.locator('[data-card-title]').click();
                await page.locator('[role="dialog"]:visible').first().waitFor({ timeout: 20000 });
                const tVisible = Date.now() - t0;
                await page.locator('[role="dialog"]:visible [role="tab"]').first().waitFor({ timeout: 20000 }).catch(() => {});
                await page.waitForLoadState('networkidle').catch(() => {});
                const tReady = Date.now() - t0;
                if (i === 0) await shot(page, `pipeline-${viewport}-detalle`);
                const tabs = page.locator('[role="dialog"]:visible [role="tab"]');
                const tabTimes = [];
                for (let k = 0; k < Math.min(await tabs.count(), 5); k++) {
                    const s = Date.now();
                    await tabs.nth(k).click();
                    await page.waitForLoadState('networkidle').catch(() => {});
                    tabTimes.push(Date.now() - s);
                }
                const c0 = Date.now();
                await page.keyboard.press('Escape');
                await page.locator('[role="dialog"]:visible').first().waitFor({ state: 'hidden', timeout: 10000 }).catch(() => {});
                timings.push({ abrir: tVisible, listo: tReady, pestañas: tabTimes, cerrar: Date.now() - c0 });
                await page.waitForTimeout(500);
            }
            const t1 = Date.now();
            await page.getByRole('button', { name: /Nueva oportunidad/ }).first().click().catch(async () => page.locator('.cap-shell-fab').click());
            await page.locator('[role="dialog"]:visible').first().waitFor({ timeout: 20000 });
            const tNew = Date.now() - t1;
            await shot(page, `pipeline-${viewport}-nueva`);
            await page.keyboard.press('Escape');
            const longTasks = await page.evaluate(() => window.__long);
            const requests = [];
            note('pipeline', `${viewport}: detalle ${JSON.stringify(timings)}; nueva oportunidad ${tNew} ms; tareas largas ${JSON.stringify(longTasks)}`, { requests });
        });
        await context.close();
    }
}

// ───────────────────────── Importar captura de licitación (móvil) ─────────────────────────
async function importar(browser) {
    const { context, page } = await session(browser, 'm390', 'claro', 'admin');
    await step('importar', 'abrir y subir', async () => {
        await page.goto(`${BASE_URL}/licitaciones`);
        await settle(page);
        await shot(page, 'importar-01-lista-movil', true);
        const button = page.getByRole('button', { name: 'Importar captura' });
        note('importar', `Botón «Importar captura» visible en móvil: ${await button.locator('visible=true').count() ? 'sí' : 'NO (oculto)'}`);
        if (!(await button.locator('visible=true').count())) {
            await page.locator('.cap-shell-fab').click().catch(() => {});
            await page.waitForTimeout(600);
            await shot(page, 'importar-02-fab-movil');
            await page.keyboard.press('Escape');
            await page.getByRole('button', { name: /Más acciones|Acciones/ }).first().click().catch(() => {});
            await page.waitForTimeout(500);
            await shot(page, 'importar-02b-acciones-movil');
        }
        await page.getByRole('button', { name: 'Importar captura' }).locator('visible=true').first().click();
        await dialog(page).locator('input[type="file"]').setInputFiles(CAPTURE);
        await page.waitForTimeout(500);
        await shot(page, 'importar-03-modal-movil');
        const t0 = Date.now();
        await dialog(page).getByRole('button', { name: 'Leer captura' }).click();
        await page.waitForURL(/\/licitaciones\/importar\//, { timeout: 90000 });
        await settle(page);
        note('importar', `Lectura de la captura (OCR) en ${Date.now() - t0} ms`);
        await shot(page, 'importar-04-revision-movil', true);
        const m = await measure(page, { touch: true });
        note('importar', `Revisión móvil: overflow=${m.overflowX}, táctiles<44=${m.tactilesChicosTotal}, fechas nativas=${m.fechasNativas}`, { ejemplos: m.tactilesChicos });
    });
    await step('importar', 'confirmar', async () => {
        const ref = page.getByLabel(/^Expediente/).first();
        if (await ref.count()) await ref.fill(`AUD-CPR-SIT-025-${Date.now().toString().slice(-4)}`);
        await page.getByLabel(/^Correo/).first().fill('contrataciones@sit.gob.hn').catch(() => {});
        await page.getByRole('button', { name: /Confirmar e importar|Confirmar y actualizar/ }).click();
        await page.waitForURL(/\/licitaciones\/\d+/, { timeout: 30000 });
        await settle(page);
        await shot(page, 'importar-05-ficha-movil', true);
        for (const tab of await page.getByRole('tab').all()) {
            const name = (await tab.innerText()).trim().split('\n')[0];
            await tab.click().catch(() => {});
            await page.waitForTimeout(900);
            await shot(page, `importar-06-ficha-tab-${name.replace(/[^a-z0-9]+/gi, '-').toLowerCase()}`, true);
        }
        const tabsScroll = await page.evaluate(() => {
            const list = document.querySelector('[role="tablist"]');
            return list ? `${list.scrollWidth}/${list.clientWidth}` : 'sin tablist';
        });
        note('importar', `Ficha móvil: ${new URL(page.url()).pathname}; barra de pestañas ancho ${tabsScroll}`);
    });
    await context.close();
}

// ───────────────────────── Cotización y contrato (escritorio) ─────────────────────────
async function cotizacion(browser) {
    const { context, page } = await session(browser, 'd1440', 'claro', 'admin');
    let number = '';
    await step('cotizacion', 'crear', async () => {
        await page.goto(`${BASE_URL}/cotizaciones`);
        await settle(page);
        await page.getByRole('button', { name: 'Nueva cotización' }).first().click();
        const d = page.getByRole('dialog', { name: 'Nueva cotización' });
        await d.waitFor({ timeout: 15000 });
        await shot(page, 'cotizacion-01-nueva');
        const institution = d.locator('input').nth(1);
        await institution.click();
        await institution.fill('Sec');
        await page.getByRole('option').locator('visible=true').first().click({ timeout: 10000 });
        await d.getByLabel('Tipo de compra').fill('AUD Compra menor');
        await d.getByRole('button', { name: 'Línea libre' }).click();
        await d.locator('[data-line="0"] input').nth(0).fill('AUD- Servicio de prueba de auditoría');
        await d.locator('[data-line="0"] input').nth(1).fill('2');
        await d.locator('[data-line="0"] input').nth(3).fill('150');
        await d.getByRole('button', { name: 'Crear cotización' }).click();
        await page.getByText(/Cotización COT-\d{4}-\d{4} creada/).first().waitFor({ timeout: 15000 });
        number = (await page.getByText(/Cotización COT-\d{4}-\d{4} creada/).first().innerText()).match(/COT-\d{4}-\d{4}/)[0];
        note('cotizacion', `Cotización creada ${number}`);
        await settle(page);
    });
    if (!number) return context.close();
    await step('cotizacion', 'presentar y ganar', async () => {
        for (const target of ['Emitida', 'Presentada']) {
            await page.locator(`button[aria-label="Más acciones de ${number}"]:visible`).first().click();
            await page.locator('[role="menuitem"]:visible', { hasText: 'Cambiar estado' }).first().click();
            const d = page.getByRole('dialog', { name: `Cambiar estado · ${number}` });
            await d.locator(`input[value="${target}"]`).check();
            await d.getByRole('button', { name: `Cambiar a ${target}` }).click();
            await d.waitFor({ state: 'hidden', timeout: 15000 });
        }
        await page.locator(`button[aria-label="Marcar ${number} como Ganada"]:visible`).first().click();
        const d = page.getByRole('dialog', { name: new RegExp(`Registrar como Ganada · ${number}`) });
        await d.waitFor();
        await shot(page, 'cotizacion-02-ganada');
        await d.getByRole('button', { name: 'Registrar como Ganada' }).click();
        const done = page.getByRole('dialog', { name: 'Oferta ganada' });
        await done.waitFor({ timeout: 20000 });
        await page.waitForTimeout(800);
        await shot(page, 'cotizacion-03-resultado');
        const contract = done.getByRole('link', { name: /contrato|CTR|Contrato/i }).first();
        if (await contract.count()) {
            await contract.click();
            await page.waitForURL(/post-adjudicacion\/\d+/, { timeout: 15000 });
            await settle(page);
            await shot(page, 'cotizacion-04-contrato', true);
            note('cotizacion', `Contrato generado: ${new URL(page.url()).pathname}`);
        } else note('cotizacion', 'La modal «Oferta ganada» no ofrece enlace directo al contrato');
    });
    await step('cotizacion', 'entrega', async () => {
        if (!/post-adjudicacion\/\d+/.test(page.url())) return;
        await page.getByRole('button', { name: 'Nueva entrega' }).click();
        const d = dialog(page);
        await d.getByLabel('Descripción').fill('AUD- Entrega de prueba');
        await d.getByLabel('Cantidad').fill('2');
        await d.getByLabel('Unidad').fill('Servicios');
        await d.getByLabel('Fecha comprometida').fill('2026-12-04');
        await shot(page, 'cotizacion-05-entrega');
        await d.getByRole('button', { name: /Guardar|Crear|Agregar|Registrar/ }).last().click();
        await page.waitForTimeout(1500);
        await shot(page, 'cotizacion-06-entrega-creada', true);
    });
    await step('cotizacion', 'dashboard cuadra', async () => {
        await page.goto(`${BASE_URL}/`);
        await settle(page, 2500);
        await shot(page, 'cotizacion-07-dashboard', true);
    });
    await context.close();
}

// ───────────────────────── Móvil: modales, teclado virtual, campana, empresa ─────────────────────────
async function movil(browser) {
    const { context, page } = await session(browser, 'm390', 'claro', 'admin');
    const modals = [
        ['/licitaciones', /Nueva licitación/],
        ['/cotizaciones', /Nueva cotización/],
        ['/pipeline', /Nueva oportunidad/],
        ['/calendario', /Nuevo evento/],
        ['/clientes', /Nuevo cliente/],
        ['/post-adjudicacion', /Nuevo contrato/],
        ['/instituciones', /Nueva institución/],
        ['/contactos', /Nuevo contacto/],
    ];
    for (const [path, name] of modals) {
        const slug = path.replace(/\W+/g, '');
        await step('movil', `modal ${slug}`, async () => {
            await page.goto(`${BASE_URL}${path}`);
            await settle(page);
            const visibleButton = page.getByRole('button', { name }).locator('visible=true');
            const fab = page.locator('.cap-shell-fab:visible');
            const how = (await visibleButton.count()) ? 'botón' : (await fab.count()) ? 'FAB' : 'ninguno';
            if (how === 'botón') await visibleButton.first().click();
            else if (how === 'FAB') await fab.click();
            else return note('movil', `${path}: no hay forma visible de crear en móvil`);
            const d = page.locator('[role="dialog"]:visible').last();
            await d.waitFor({ timeout: 15000 });
            await page.waitForTimeout(600);
            await shot(page, `movil-modal-${slug}`);
            // Teclado virtual: la altura útil baja ~ 330 px. ¿Se puede llegar al último campo y al botón de enviar?
            const input = d.locator('input:visible, textarea:visible').first();
            await input.focus().catch(() => {});
            await page.setViewportSize({ width: 390, height: 510 });
            await page.waitForTimeout(500);
            const reach = await page.evaluate(() => {
                const dlg = [...document.querySelectorAll('[role="dialog"]')].filter((n) => n.getBoundingClientRect().height > 0).at(-1);
                const buttons = [...dlg.querySelectorAll('button[type="submit"], footer button, [data-modal-footer] button')];
                const submit = buttons.at(-1);
                const r = dlg.getBoundingClientRect();
                const scrollers = [...dlg.querySelectorAll('*')].filter((n) => n.scrollHeight > n.clientHeight + 4 && /auto|scroll/.test(getComputedStyle(n).overflowY));
                const sr = submit?.getBoundingClientRect();
                return { dialogH: Math.round(r.height), top: Math.round(r.top), bottom: Math.round(r.bottom), vh: innerHeight, scrollers: scrollers.length, submitVisible: sr ? sr.bottom <= innerHeight && sr.top >= 0 : null, submit: submit?.innerText.trim() };
            });
            await shot(page, `movil-modal-${slug}-teclado`);
            note('movil', `${path}: crear vía ${how}; con teclado (alto 510): ${JSON.stringify(reach)}`);
            await page.setViewportSize({ width: 390, height: 844 });
            await page.keyboard.press('Escape');
            await page.waitForTimeout(400);
            const still = await page.locator('[role="dialog"]:visible').count();
            if (still) note('movil', `${path}: Escape no cerró la modal`);
        });
    }
    await step('movil', 'campana', async () => {
        await page.goto(`${BASE_URL}/`);
        await settle(page);
        await page.getByRole('button', { name: /Notificaciones|notificaciones/ }).locator('visible=true').first().click();
        await page.waitForTimeout(800);
        await shot(page, 'movil-campana');
        await page.keyboard.press('Escape');
    });
    await step('movil', 'dashboard y licitaciones a 360', async () => {
        await page.setViewportSize({ width: 360, height: 740 });
        for (const path of ['/', '/licitaciones', '/licitaciones/13', '/cotizaciones', '/pipeline', '/agente-legal', '/firmas', '/reportes', '/empresas']) {
            await page.goto(`${BASE_URL}${path}`);
            await settle(page);
            await shot(page, `movil360-${path.replace(/\W+/g, '-') || 'inicio'}`);
        }
    });
    await context.close();
}

// ───────────────────────── Raíz: empresas y cambio de empresa ─────────────────────────
async function raiz(browser) {
    for (const viewport of ['d1440', 'm390']) {
        const { context, page } = await session(browser, viewport, 'claro', 'raiz');
        await step('raiz', `${viewport} empresas`, async () => {
            await page.goto(`${BASE_URL}/`);
            await settle(page);
            const menu = await page.evaluate(() => [...document.querySelectorAll('.cap-shell-sidebar__item')].map((n) => n.innerText.trim()).filter(Boolean));
            note('raiz', `${viewport}: el menú lateral tiene ${menu.length} ítems; Empresas en posición ${menu.indexOf('Empresas') + 1}`);
            await page.goto(`${BASE_URL}/empresas`);
            await settle(page);
            await shot(page, `raiz-${viewport}-empresas`, true);
            const create = page.getByRole('button', { name: 'Nueva empresa' }).locator('visible=true');
            const fab = page.locator('.cap-shell-fab:visible');
            note('raiz', `${viewport}: «Nueva empresa» visible=${await create.count()}, FAB=${await fab.count()}`);
            if (await create.count()) await create.first().click();
            else if (await fab.count()) await fab.click();
            await page.waitForTimeout(800);
            await shot(page, `raiz-${viewport}-nueva-empresa`);
            await page.keyboard.press('Escape');
            // Selector de empresa en la cabecera: ¿ofrece «Administrar empresas»?
            const switcher = page.getByRole('button', { name: /Cambiar de empresa/ }).locator('visible=true').first();
            if (await switcher.count()) {
                await switcher.click();
                await page.waitForTimeout(500);
                await shot(page, `raiz-${viewport}-selector-empresa`);
                const items = await page.locator('[role="menuitem"]:visible').allInnerTexts();
                note('raiz', `${viewport}: selector de empresa ofrece: ${items.join(' | ')}`);
                const other = page.locator('[role="menuitem"]:visible:not([aria-current])').first();
                if (await other.count()) {
                    const t0 = Date.now();
                    await other.click();
                    await page.waitForLoadState('networkidle');
                    await page.waitForTimeout(800);
                    await shot(page, `raiz-${viewport}-tras-cambio`);
                    note('raiz', `${viewport}: cambio de empresa en ${Date.now() - t0} ms; queda en ${new URL(page.url()).pathname}`);
                    await page.getByRole('button', { name: /Cambiar de empresa/ }).locator('visible=true').first().click();
                    await page.locator('[role="menuitem"]:visible:not([aria-current])').first().click();
                    await page.waitForLoadState('networkidle');
                }
            } else note('raiz', `${viewport}: no hay selector de empresa visible`);
        });
        await context.close();
    }
}

// ───────────────────────── Teclado: foco y navegación en escritorio ─────────────────────────
async function teclado(browser) {
    const { context, page } = await session(browser, 'd1440', 'claro', 'admin');
    await step('teclado', 'tab inicial', async () => {
        await page.goto(`${BASE_URL}/licitaciones`);
        await settle(page);
        const order = [];
        for (let i = 0; i < 8; i++) {
            await page.keyboard.press('Tab');
            order.push(await page.evaluate(() => {
                const el = document.activeElement;
                const s = getComputedStyle(el);
                return `${el.tagName.toLowerCase()}«${(el.getAttribute('aria-label') || el.innerText || '').trim().slice(0, 25)}» outline=${s.outlineStyle !== 'none' || s.boxShadow !== 'none'}`;
            }));
        }
        note('teclado', `Orden de Tab en /licitaciones: ${order.join(' → ')}`);
        const skip = await page.locator('a[href="#main"], a:has-text("Saltar al contenido")').count();
        note('teclado', `Enlace «Saltar al contenido»: ${skip ? 'sí' : 'NO'}`);
        await shot(page, 'teclado-foco');
    });
    await step('teclado', 'modal foco', async () => {
        await page.getByRole('button', { name: 'Nueva licitación' }).first().click();
        await page.waitForTimeout(600);
        const focused = await page.evaluate(() => document.activeElement?.closest('[role="dialog"]') !== null);
        for (let i = 0; i < 40; i++) await page.keyboard.press('Tab');
        const inside = await page.evaluate(() => document.activeElement?.closest('[role="dialog"]') !== null);
        await page.keyboard.press('Escape');
        await page.waitForTimeout(400);
        const back = await page.evaluate(() => `${document.activeElement?.tagName}«${(document.activeElement?.innerText || '').trim().slice(0, 30)}»`);
        note('teclado', `Modal Nueva licitación: foco inicial dentro=${focused}, foco atrapado tras 40 Tab=${inside}, foco al cerrar=${back}`);
    });
    await context.close();
}

const FLOWS = { acceso, barra, reportes, pipeline, importar, cotizacion, movil, raiz, teclado };
const browser = await chromium.launch();
for (const [name, flow] of Object.entries(FLOWS)) {
    if (wanted.length && !wanted.includes(name)) continue;
    console.log(`\n=== ${name} ===`);
    await flow(browser).catch((error) => note(name, `FLUJO ABORTADO: ${error.message.split('\n')[0]}`));
}
await browser.close();
const file = `${dir()}/flujos-${wanted.join('_') || 'todos'}.json`;
writeFileSync(file, JSON.stringify({ hallazgos: findings, eventos: events }, null, 2));
console.log(`\n${findings.length} hallazgos, ${events.length} eventos de consola/HTTP → ${file}`);
