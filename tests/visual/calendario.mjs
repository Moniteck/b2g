// Revisión visual y de flujo del calendario, la campana y «Mis recordatorios».
//
//   node tests/visual/calendario.mjs
//
// Recorre, a 1440×900 y 390×844 en claro y oscuro: rejilla, agenda, detalle de evento, crear evento,
// reglas de recordatorio, panel de la campana, «Mis recordatorios» y plantillas. En escritorio claro
// ejecuta el flujo real: crear un evento manual, editarlo, marcarlo atendido y eliminarlo. Con el
// lector comprueba que no aparecen acciones de escritura.
// Capturas en storage/app/qa/capturas/calendario/. Termina con código 1 ante errores de consola,
// scroll horizontal o pasos fallidos.
import { mkdirSync } from 'node:fs';
import { chromium } from 'playwright';
import { horizontalOverflow, openContext, settle, watchConsole } from './capturas.mjs';

const BASE_URL = process.env.QA_URL ?? 'http://localhost:8000';
const PASSWORD = process.env.QA_PASSWORD ?? 'QaB2g#2026';
const OUTPUT = 'storage/app/qa/capturas/calendario';
const ONLY = process.env.QA_ONLY ?? '';
const VIEWPORTS = ['escritorio', 'movil'].filter((name) => !ONLY || ONLY.startsWith(name));
const THEMES = ['claro', 'oscuro'].filter((name) => !ONLY || ONLY.endsWith(name));

const problems = [];

async function login(page, email) {
    await page.goto(`${BASE_URL}/login`);
    await page.fill('#login-email', email);
    await page.fill('#login-password', PASSWORD);
    await Promise.all([page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 20000 }), page.click('button[type="submit"]')]);
}

/** Reintenta un paso: la recarga de Vite de otros agentes puede dejar la página en blanco un momento. */
async function step(label, action, attempts = 3) {
    for (let attempt = 1; attempt <= attempts; attempt++) {
        try {
            return await action();
        } catch (error) {
            if (attempt === attempts) {
                console.log(`FALLA ${label}: ${error.message.split('\n')[0]}`);
                problems.push(`[${label}] ${error.message.split('\n')[0]}`);
                return null;
            }
            await new Promise((resolve) => setTimeout(resolve, 1500));
        }
    }
    return null;
}

async function shot(page, name) {
    await page.waitForTimeout(400);
    const overflow = await horizontalOverflow(page);
    if (overflow > 1) problems.push(`[${name}] scroll horizontal de ${overflow}px`);
    await page.screenshot({ path: `${OUTPUT}/${name}.png`, fullPage: false, animations: 'disabled' });
    console.log(`ok  ${name}`);
}

async function openCalendar(page) {
    await page.goto(`${BASE_URL}/calendario?mes=2026-11`);
    await page.waitForSelector('[aria-label="Vista del calendario"]', { timeout: 20000 });
    await settle(page);
    await page.waitForSelector('[aria-busy="true"]', { state: 'detached', timeout: 15000 }).catch(() => {});
}

async function setView(page, label) {
    await page.locator('[aria-label="Vista del calendario"] button', { hasText: label }).click();
    await page.waitForTimeout(500);
}

async function closeModal(page) {
    await page.keyboard.press('Escape');
    await page.waitForSelector('[role="dialog"][aria-modal="true"]:not(.cap-shell-sheet)', { state: 'detached', timeout: 5000 }).catch(() => {});
}

async function openFirstEvent(page, view) {
    if (view === 'month') {
        const chip = page.locator('.md\\:flex button[title]').first();
        if (await chip.isVisible().catch(() => false)) await chip.click();
        else {
            await setView(page, 'Agenda');
            await page.locator('[data-day] li button').first().click();
        }
    } else {
        await page.locator('[data-day] li button').first().click();
    }
    await page.waitForSelector('[role="dialog"][aria-modal="true"]:not(.cap-shell-sheet)');
    await page.waitForSelector('[role="dialog"][aria-modal="true"]:not(.cap-shell-sheet) h3', { timeout: 10000 });
}

async function realFlow(page, label) {
    const title = `Reunión QA ${Date.now().toString().slice(-5)}`;
    await page.locator('button:visible', { hasText: 'Nuevo evento' }).or(page.locator('button.cap-shell-fab:visible')).first().click();
    await page.waitForSelector('[role="dialog"][aria-modal="true"]:not(.cap-shell-sheet)');
    const dialog = page.locator('[role="dialog"][aria-modal="true"]:not(.cap-shell-sheet)');
    await dialog.getByLabel('Título').fill(title);
    await dialog.getByLabel('Inicio').fill('2026-11-18T09:30');
    await dialog.getByLabel('Descripción').fill('Revisión de documentos de la oferta.');
    await shot(page, `${label}-crear-lleno`);
    await dialog.getByRole('button', { name: 'Crear evento' }).click();
    await page.waitForSelector('[role="dialog"][aria-modal="true"]:not(.cap-shell-sheet)', { state: 'detached', timeout: 15000 });
    await settle(page);

    await setView(page, 'Agenda');
    await page.locator('[data-day] li button', { hasText: title }).first().click();
    await page.waitForSelector('[role="dialog"][aria-modal="true"]:not(.cap-shell-sheet) h3');
    await shot(page, `${label}-manual-detalle`);

    await page.locator('[role="dialog"][aria-modal="true"]:not(.cap-shell-sheet)').getByRole('button', { name: 'Editar' }).click();
    await page.waitForSelector('[role="dialog"][aria-modal="true"]:not(.cap-shell-sheet) >> text=Editar evento');
    await page.locator('[role="dialog"][aria-modal="true"]:not(.cap-shell-sheet)').getByLabel('Título').fill(`${title} (editado)`);
    await page.locator('[role="dialog"][aria-modal="true"]:not(.cap-shell-sheet)').getByLabel('Personalizar los avisos de este evento').check({ force: true }).catch(async () => {
        await page.locator('[role="dialog"][aria-modal="true"]:not(.cap-shell-sheet)').getByText('Personalizar los avisos de este evento').click();
    });
    await shot(page, `${label}-editar`);
    await page.locator('[role="dialog"][aria-modal="true"]:not(.cap-shell-sheet)').getByRole('button', { name: 'Guardar cambios' }).click();
    await page.waitForSelector('[role="dialog"][aria-modal="true"]:not(.cap-shell-sheet)', { state: 'detached', timeout: 15000 });
    await settle(page);

    await page.locator('[data-day] li button', { hasText: `${title} (editado)` }).first().click();
    await page.waitForSelector('[role="dialog"][aria-modal="true"]:not(.cap-shell-sheet) h3');
    await page.locator('[role="dialog"][aria-modal="true"]:not(.cap-shell-sheet)').getByRole('button', { name: 'Marcar como atendido' }).click();
    await page.waitForSelector('[role="dialog"][aria-modal="true"]:not(.cap-shell-sheet) >> text=Atendido por', { timeout: 15000 });
    await shot(page, `${label}-atendido`);

    await page.locator('[role="dialog"][aria-modal="true"]:not(.cap-shell-sheet)').getByRole('button', { name: 'Eliminar' }).click();
    await page.waitForSelector('text=¿Eliminar el evento');
    await shot(page, `${label}-confirmar-eliminar`);
    await page.getByRole('button', { name: 'Eliminar evento' }).click();
    await page.waitForSelector('[role="dialog"][aria-modal="true"]:not(.cap-shell-sheet)', { state: 'detached', timeout: 15000 });
    await settle(page);
    if (await page.locator('[data-day] li button', { hasText: title }).count()) problems.push(`[${label}] el evento eliminado sigue en la agenda`);
}

async function main() {
    mkdirSync(OUTPUT, { recursive: true });
    const browser = await chromium.launch();

    for (const viewport of VIEWPORTS) {
        for (const theme of THEMES) {
            const label = `${viewport}-${theme}`;
            const context = await openContext(browser, viewport, theme);
            const page = await context.newPage();
            page.setDefaultTimeout(10000);
            const seen = watchConsole(page, label);

            await step(`${label} acceso`, () => login(page, 'qa.admin@crm-b2g.test'));
            await step(`${label} calendario`, async () => {
                await openCalendar(page);
                await setView(page, 'Mes');
                await shot(page, `${label}-mes`);
            });
            if (viewport === 'movil') {
                await step(`${label} día`, async () => {
                    await page.locator('button[aria-label="Ver el 2026-11-26"]').click();
                    await page.waitForTimeout(400);
                    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
                    await shot(page, `${label}-mes-dia`);
                    await page.evaluate(() => window.scrollTo(0, 0));
                });
            }
            await step(`${label} agenda`, async () => {
                await setView(page, 'Agenda');
                await shot(page, `${label}-agenda`);
            });
            await step(`${label} detalle`, async () => {
                await openFirstEvent(page, 'agenda');
                await shot(page, `${label}-detalle`);
                await closeModal(page);
            });
            await step(`${label} crear`, async () => {
                await page.locator('button:visible', { hasText: 'Nuevo evento' }).or(page.locator('button.cap-shell-fab:visible')).first().click();
                await page.waitForSelector('[role="dialog"][aria-modal="true"]:not(.cap-shell-sheet)');
                await shot(page, `${label}-crear`);
                await closeModal(page);
            });
            await step(`${label} reglas`, async () => {
                await page.getByRole('button', { name: 'Reglas de recordatorio' }).click();
                await page.waitForSelector('[role="dialog"][aria-modal="true"]:not(.cap-shell-sheet) >> text=Cierre de aclaraciones');
                await shot(page, `${label}-reglas`);
                await closeModal(page);
            });
            await step(`${label} filtro`, async () => {
                await page.locator('select:visible', { has: page.locator('option', { hasText: 'Todos los tipos' }) }).first().selectOption({ label: 'Vencimiento de documento' });
                await page.waitForTimeout(800);
                await shot(page, `${label}-filtro`);
                await page.locator('select:visible', { has: page.locator('option', { hasText: 'Todos los tipos' }) }).first().selectOption({ label: 'Todos los tipos' });
            });
            if (label === 'escritorio-claro') await step(`${label} flujo`, () => realFlow(page, label), 1);
            await step(`${label} campana`, async () => {
                await openCalendar(page);
                await page.locator('button[aria-controls="cap-notifications"]:visible').first().click();
                await page.waitForSelector('#cap-notifications');
                await shot(page, `${label}-campana`);
                await page.keyboard.press('Escape');
            });
            await step(`${label} mis-recordatorios`, async () => {
                await page.goto(`${BASE_URL}/notificaciones`);
                await settle(page);
                await shot(page, `${label}-mis-recordatorios`);
            });
            await step(`${label} plantillas`, async () => {
                await page.goto(`${BASE_URL}/notificaciones/plantillas`);
                await settle(page);
                await shot(page, `${label}-plantillas`);
                await page.getByRole('button', { name: 'Nueva plantilla' }).first().click();
                await page.waitForSelector('[role="dialog"][aria-modal="true"]:not(.cap-shell-sheet)');
                await shot(page, `${label}-plantilla-modal`);
                await closeModal(page);
            });

            problems.push(...seen);
            await context.close();
        }
    }

    // Solo lectura: ve el calendario sin acciones de escritura.
    const context = await openContext(browser, 'escritorio', 'claro');
    const page = await context.newPage();
    const seen = watchConsole(page, 'lector');
    await step('lector acceso', () => login(page, 'qa.lector@crm-b2g.test'));
    await step('lector', async () => {
        await openCalendar(page);
        if (await page.getByRole('button', { name: 'Nuevo evento' }).count()) problems.push('[lector] ve «Nuevo evento»');
        if (await page.getByRole('button', { name: 'Reglas de recordatorio' }).count()) problems.push('[lector] ve «Reglas de recordatorio»');
        await shot(page, 'lector-mes');
        await setView(page, 'Agenda');
        await openFirstEvent(page, 'agenda');
        if (await page.locator('[role="dialog"][aria-modal="true"]:not(.cap-shell-sheet)').getByRole('button', { name: 'Marcar como atendido' }).count()) problems.push('[lector] puede marcar atendido');
        await shot(page, 'lector-detalle');
    });
    problems.push(...seen);
    await context.close();

    await browser.close();
    if (problems.length) {
        console.log(`\n${problems.length} problema(s):`);
        problems.forEach((problem) => console.log(`  ${problem}`));
        process.exit(1);
    }
    console.log('\nCalendario sin errores de consola ni scroll horizontal.');
}

await main();
