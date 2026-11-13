// Revisión visual y de flujo de las pantallas financieras: Reportes, KPI y metas, Cobros, Presupuesto
// revolvente, Flujo de caja y Alertas POA. Abre cada modal y ejecuta el flujo real (crear, editar,
// pagar, ejecutar, eliminar) en escritorio (1440×900) y móvil (390×844), en claro y oscuro.
// Después recorre las pantallas con el usuario de solo lectura y comprueba que no ve acciones de escritura.
//
//   node tests/visual/finanzas.mjs                 todo
//   node tests/visual/finanzas.mjs escritorio      solo una vista (escritorio | movil)
//
// Capturas en storage/app/qa/capturas/finanzas/. Termina con código 1 si hay errores de consola,
// scroll horizontal o un paso que falle.
import { mkdirSync } from 'node:fs';
import { chromium } from 'playwright';
import { openContext, settle, watchConsole } from './capturas.mjs';

const BASE_URL = process.env.QA_URL ?? 'http://localhost:8000';
const PASSWORD = process.env.QA_PASSWORD ?? 'QaB2g#2026';
const ADMIN = process.env.QA_EMAIL ?? 'qa.admin@crm-b2g.test';
const READER = 'qa.lector@crm-b2g.test';
const OUTPUT = 'storage/app/qa/capturas/finanzas';
const RUN = Date.now().toString(36).slice(-5).toUpperCase();

const wanted = process.argv.slice(2);
const VIEWPORTS = ['escritorio', 'movil'].filter((name) => !wanted.length || wanted.includes(name));
const THEMES = ['claro', 'oscuro'];

const problems = [];

async function loginAs(page, email) {
    await page.goto(`${BASE_URL}/login`);
    await page.fill('#login-email', email);
    await page.fill('#login-password', PASSWORD);
    await Promise.all([page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 20000 }), page.click('button[type="submit"]')]);
}

/** Abre una ruta y espera a que el marco esté montado; reintenta si la recarga de Vite deja la página en blanco. */
async function open(page, path) {
    for (let attempt = 1; attempt <= 3; attempt++) {
        const response = await page.goto(`${BASE_URL}${path}`);
        if ((response?.status() ?? 200) >= 400) throw new Error(`HTTP ${response.status()} en ${path}`);
        try {
            await page.waitForSelector('.cap-shell__content', { timeout: 10000 });
            await settle(page);
            return;
        } catch (error) {
            if (attempt === 3) throw error;
        }
    }
}

async function shot(page, label) {
    await page.waitForTimeout(350);
    // En emulación móvil `innerWidth` crece con la página: se compara contra el ancho del viewport.
    const overflow = (await page.evaluate(() => document.documentElement.scrollWidth)) - page.viewportSize().width;
    if (overflow > 1) problems.push(`[${label}] scroll horizontal de ${overflow}px`);
    await page.screenshot({ path: `${OUTPUT}/${label}.png`, fullPage: true, animations: 'disabled' });
    console.log(`ok  ${label}`);
}

/** Ejecuta un paso; si falla, recarga la página y lo intenta una vez más. */
async function step(page, label, action) {
    for (let attempt = 1; attempt <= 2; attempt++) {
        try {
            await action();
            return;
        } catch (error) {
            if (attempt === 2) {
                problems.push(`[${label}] ${error.message.split('\n')[0]}`);
                await page.screenshot({ path: `${OUTPUT}/fallo-${label}.png`, fullPage: true }).catch(() => {});
                return;
            }
            await page.keyboard.press('Escape').catch(() => {});
            await page.reload().catch(() => {});
            await settle(page);
        }
    }
}

const dialog = (page) => page.getByRole('dialog').last();
const visible = (locator) => locator.locator('visible=true').first();

async function toast(page, text) {
    await page.getByText(text, { exact: false }).first().waitFor({ timeout: 10000 });
}

async function confirmDialog(page, button) {
    const alert = page.getByRole('alertdialog');
    await alert.waitFor();
    return alert.getByRole('button', { name: button });
}

async function rowMenu(page, label) {
    await visible(page.getByRole('button', { name: label })).click();
}

// ---------------------------------------------------------------------------------------------

async function reports(page, tag) {
    await open(page, '/reportes');
    await shot(page, `reportes-${tag}`);
    await step(page, `reportes-filtro-${tag}`, async () => {
        await page.getByLabel('Período', { exact: true }).selectOption('year');
        await settle(page);
        await page.getByLabel('Módulo', { exact: true }).selectOption('cotizaciones');
        await settle(page);
        await page.getByRole('button', { name: 'Exportar' }).click();
        await page.getByRole('menuitem', { name: 'Descargar CSV' }).waitFor();
        await shot(page, `reportes-exportar-${tag}`);
        await page.keyboard.press('Escape');
    });
    await step(page, `reportes-descarga-${tag}`, async () => {
        const response = await page.request.get(`${BASE_URL}/reportes/xlsx?module=cotizaciones`);
        if (!response.ok()) throw new Error(`exporte XLSX respondió ${response.status()}`);
    });
}

async function kpis(page, tag) {
    await open(page, '/kpis');
    await shot(page, `kpis-${tag}`);
    await step(page, `kpis-meta-${tag}`, async () => {
        await page.getByRole('button', { name: 'Fijar meta' }).first().click();
        const modal = dialog(page);
        await modal.waitFor();
        await modal.getByLabel('Indicador').selectOption('tasa_adjudicacion');
        await modal.getByLabel('Meta (%)').fill('150');
        await modal.getByRole('button', { name: 'Guardar meta' }).click();
        await modal.getByText('no puede pasar de 100').waitFor();
        await shot(page, `kpis-modal-error-${tag}`);
        await modal.getByLabel('Meta (%)').fill('60');
        await modal.getByRole('button', { name: 'Guardar meta' }).click();
        await toast(page, 'Meta guardada');
        await settle(page);
        await shot(page, `kpis-con-meta-${tag}`);
    });
    await step(page, `kpis-editar-${tag}`, async () => {
        await rowMenu(page, 'Acciones de la meta de Tasa de adjudicación');
        await page.getByRole('menuitem', { name: 'Editar meta' }).click();
        await dialog(page).waitFor();
        await shot(page, `kpis-modal-editar-${tag}`);
        await dialog(page).getByRole('button', { name: 'Cancelar' }).click();
        await rowMenu(page, 'Acciones de la meta de Tasa de adjudicación');
        await page.getByRole('menuitem', { name: 'Eliminar meta' }).click();
        const button = await confirmDialog(page, 'Eliminar meta');
        await shot(page, `kpis-confirmar-${tag}`);
        await button.click();
        await toast(page, 'Meta eliminada');
    });
}

async function collections(page, tag) {
    const number = `QA-${RUN}-${tag}`;
    await open(page, '/cobros');
    await shot(page, `cobros-${tag}`);
    await step(page, `cobros-alta-${tag}`, async () => {
        await visible(page.getByRole('button', { name: 'Nueva factura' })).click();
        const modal = dialog(page);
        await modal.waitFor();
        await modal.getByRole('button', { name: 'Registrar factura' }).click();
        await modal.getByText('El campo número de factura es obligatorio').waitFor();
        await shot(page, `cobros-modal-error-${tag}`);
        await modal.getByLabel('Número de factura').fill(number);
        await modal.getByLabel('Monto').fill('36500');
        await modal.getByLabel('Concepto').fill('Suministro de equipo de prueba');
        await modal.getByLabel('Fecha de emisión').fill('2026-10-01');
        await modal.getByLabel('Fecha de vencimiento').fill('2026-11-01');
        await modal.getByLabel('Interés moratorio anual (%)').fill('12');
        await modal.getByRole('button', { name: 'Registrar factura' }).click();
        await toast(page, `Factura ${number} registrada`);
        await settle(page);
    });
    await step(page, `cobros-ficha-${tag}`, async () => {
        await rowMenu(page, `Acciones de la factura ${number}`);
        await page.getByRole('menuitem', { name: 'Ver ficha' }).click();
        const modal = dialog(page);
        await modal.getByText('Recordatorio por WhatsApp').waitFor();
        await modal.getByText('Estimado cliente').waitFor();
        await modal.getByRole('textbox').first().fill('Llamada de seguimiento: pagan el viernes.');
        await modal.getByRole('button', { name: 'Guardar nota' }).click();
        await toast(page, 'Nota de cobro guardada');
        await shot(page, `cobros-modal-ficha-${tag}`);
        await modal.getByRole('button', { name: 'Editar' }).click();
        await dialog(page).getByLabel('Concepto').fill('Suministro de equipo corregido');
        await dialog(page).getByRole('button', { name: 'Guardar cambios' }).click();
        await toast(page, `Factura ${number} actualizada`);
    });
    await step(page, `cobros-pago-${tag}`, async () => {
        await rowMenu(page, `Acciones de la factura ${number}`);
        await page.getByRole('menuitem', { name: 'Registrar pago' }).click();
        await dialog(page).getByText('Interés moratorio').waitFor();
        await shot(page, `cobros-modal-pago-${tag}`);
        await dialog(page).getByRole('button', { name: 'Registrar pago' }).click();
        await toast(page, `Pago de la factura ${number} registrado`);
        await settle(page);
    });
    await step(page, `cobros-eliminar-${tag}`, async () => {
        await rowMenu(page, `Acciones de la factura ${number}`);
        await page.getByRole('menuitem', { name: 'Eliminar' }).click();
        const button = await confirmDialog(page, 'Eliminar factura');
        await shot(page, `cobros-confirmar-${tag}`);
        await button.click();
        await toast(page, `Factura ${number} eliminada`);
    });
}

async function budget(page, tag) {
    const concept = `Movimiento QA ${RUN} ${tag}`;
    await open(page, '/presupuesto-revolvente');
    await shot(page, `presupuesto-${tag}`);
    await step(page, `presupuesto-inicial-${tag}`, async () => {
        await page.getByRole('button', { name: 'Monto inicial' }).click();
        await dialog(page).waitFor();
        await dialog(page).getByLabel('Monto inicial').fill('50000');
        await shot(page, `presupuesto-modal-inicial-${tag}`);
        await dialog(page).getByRole('button', { name: 'Guardar monto' }).click();
        await toast(page, 'Monto inicial actualizado');
    });
    await step(page, `presupuesto-movimiento-${tag}`, async () => {
        await visible(page.getByRole('button', { name: 'Registrar movimiento' })).click();
        const modal = dialog(page);
        await modal.waitFor();
        await modal.getByLabel('Monto').fill('1250.50');
        await modal.getByLabel('Concepto').fill(concept);
        await modal.getByLabel('Referencia').fill(`REF-${Date.now().toString(36)}`);
        await shot(page, `presupuesto-modal-movimiento-${tag}`);
        await modal.getByRole('button', { name: 'Registrar movimiento' }).click();
        await toast(page, 'Movimiento registrado');
        await settle(page);
    });
    await step(page, `presupuesto-sincronizar-${tag}`, async () => {
        await page.getByRole('button', { name: 'Sincronizar fuentes' }).click();
        await toast(page, 'incroniza');
        await settle(page);
        await shot(page, `presupuesto-con-movimientos-${tag}`);
    });
    await step(page, `presupuesto-eliminar-${tag}`, async () => {
        await rowMenu(page, `Acciones del movimiento ${concept}`);
        await page.getByRole('menuitem', { name: 'Eliminar movimiento' }).click();
        await (await confirmDialog(page, 'Eliminar movimiento')).click();
        await toast(page, 'Movimiento eliminado');
    });
}

async function cashFlow(page, tag) {
    const concept = `Ingreso QA ${RUN} ${tag}`;
    await open(page, '/flujo-caja');
    await shot(page, `flujo-${tag}`);
    await step(page, `flujo-alta-${tag}`, async () => {
        await visible(page.getByRole('button', { name: 'Registrar ingreso' })).click();
        const modal = dialog(page);
        await modal.waitFor();
        await modal.getByLabel('Concepto').fill(concept);
        await modal.getByLabel('Monto').fill('8000');
        await shot(page, `flujo-modal-${tag}`);
        await modal.getByRole('button', { name: 'Registrar ingreso' }).click();
        await toast(page, 'Ingreso registrado');
        await settle(page);
        await shot(page, `flujo-con-ingreso-${tag}`);
    });
    await step(page, `flujo-editar-${tag}`, async () => {
        await rowMenu(page, `Acciones del ingreso ${concept}`);
        await page.getByRole('menuitem', { name: 'Editar' }).click();
        await dialog(page).getByLabel('Monto').fill('8500');
        await dialog(page).getByRole('button', { name: 'Guardar cambios' }).click();
        await toast(page, 'Ingreso actualizado');
    });
    await step(page, `flujo-eliminar-${tag}`, async () => {
        await rowMenu(page, `Acciones del ingreso ${concept}`);
        await page.getByRole('menuitem', { name: 'Eliminar' }).click();
        await (await confirmDialog(page, 'Eliminar ingreso')).click();
        await toast(page, 'Ingreso eliminado');
    });
}

async function poa(page, tag) {
    const project = `Partida QA ${RUN} ${tag}`;
    await open(page, '/alertas-poa');
    await shot(page, `poa-${tag}`);
    await step(page, `poa-alta-${tag}`, async () => {
        await visible(page.getByRole('button', { name: 'Nueva partida' })).click();
        const modal = dialog(page);
        await modal.waitFor();
        await modal.getByLabel('Proyecto').fill(project);
        await modal.getByLabel('Monto presupuestado').fill('250000');
        await modal.getByLabel('Fecha estimada de compra').fill('2026-11-30');
        await modal.getByText('Alertar al cierre del año fiscal').click();
        await shot(page, `poa-modal-${tag}`);
        await modal.getByRole('button', { name: 'Registrar partida' }).click();
        await toast(page, 'Partida POA registrada');
        await settle(page);
    });
    await step(page, `poa-ficha-${tag}`, async () => {
        await rowMenu(page, `Acciones de la partida ${project}`);
        await page.getByRole('menuitem', { name: 'Ver ficha' }).click();
        await dialog(page).waitFor();
        await shot(page, `poa-modal-ficha-${tag}`);
        await dialog(page).getByRole('button', { name: 'Marcar ejecutada' }).click();
        await (await confirmDialog(page, 'Marcar ejecutada')).click();
        await toast(page, 'Partida marcada como ejecutada');
        await page.keyboard.press('Escape');
    });
    await step(page, `poa-eliminar-${tag}`, async () => {
        await rowMenu(page, `Acciones de la partida ${project}`);
        await page.getByRole('menuitem', { name: 'Eliminar' }).click();
        await (await confirmDialog(page, 'Eliminar partida')).click();
        await toast(page, 'Partida POA eliminada');
    });
}

/** El lector ve todo y puede exportar, pero no encuentra botones de escritura. */
async function readerTour(page, tag) {
    const checks = [
        ['/reportes', 'Exportar', []],
        ['/kpis', null, ['Fijar meta']],
        ['/cobros', 'Exportar', ['Nueva factura']],
        ['/presupuesto-revolvente', 'Exportar', ['Registrar movimiento', 'Sincronizar fuentes', 'Monto inicial']],
        ['/flujo-caja', null, ['Registrar ingreso']],
        ['/alertas-poa', null, ['Nueva partida']],
    ];
    for (const [path, expected, forbidden] of checks) {
        await step(page, `lector${path.replace(/\//g, '-')}-${tag}`, async () => {
            await open(page, path);
            if (expected) await visible(page.getByRole('button', { name: expected })).waitFor();
            for (const name of forbidden) {
                if (await page.getByRole('button', { name }).locator('visible=true').count()) throw new Error(`el lector ve «${name}»`);
            }
        });
    }
    await shot(page, `lector-presupuesto-${tag}`);
}

// ---------------------------------------------------------------------------------------------

mkdirSync(OUTPUT, { recursive: true });
const browser = await chromium.launch();

for (const viewport of VIEWPORTS) {
    for (const theme of THEMES) {
        const tag = `${viewport}-${theme}`;
        const context = await openContext(browser, viewport, theme);
        const page = await context.newPage();
        const seen = watchConsole(page, tag);
        try {
            await loginAs(page, ADMIN);
            for (const flow of [reports, kpis, collections, budget, cashFlow, poa]) {
                await step(page, `${flow.name}-${tag}`, () => flow(page, tag));
            }
        } catch (error) {
            problems.push(`[${tag}] ${error.message.split('\n')[0]}`);
        }
        problems.push(...seen);
        await context.close();
    }
}

const readerContext = await openContext(browser, VIEWPORTS[0] ?? 'escritorio', 'claro');
const readerPage = await readerContext.newPage();
const readerSeen = watchConsole(readerPage, 'lector');
await loginAs(readerPage, READER);
await readerTour(readerPage, VIEWPORTS[0] ?? 'escritorio');
problems.push(...readerSeen);
await readerContext.close();

await browser.close();
if (problems.length) {
    console.log(`\n${problems.length} problema(s):`);
    problems.forEach((problem) => console.log(`  ${problem}`));
    process.exit(1);
}
console.log('\nFlujos completos, sin errores de consola ni scroll horizontal.');
