// Revisión visual y recorrido real del Pipeline (kanban) con Playwright.
//
//   node tests/visual/pipeline.mjs                 (todo)
//   QA_ONLY=flujo node tests/visual/pipeline.mjs   (solo el recorrido)
//   QA_ONLY=capturas node tests/visual/pipeline.mjs
//
// Recorrido (administrador, 1440 claro): crear dos oportunidades (botón y «+» de columna) → arrastrar
// con el ratón Prospecto → Oportunidad → intento inválido (vuelve) → mover con teclado (Espacio,
// flechas, Espacio) → «Mover a…» del menú → soltar en Oferta Presentada y cancelar (vuelve) →
// Oferta Presentada con participantes → No Adjudicado eligiendo al ganador → la otra a Oferta
// Presentada y Adjudicado (cliente y contrato) → detalle con sus pestañas → enlace /pipeline/{id} →
// lector: solo lectura y arrastre rechazado. Luego capturas en 1440 y 390, claro y oscuro, como
// administrador y lector. Al final revierte y elimina lo creado.
// Capturas en storage/app/qa/capturas/pipeline/. Sale con código 1 si algo falla, hay errores de
// consola o scroll horizontal.
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { chromium } from 'playwright';
import { horizontalOverflow, openContext, settle, watchConsole } from './capturas.mjs';

const BASE_URL = process.env.QA_URL ?? 'http://localhost:8000';
const PASSWORD = process.env.QA_PASSWORD ?? 'QaB2g#2026';
const ADMIN = 'qa.admin@crm-b2g.test';
const VIEWER = 'qa.lector@crm-b2g.test';
const OUTPUT = 'storage/app/qa/capturas/pipeline';
const ONLY = process.env.QA_ONLY ?? '';
const RUN = new Date().toISOString().slice(11, 19).replace(/:/g, '');
const TITLE_A = `QA Kanban A ${RUN}`;
const TITLE_B = `QA Kanban B ${RUN}`;

mkdirSync(OUTPUT, { recursive: true });

/** Borra restos de corridas anteriores que quedaron a medias (oportunidades «QA Kanban …»). */
function cleanupLeftovers() {
    execFileSync('php', ['-r', `
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$db = Illuminate\\Support\\Facades\\DB::class;
$ids = $db::table('opportunities')->where('title', 'like', 'QA Kanban %')->pluck('id');
if ($ids->isNotEmpty()) {
    $db::table('contracts')->whereIn('opportunity_id', $ids)->delete();
    $db::table('quote_participants')->whereIn('opportunity_id', $ids)->delete();
    $db::table('opportunities')->whereIn('id', $ids)->delete();
}
echo $ids->count();
`]);
}

const problems = [];
const visible = (page, selector) => page.locator(selector).locator('visible=true').first();
const topDialog = (page) => page.getByRole('dialog').last();
const cardOf = (page, title) => page.locator('[data-cap-kanban-card]', { has: page.locator('[data-card-title]', { hasText: title }) }).first();
const listOf = (page, stage) => page.locator(`[data-cap-kanban-column="${stage}"] [data-cap-kanban-list]`);
const columnOfCard = (page, title) => cardOf(page, title).evaluate((element) => element.closest('[data-cap-kanban-column]')?.dataset.capKanbanColumn);

/** Inicia sesión; el servidor de desarrollo es compartido y puede tardar: se reintenta. */
async function login(page, email) {
    for (let attempt = 1; attempt <= 4; attempt++) {
        try {
            await page.goto(`${BASE_URL}/login`, { timeout: 60000 });
            await page.fill('#login-email', email);
            await page.fill('#login-password', PASSWORD);
            await Promise.all([page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 45000 }), page.click('button[type="submit"]')]);
            return;
        } catch (error) {
            if (attempt === 4) throw error;
            await page.waitForTimeout(3000);
        }
    }
}

async function step(page, label, action, attempts = 1) {
    for (let attempt = 1; attempt <= attempts; attempt++) {
        try {
            await action();
            console.log(`ok  ${label}`);
            return true;
        } catch (error) {
            console.log(`  intento ${attempt} de ${label}: ${error.message.split('\n')[0]}`);
            if (attempt === attempts) {
                problems.push(`[${label}] ${error.message.split('\n')[0]}`);
                console.log(`ERR ${label}`);
                await page.screenshot({ path: `${OUTPUT}/error-${label.replace(/[^a-z0-9-]+/gi, '_')}.png`, fullPage: true }).catch(() => {});
                return false;
            }
            await page.keyboard.press('Escape').catch(() => {});
            await page.waitForTimeout(1500);
        }
    }
    return false;
}

async function shot(page, name) {
    await page.waitForTimeout(500);
    const modalOpen = (await page.getByRole('dialog').count()) > 0;
    await page.screenshot({ path: `${OUTPUT}/${name}.png`, fullPage: !modalOpen, animations: 'disabled' });
    const overflow = await horizontalOverflow(page);
    if (overflow > 1) problems.push(`[${name}] scroll horizontal de ${overflow}px`);
}

/** Abre el tablero esperando a que monte (Vite puede recargar la página mientras otros editan). */
async function openBoard(page, path = '/pipeline') {
    for (let attempt = 1; attempt <= 4; attempt++) {
        try {
            await page.goto(`${BASE_URL}${path}`);
            await page.waitForSelector('.cap-shell', { timeout: 15000 });
            await page.locator('[data-cap-kanban]').or(page.getByText('Aún no hay oportunidades')).first().waitFor({ timeout: 15000 });
            await settle(page);
            return;
        } catch (error) {
            if (attempt === 4) throw error;
            await page.waitForTimeout(2000);
        }
    }
}

async function waitColumn(page, title, stage, timeout = 10000) {
    const started = Date.now();
    let current = null;
    while (Date.now() - started < timeout) {
        current = await columnOfCard(page, title).catch(() => null);
        if (current === stage) return;
        await page.waitForTimeout(250);
    }
    throw new Error(`«${title}» está en ${current}, se esperaba ${stage}`);
}

/** Arrastre real con el ratón: desplaza el tablero hasta la columna destino y suelta a la altura de la tarjeta. */
async function dragCard(page, card, stage) {
    await page.evaluate((id) => { const b = document.querySelector('[data-cap-kanban]'); const c = b.querySelector(`[data-cap-kanban-column="${id}"]`); if (c.offsetLeft + c.offsetWidth > b.scrollLeft + b.clientWidth) b.scrollLeft = c.offsetLeft + c.offsetWidth - b.clientWidth + 8; if (c.offsetLeft < b.scrollLeft) b.scrollLeft = c.offsetLeft; }, stage);
    await card.scrollIntoViewIfNeeded();
    const from = await card.boundingBox();
    const list = await page.locator(`[data-cap-kanban-column="${stage}"] [data-cap-kanban-list]`).boundingBox();
    const sx = from.x + from.width / 2, sy = from.y + 20;
    const tx = list.x + list.width / 2, ty = Math.min(Math.max(sy, list.y + 30), list.y + list.height - 30);
    await page.mouse.move(sx, sy);
    await page.mouse.down();
    await page.mouse.move(sx + 10, sy, { steps: 3 });
    await page.mouse.move(tx, ty, { steps: 12 });
    await page.mouse.up();
}

async function cardMenu(page, title, item) {
    await cardOf(page, title).locator('button.cap-kanban-card__menu').click();
    await visible(page, `[role="menuitem"]:has-text("${item}")`).click();
}

async function chooseInstitution(dialog, page) {
    const input = dialog.getByPlaceholder('Buscar institución…');
    await input.click();
    await input.fill('Sec');
    const option = visible(page, '[role="option"]');
    await option.waitFor({ timeout: 10000 });
    await option.click();
}

async function createOpportunity(page, { title, amount, fromColumn = null }) {
    if (fromColumn) await page.locator(`[data-cap-kanban-column="${fromColumn}"] button[aria-label="Agregar en ${fromColumn === 'oportunidad' ? 'Oportunidad' : 'Prospecto'}"]`).click();
    else await visible(page, 'button:has-text("Nueva oportunidad")').click();
    const dialog = topDialog(page);
    await dialog.getByText('Nueva oportunidad').first().waitFor();
    await dialog.getByPlaceholder('Suministro de equipo médico').fill(title);
    await chooseInstitution(dialog, page);
    await dialog.locator('input[inputmode="decimal"]').first().fill(String(amount));
    const closeDate = dialog.getByPlaceholder('dd/mm/aaaa').first(); // DateInput: texto dd/mm/aaaa con calendario propio
    await closeDate.fill('20/12/2026');
    await closeDate.press('Tab');
    await dialog.getByPlaceholder('Visitar a la unidad de compras').fill('Reunión con compras');
    await dialog.getByRole('button', { name: 'Crear oportunidad' }).click();
    await dialog.waitFor({ state: 'detached', timeout: 15000 });
    await cardOf(page, title).waitFor({ timeout: 15000 });
}

/** Modal «Presentar oferta»: agrega nuestra oferta (y un rival) y mueve la tarjeta. */
async function presentOffer(page, rival = null, screenshot = '') {
    const dialog = topDialog(page);
    await dialog.getByText('Presentar oferta').first().waitFor();
    await dialog.getByRole('button', { name: 'Agregar participante' }).first().click();
    const rows = dialog.locator('[data-participant]');
    await rows.first().getByRole('textbox').first().fill('CAP Honduras');
    if (rival) {
        await dialog.getByRole('button', { name: 'Agregar participante' }).first().click();
        const second = rows.nth(1);
        await second.getByRole('textbox').first().fill(rival.name);
        await second.locator('input[inputmode="decimal"]').first().fill(String(rival.amount));
    }
    if (screenshot) await shot(page, screenshot);
    await dialog.getByRole('button', { name: /Guardar y presentar|Mover a Oferta Presentada/ }).click();
    await dialog.waitFor({ state: 'detached', timeout: 15000 });
}

async function flow(browser) {
    const context = await openContext(browser, 'escritorio', 'claro');
    const page = await context.newPage();
    const consoleProblems = watchConsole(page, 'flujo');
    page.on('response', (response) => response.status() === 404 && problems.push('[flujo] 404 ' + response.url()));
    await login(page, ADMIN);
    await openBoard(page);

    await step(page, 'crear oportunidad A con el botón', () => createOpportunity(page, { title: TITLE_A, amount: 150000 }));
    await step(page, 'crear oportunidad B con el + de Oportunidad', async () => {
        await createOpportunity(page, { title: TITLE_B, amount: 90000, fromColumn: 'oportunidad' });
        await waitColumn(page, TITLE_B, 'oportunidad');
    });
    await shot(page, 'flujo-01-creadas');

    await step(page, 'arrastrar A con el ratón: Prospecto → Oportunidad', async () => {
        await waitColumn(page, TITLE_A, 'prospecto');
        await dragCard(page, cardOf(page, TITLE_A), 'oportunidad');
        await waitColumn(page, TITLE_A, 'oportunidad');
        await page.getByText('Oportunidad movida a Oportunidad').first().waitFor({ timeout: 10000 });
    });

    await step(page, 'arrastre inválido Oportunidad → Adjudicado vuelve a su columna', async () => {
        await dragCard(page, cardOf(page, TITLE_A), 'adjudicado');
        await page.getByText('Movimiento no permitido').first().waitFor({ timeout: 10000 });
        await page.waitForTimeout(800);
        await waitColumn(page, TITLE_A, 'oportunidad', 2000);
        await shot(page, 'flujo-02-movimiento-invalido');
    });

    await step(page, 'mover B con el teclado: Oportunidad → Prospecto → Oportunidad', async () => {
        const card = cardOf(page, TITLE_B);
        await card.focus();
        await page.keyboard.press('Space');
        await page.keyboard.press('ArrowLeft');
        await page.keyboard.press('Space');
        await waitColumn(page, TITLE_B, 'prospecto');
        await page.waitForTimeout(800);
        await cardOf(page, TITLE_B).focus();
        await page.keyboard.press('Space');
        await page.keyboard.press('ArrowRight');
        await page.keyboard.press('Space');
        await waitColumn(page, TITLE_B, 'oportunidad');
    });

    await step(page, '«Mover a…» desde el menú de la tarjeta', async () => {
        await cardMenu(page, TITLE_B, 'Mover a Prospecto');
        await waitColumn(page, TITLE_B, 'prospecto');
        await page.waitForTimeout(600);
        await cardMenu(page, TITLE_B, 'Mover a Oportunidad');
        await waitColumn(page, TITLE_B, 'oportunidad');
    });

    await step(page, 'soltar en Oferta Presentada y cancelar: la tarjeta vuelve', async () => {
        await dragCard(page, cardOf(page, TITLE_B), 'oferta_presentada');
        const dialog = topDialog(page);
        await dialog.getByText('Presentar oferta').first().waitFor();
        await shot(page, 'flujo-03-presentar-vacio');
        await dialog.getByRole('button', { name: 'Cancelar' }).click();
        await dialog.waitFor({ state: 'detached' });
        await waitColumn(page, TITLE_B, 'oportunidad', 2000);
    });

    await step(page, 'A a Oferta Presentada con participantes', async () => {
        await dragCard(page, cardOf(page, TITLE_A), 'oferta_presentada');
        await presentOffer(page, { name: 'Distribuidora Rival', amount: 140000 }, 'flujo-04-presentar-participantes');
        await waitColumn(page, TITLE_A, 'oferta_presentada');
        await cardOf(page, TITLE_A).getByText('Participantes · 2').waitFor({ timeout: 10000 });
    });

    await step(page, 'A a No Adjudicado eligiendo al ganador', async () => {
        await dragCard(page, cardOf(page, TITLE_A), 'no_adjudicado');
        const dialog = topDialog(page);
        await dialog.getByText('Registrar como Perdida').first().waitFor();
        const option = await dialog.locator('select option', { hasText: 'Distribuidora Rival' }).first().getAttribute('value');
        await dialog.locator('select').first().selectOption(option);
        await dialog.getByPlaceholder('Precio, requisito técnico, documentación…').fill('Precio más bajo del rival');
        await shot(page, 'flujo-05-perdida');
        await dialog.getByRole('button', { name: 'Registrar como Perdida' }).click();
        await dialog.waitFor({ state: 'detached', timeout: 15000 });
        await waitColumn(page, TITLE_A, 'no_adjudicado');
        await cardOf(page, TITLE_A).locator('[data-card-winner]').getByText('Distribuidora Rival').waitFor({ timeout: 10000 });
        await cardOf(page, TITLE_A).locator('[data-card-winner]').getByText('L 140,000.00').waitFor();
    });

    await step(page, 'B a Oferta Presentada (menú) y Adjudicado (arrastre)', async () => {
        await cardMenu(page, TITLE_B, 'Mover a Oferta Presentada');
        await presentOffer(page);
        await waitColumn(page, TITLE_B, 'oferta_presentada');
        await dragCard(page, cardOf(page, TITLE_B), 'adjudicado');
        const dialog = topDialog(page);
        await dialog.getByRole('button', { name: 'Registrar como Ganada' }).waitFor();
        await shot(page, 'flujo-06-ganada');
        await dialog.getByRole('button', { name: 'Registrar como Ganada' }).click();
        await dialog.getByText('Oferta ganada').first().waitFor({ timeout: 15000 });
        await dialog.getByText(/Contrato:/).waitFor();
        await shot(page, 'flujo-07-ganada-resultado');
        await dialog.getByRole('button', { name: 'Listo' }).click();
        await dialog.waitFor({ state: 'detached', timeout: 15000 });
        await waitColumn(page, TITLE_B, 'adjudicado');
        await page.waitForTimeout(800); // el tablero se recarga tras el resultado
    });

    await step(page, 'cancelar la modal Ganada desde una oferta presentada deja la tarjeta', async () => {
        // A ya tiene resultado: se comprueba que salir de No Adjudicado moviendo se rechaza.
        await dragCard(page, cardOf(page, TITLE_A), 'oferta_presentada');
        await page.getByText('Movimiento no permitido').first().waitFor({ timeout: 10000 });
        await waitColumn(page, TITLE_A, 'no_adjudicado', 3000);
    });

    await shot(page, 'flujo-08-tablero-final');

    let idA = null;
    await step(page, 'detalle de A: pestañas y Quién ganó', async () => {
        await cardOf(page, TITLE_A).locator('[data-card-title]').click();
        const dialog = topDialog(page);
        await dialog.getByRole('tab', { name: /Quién ganó/ }).waitFor({ timeout: 15000 });
        idA = (await page.evaluate(() => window.location.pathname)).split('/').pop();
        await shot(page, 'flujo-09-detalle-datos');
        await dialog.getByRole('tab', { name: /Participantes/ }).click();
        await dialog.getByText('Posición de nuestra oferta').waitFor();
        await shot(page, 'flujo-10-detalle-participantes');
        await dialog.getByRole('tab', { name: /Quién ganó/ }).click();
        await dialog.locator('[data-tab-winner]').getByText('Distribuidora Rival').waitFor();
        await dialog.locator('[data-tab-winner]').getByText('+ L 10,000.00', { exact: false }).waitFor();
        await shot(page, 'flujo-11-detalle-quien-gano');
        await dialog.getByRole('tab', { name: /Documentos/ }).click();
        await dialog.getByText('Documentos de la oportunidad').waitFor();
        await shot(page, 'flujo-12-detalle-documentos');
        await dialog.getByRole('tab', { name: /Historial/ }).click();
        await dialog.getByText('Registrada como no adjudicada').waitFor();
        await shot(page, 'flujo-13-detalle-historial');
        await dialog.getByRole('button', { name: 'Cerrar' }).last().click();
        await dialog.waitFor({ state: 'detached' });
    });

    await step(page, 'detalle de B: Resultado con cliente y contrato', async () => {
        await cardOf(page, TITLE_B).locator('[data-card-title]').click();
        const dialog = topDialog(page);
        await dialog.getByRole('tab', { name: /Resultado/ }).click();
        await dialog.getByText(/Contrato:/).waitFor();
        await dialog.getByText(/CTR-/).first().waitFor();
        await shot(page, 'flujo-14-detalle-resultado');
        await page.keyboard.press('Escape');
    });

    await step(page, 'el enlace /pipeline/{id} abre el detalle', async () => {
        await openBoard(page, `/pipeline/${idA}`);
        await topDialog(page).getByText(TITLE_A).first().waitFor({ timeout: 15000 });
        await page.keyboard.press('Escape');
        await page.waitForTimeout(500);
        if ((await page.evaluate(() => window.location.pathname)) !== '/pipeline') throw new Error('La URL no volvió a /pipeline al cerrar el detalle');
    });

    problems.push(...consoleProblems);
    await context.close();

    // Lector: ve, no mueve.
    const viewerContext = await openContext(browser, 'escritorio', 'claro');
    const viewer = await viewerContext.newPage();
    const viewerConsole = watchConsole(viewer, 'lector');
    await login(viewer, VIEWER);
    await openBoard(viewer);
    await step(viewer, 'lector: sin botón de crear y arrastre rechazado', async () => {
        if (await viewer.locator('button:has-text("Nueva oportunidad")').count()) throw new Error('El lector ve «Nueva oportunidad»');
        await cardOf(viewer, TITLE_B).waitFor();
        const prospect = viewer.locator('[data-cap-kanban-column="prospecto"] [data-cap-kanban-card]').first();
        if (await prospect.count()) {
            const title = await prospect.locator('[data-card-title]').innerText();
            await dragCard(viewer, prospect, 'oportunidad');
            // El aviso, no la etiqueta oculta del rol «Solo lectura» del menú de usuario.
            await viewer.locator('.cap-toast', { hasText: 'Solo lectura' }).first().waitFor({ timeout: 10000 });
            await waitColumn(viewer, title, 'prospecto', 2000);
        }
        await cardOf(viewer, TITLE_B).locator('button.cap-kanban-card__menu').click();
        if (await viewer.locator('[role="menuitem"]:has-text("Revertir")').count()) throw new Error('El lector ve «Revertir resultado»');
        await shot(viewer, 'flujo-15-lector-menu');
        await viewer.keyboard.press('Escape');
    });
    problems.push(...viewerConsole);
    await viewerContext.close();
}

async function captures(browser) {
    for (const viewport of ['escritorio', 'movil']) {
        for (const theme of ['claro', 'oscuro']) {
            for (const [role, email] of [['admin', ADMIN], ['lector', VIEWER]]) {
                const tag = `${viewport === 'escritorio' ? '1440' : '390'}-${theme}-${role}`;
                const context = await openContext(browser, viewport, theme);
                const page = await context.newPage();
                const consoleProblems = watchConsole(page, tag);
                await login(page, email);
                await openBoard(page);
                await shot(page, `${tag}-tablero`);

                if (viewport === 'movil') {
                    await step(page, `${tag} selector de columna`, async () => {
                        await visible(page, '[data-cap-kanban-picker] button:has-text("No Adjudicado")').click();
                        await page.waitForTimeout(400);
                        await shot(page, `${tag}-columna-no-adjudicado`);
                    }, 2);
                }

                const card = page.locator('[data-cap-kanban-card]').locator('visible=true').first();
                await step(page, `${tag} menú de tarjeta`, async () => {
                    await card.locator('button.cap-kanban-card__menu').click();
                    await visible(page, '[role="menu"]').waitFor();
                    await shot(page, `${tag}-menu`);
                    await page.keyboard.press('Escape');
                }, 2);

                await step(page, `${tag} detalle`, async () => {
                    await card.locator('[data-card-title]').click();
                    const dialog = topDialog(page);
                    await dialog.getByRole('tab', { name: /Historial/ }).waitFor({ timeout: 15000 });
                    await shot(page, `${tag}-detalle`);
                    await dialog.getByRole('tab', { name: /Participantes/ }).click();
                    await page.waitForTimeout(800);
                    await shot(page, `${tag}-detalle-participantes`);
                    await page.keyboard.press('Escape');
                    await dialog.waitFor({ state: 'detached' });
                }, 2);

                if (role === 'admin') {
                    await step(page, `${tag} formulario`, async () => {
                        await visible(page, 'button:has-text("Nueva oportunidad"), button[aria-label="Nueva oportunidad"]').click();
                        await topDialog(page).getByRole('button', { name: 'Crear oportunidad' }).waitFor();
                        await shot(page, `${tag}-formulario`);
                        await topDialog(page).getByRole('button', { name: 'Cancelar' }).click();
                    }, 2);

                    const presented = page.locator('[data-cap-kanban-column="oferta_presentada"] [data-cap-kanban-card]').first();
                    if (await presented.count()) {
                        await step(page, `${tag} modales de resultado (cancelando)`, async () => {
                            if (viewport === 'movil') await visible(page, '[data-cap-kanban-picker] button:has-text("Oferta Presentada")').click();
                            const title = await presented.locator('[data-card-title]').innerText();
                            await presented.locator('button.cap-kanban-card__menu').click();
                            await visible(page, '[role="menuitem"]:has-text("Registrar como Perdida")').click();
                            await topDialog(page).getByRole('button', { name: 'Registrar como Perdida' }).waitFor();
                            await page.waitForTimeout(600);
                            await shot(page, `${tag}-perdida`);
                            await topDialog(page).getByRole('button', { name: 'Cancelar' }).click();
                            await presented.locator('button.cap-kanban-card__menu').click();
                            await visible(page, '[role="menuitem"]:has-text("Registrar como Ganada")').click();
                            await topDialog(page).getByRole('button', { name: 'Registrar como Ganada' }).waitFor();
                            await shot(page, `${tag}-ganada`);
                            await topDialog(page).getByRole('button', { name: 'Cancelar' }).click();
                            await presented.locator('button.cap-kanban-card__menu').click();
                            await visible(page, '[role="menuitem"]:has-text("Participantes")').click();
                            await topDialog(page).getByText('Oferentes del proceso').waitFor();
                            await page.waitForTimeout(800);
                            await shot(page, `${tag}-participantes`);
                            await page.keyboard.press('Escape');
                            await waitColumn(page, title, 'oferta_presentada', 3000);
                        }, 2);
                    }
                }

                problems.push(...consoleProblems);
                await context.close();
            }
        }
    }
}

async function cleanup(browser) {
    const context = await openContext(browser, 'escritorio', 'claro');
    const page = await context.newPage();
    await login(page, ADMIN);
    await openBoard(page);
    for (const title of [TITLE_B, TITLE_A]) {
        if (!(await cardOf(page, title).count())) continue;
        await step(page, `revertir y eliminar ${title}`, async () => {
            await cardMenu(page, title, 'Revertir resultado');
            const dialog = topDialog(page);
            await dialog.locator('textarea').fill('Limpieza de la prueba visual');
            await shot(page, `flujo-16-revertir-${title === TITLE_A ? 'a' : 'b'}`);
            await dialog.getByRole('button', { name: 'Revertir resultado' }).click();
            await dialog.waitFor({ state: 'detached', timeout: 15000 });
            await waitColumn(page, title, 'oferta_presentada');
            await cardMenu(page, title, 'Eliminar');
            await page.getByRole('button', { name: 'Eliminar oportunidad' }).click();
            await cardOf(page, title).waitFor({ state: 'detached', timeout: 15000 });
        });
    }
    await context.close();
}

const browser = await chromium.launch();
try {
    cleanupLeftovers();
    if (!ONLY || ONLY === 'flujo') await flow(browser);
    if (!ONLY || ONLY === 'capturas') await captures(browser);
    if (!ONLY || ONLY === 'flujo') await cleanup(browser);
} finally {
    await browser.close();
}

if (problems.length) {
    console.log(`\n${problems.length} problema(s):\n${problems.join('\n')}`);
    process.exit(1);
}
console.log('\nPipeline sin problemas.');
