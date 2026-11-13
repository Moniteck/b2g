// Recorrido visual y funcional del módulo Licitaciones, en escritorio y móvil, claro y oscuro.
//
//   node tests/visual/licitaciones.mjs            recorrido completo
//   node tests/visual/licitaciones.mjs --sin-ia   sin subir el pliego (no llama a OpenAI)
//
// Variables: QA_URL (http://localhost:8000), QA_PLIEGO (ruta de un PDF de pliego para subir; sin
// ella se omite el paso del pliego). El paso del pliego ejecuta `php artisan queue:work --once`
// para procesar el análisis (no hay worker permanente en desarrollo) y llama de verdad a OpenAI.
//
// Capturas en storage/app/qa/capturas/licitaciones/. Termina con código 1 si hay errores de consola,
// scroll horizontal o un paso falla.
import { execSync } from 'node:child_process';
import { existsSync, mkdirSync } from 'node:fs';
import { chromium } from 'playwright';
import { horizontalOverflow, openContext, settle, watchConsole } from './capturas.mjs';

const BASE_URL = process.env.QA_URL ?? 'http://localhost:8000';
const PASSWORD = process.env.QA_PASSWORD ?? 'QaB2g#2026';
const ADMIN = 'qa.admin@crm-b2g.test';
const VIEWER = 'qa.lector@crm-b2g.test';
const OUTPUT = 'storage/app/qa/capturas/licitaciones';
const CAPTURE = 'tests/fixtures/honducompras/captura-cpr-sit-025-2022.png';
const PLIEGO = process.argv.includes('--sin-ia') ? null : process.env.QA_PLIEGO ?? null;
const problems = [];

mkdirSync(OUTPUT, { recursive: true });

async function login(page, email) {
    await page.goto(`${BASE_URL}/login`);
    await page.fill('#login-email', email);
    await page.fill('#login-password', PASSWORD);
    await Promise.all([page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 20000 }), page.click('button[type="submit"]')]);
}

/** Vite con HMR puede dejar la página en blanco mientras otros agentes editan: se reintenta. */
async function go(page, path) {
    for (let attempt = 1; attempt <= 3; attempt++) {
        try {
            await page.goto(`${BASE_URL}${path}`);
            await settle(page);
            if (await page.locator('.cap-shell').count()) return;
        } catch (error) {
            if (attempt === 3) throw error;
        }
        await page.waitForTimeout(1500);
    }
    throw new Error(`La página ${path} no terminó de cargar`);
}

async function shot(page, name) {
    const overflow = await horizontalOverflow(page);
    if (overflow > 1) problems.push(`[${name}] scroll horizontal de ${overflow}px`);
    await page.screenshot({ path: `${OUTPUT}/${name}.png`, fullPage: true, animations: 'disabled' });
    console.log(`ok  ${name}`);
}

async function modalShot(page, name) {
    await page.locator('[role="dialog"]:visible').first().waitFor({ timeout: 10000 });
    await page.waitForTimeout(500);
    await shot(page, name);
}

async function closeModal(page) {
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);
}

async function step(name, action) {
    try {
        await action();
    } catch (error) {
        problems.push(`[${name}] ${error.message.split('\n')[0]}`);
        console.log(`ERR ${name}: ${error.message.split('\n')[0]}`);
    }
}

const dialog = (page) => page.locator('[role="dialog"]:visible').last();

/** Recorrido funcional completo como administrador, en escritorio claro. */
async function flow(browser) {
    const context = await openContext(browser, 'escritorio', 'claro');
    const page = await context.newPage();
    problems.push(...watchConsoleLive(page, 'flujo'));
    await login(page, ADMIN);
    const reference = `QA-LIC-${Date.now().toString().slice(-6)}`;

    await step('crear manual', async () => {
        await go(page, '/licitaciones');
        await page.getByRole('button', { name: 'Nueva licitación' }).first().click();
        await modalShot(page, 'flujo-01-nueva');
        // Enviar vacío muestra los errores bajo cada campo.
        await dialog(page).getByRole('button', { name: 'Crear licitación' }).click();
        await page.waitForTimeout(800);
        await modalShot(page, 'flujo-02-nueva-errores');
        const form = dialog(page);
        await form.getByLabel(new RegExp('^Expediente')).fill(reference);
        await form.getByLabel(new RegExp('^Objeto')).fill('Suministro de lubricantes y grasas para la flota (prueba visual)');
        await form.getByLabel(new RegExp('^Recepción de ofertas')).fill('2026-12-30T10:00');
        await form.getByLabel(new RegExp('^Cierre de aclaraciones')).fill('2026-12-15T16:00');
        await form.getByLabel(new RegExp('^Apertura de ofertas')).fill('2026-12-30T10:15');
        await form.getByRole('button', { name: 'Crear licitación' }).click();
        await page.waitForURL(/\/licitaciones\/\d+/, { timeout: 15000 });
        await settle(page);
        await shot(page, 'flujo-03-ficha-creada');
    });

    await step('editar', async () => {
        await page.getByRole('button', { name: 'Editar' }).first().click();
        await modalShot(page, 'flujo-04-editar');
        await dialog(page).getByLabel(new RegExp('^Unidad de compra')).fill('Gerencia Administrativa');
        await dialog(page).getByRole('button', { name: 'Guardar cambios' }).click();
        await page.waitForTimeout(1500);
        await shot(page, 'flujo-05-editada');
    });

    await step('pestañas', async () => {
        for (const [name, label] of [['fechas', 'Fechas críticas'], ['documentos', 'Documentos'], ['analisis', 'Análisis del pliego'], ['oportunidad', 'Oportunidad y cotizaciones']]) {
            await page.getByRole('tab', { name: new RegExp(label) }).click();
            await page.waitForTimeout(700);
            await shot(page, `flujo-06-tab-${name}`);
        }
    });

    await step('eliminar manual', async () => {
        await page.getByRole('button', { name: 'Más acciones' }).click();
        await page.getByRole('menuitem', { name: /Eliminar/ }).click();
        await page.waitForTimeout(500);
        await shot(page, 'flujo-07-confirmar-eliminar');
        await page.getByRole('button', { name: 'Eliminar licitación' }).click();
        await page.waitForURL(`${BASE_URL}/licitaciones`, { timeout: 15000 });
        await settle(page);
    });

    let tenderUrl = null;
    await step('importar captura', async () => {
        await page.getByRole('button', { name: 'Importar captura' }).click();
        await modalShot(page, 'flujo-08-importar');
        await dialog(page).locator('input[type="file"]').setInputFiles(CAPTURE);
        await page.waitForTimeout(300);
        await dialog(page).getByRole('button', { name: 'Leer captura' }).click();
        await page.waitForURL(/\/licitaciones\/importar\//, { timeout: 60000 });
        await settle(page);
        await shot(page, 'flujo-09-revision-captura');
        const statuses = await page.locator('[data-field-status]').evaluateAll((nodes) => nodes.map((node) => node.dataset.fieldStatus));
        if (!statuses.includes('read')) problems.push('[importar] la revisión no muestra campos leídos');
        // El OCR lee mal el correo (queda «Dudoso»): se corrige como lo haría la persona.
        await page.getByLabel(new RegExp('^Correo')).fill('contrataciones@sit.gob.hn');
        // Si el expediente ya existe (corridas anteriores), se actualiza: la casilla viene marcada.
        await page.getByRole('button', { name: /Confirmar e importar|Confirmar y actualizar/ }).click();
        await page.waitForURL(/\/licitaciones\/\d+/, { timeout: 30000 });
        await settle(page);
        tenderUrl = new URL(page.url()).pathname;
        await shot(page, 'flujo-10-ficha-importada');
        await page.getByRole('tab', { name: /Documentos/ }).click();
        await page.waitForTimeout(1500);
        await shot(page, 'flujo-11-documentos');
        const view = page.getByRole('button', { name: /^Ver Captura/ }).first();
        if (await view.count()) {
            await view.click();
            await modalShot(page, 'flujo-12-ver-captura');
            await closeModal(page);
        }
    });

    if (PLIEGO && tenderUrl) {
        await step('pliego', async () => {
            await go(page, `${tenderUrl}?tab=analisis`);
            await page.getByRole('button', { name: /Subir pliego|Analizar otro pliego/ }).click();
            await modalShot(page, 'flujo-13-subir-pliego');
            await dialog(page).locator('input[type="file"]').setInputFiles(PLIEGO);
            await page.waitForTimeout(300);
            await dialog(page).getByRole('button', { name: 'Subir y analizar' }).click();
            await page.waitForSelector('[data-analysis-status="queued"]', { timeout: 60000 });
            await shot(page, 'flujo-14-en-cola');
            console.log('    procesando la cola: php artisan queue:work --once');
            const started = Date.now();
            execSync('php artisan queue:work --once --timeout=1500 --tries=1', { stdio: 'inherit' });
            console.log(`    análisis terminado en ${Math.round((Date.now() - started) / 1000)} s`);
            await page.waitForSelector('[data-analysis-status="review"], [data-analysis-status="failed"]', { timeout: 30000 });
            await shot(page, 'flujo-15-listo');
            if (await page.locator('[data-analysis-status="failed"]').count()) throw new Error('El análisis del pliego falló');
            await page.getByRole('link', { name: 'Revisar datos' }).first().click();
            await page.waitForURL(/\/revision$/, { timeout: 15000 });
            await settle(page);
            await shot(page, 'flujo-16-revision-pliego');
            const pdfButton = page.getByRole('button', { name: /Ver en el PDF/ }).first();
            await pdfButton.click();
            await page.waitForTimeout(3000);
            await modalShot(page, 'flujo-17-pdf-en-pagina');
            await closeModal(page);
            // En cada conflicto: las fechas, del pliego; lo demás (expediente, objeto…), el actual.
            for (const row of await page.locator('[data-row]').all()) {
                const key = await row.getAttribute('data-row');
                const choice = row.getByRole('button', { name: ['clarification_deadline', 'presentation_deadline', 'opening_at'].includes(key) ? 'Usar el del pliego' : 'Mantener el actual' });
                if (await choice.count()) await choice.click();
            }
            await page.getByRole('button', { name: 'Confirmar y aplicar' }).click();
            await page.waitForURL(/tab=fechas/, { timeout: 20000 });
            await settle(page);
            await shot(page, 'flujo-18-fechas-aplicadas');
            await page.goBack();
            await settle(page);
            const matrix = page.getByRole('button', { name: /matriz documental/ });
            if (await matrix.isEnabled()) {
                await matrix.click();
                await page.waitForTimeout(2000);
            }
            const quote = page.getByRole('button', { name: 'Crear cotización borrador' });
            if ((await quote.count()) && (await quote.isEnabled())) {
                await quote.click();
                await page.waitForTimeout(2000);
            }
            await shot(page, 'flujo-19-revision-confirmada');
        });
    }

    await context.close();
    return tenderUrl;
}

/** Errores de consola que llegan mientras corre el flujo. */
function watchConsoleLive(page, label) {
    const list = [];
    page.on('console', (message) => {
        if (message.type() === 'error') problems.push(`[${label}] consola: ${message.text()}`);
    });
    page.on('pageerror', (error) => problems.push(`[${label}] excepción: ${error.message}`));
    return list;
}

/** Pantallas y modales en las cuatro combinaciones de vista y tema. */
async function screens(browser, tenderUrl) {
    for (const viewport of ['escritorio', 'movil']) {
        for (const theme of ['claro', 'oscuro']) {
            const label = `${viewport}-${theme}`;
            const context = await openContext(browser, viewport, theme);
            const page = await context.newPage();
            const seen = watchConsole(page, label);
            await login(page, ADMIN);

            await step(`lista ${label}`, async () => {
                await go(page, '/licitaciones');
                await shot(page, `lista-${label}`);
                await page.getByRole('button', { name: 'Nueva licitación' }).first().click();
                await modalShot(page, `modal-nueva-${label}`);
                await closeModal(page);
                await page.getByRole('button', { name: 'Importar captura' }).click();
                await modalShot(page, `modal-importar-${label}`);
                await closeModal(page);
                await go(page, '/licitaciones?search=zzzz-sin-resultados');
                await shot(page, `lista-sin-resultados-${label}`);
            });

            await step(`revision captura ${label}`, async () => {
                await go(page, '/licitaciones');
                await page.getByRole('button', { name: 'Importar captura' }).click();
                await dialog(page).locator('input[type="file"]').setInputFiles(CAPTURE);
                await page.waitForTimeout(300);
                await dialog(page).getByRole('button', { name: 'Leer captura' }).click();
                await page.waitForURL(/\/licitaciones\/importar\//, { timeout: 60000 });
                await settle(page);
                await shot(page, `revision-captura-${label}`);
            });

            if (tenderUrl) {
                await step(`ficha ${label}`, async () => {
                    for (const tab of ['datos', 'fechas', 'documentos', 'analisis', 'oportunidad']) {
                        await go(page, `${tenderUrl}?tab=${tab}`);
                        await page.waitForTimeout(800);
                        await shot(page, `ficha-${tab}-${label}`);
                    }
                    await page.getByRole('button', { name: 'Editar' }).first().click();
                    await modalShot(page, `modal-editar-${label}`);
                    await closeModal(page);
                    const upload = page.getByRole('button', { name: /Subir pliego|Analizar otro pliego/ });
                    await go(page, `${tenderUrl}?tab=analisis`);
                    if (await upload.count()) {
                        await upload.click();
                        await modalShot(page, `modal-pliego-${label}`);
                        await closeModal(page);
                    }
                    const review = page.getByRole('link', { name: /Revisar datos|Ver revisión/ }).first();
                    if (await review.count()) {
                        await review.click();
                        await page.waitForURL(/\/revision$/, { timeout: 15000 });
                        await settle(page);
                        await shot(page, `revision-pliego-${label}`);
                        await page.getByRole('button', { name: /Ver en el PDF/ }).first().click();
                        await page.waitForTimeout(2500);
                        await modalShot(page, `modal-pdf-${label}`);
                        await closeModal(page);
                    }
                    await go(page, tenderUrl);
                    await page.getByRole('button', { name: 'Más acciones' }).click();
                    await page.getByRole('menuitem', { name: /Eliminar/ }).click();
                    await page.waitForTimeout(500);
                    await shot(page, `confirmar-eliminar-${label}`);
                    await page.getByRole('button', { name: 'Cancelar' }).last().click();
                });
            }

            problems.push(...seen);
            await context.close();
        }
    }
}

/** El lector ve, pero no tiene botones para escribir. */
async function viewer(browser, tenderUrl) {
    const context = await openContext(browser, 'escritorio', 'claro');
    const page = await context.newPage();
    const seen = watchConsole(page, 'lector');
    await login(page, VIEWER);
    await step('lector', async () => {
        await go(page, '/licitaciones');
        await shot(page, 'lector-lista');
        for (const name of ['Nueva licitación', 'Importar captura']) {
            if (await page.getByRole('button', { name }).count()) problems.push(`[lector] ve el botón «${name}»`);
        }
        if (tenderUrl) {
            await go(page, `${tenderUrl}?tab=analisis`);
            await shot(page, 'lector-ficha-analisis');
            if (await page.getByRole('button', { name: 'Editar' }).count()) problems.push('[lector] ve el botón «Editar»');
            if (await page.getByRole('button', { name: /Subir pliego/ }).count()) problems.push('[lector] ve «Subir pliego»');
        }
    });
    problems.push(...seen);
    await context.close();
}

const browser = await chromium.launch();
if (PLIEGO && !existsSync(PLIEGO)) problems.push(`No existe QA_PLIEGO: ${PLIEGO}`);
const tenderUrl = await flow(browser);
await screens(browser, tenderUrl);
await viewer(browser, tenderUrl);
await browser.close();

if (problems.length) {
    console.log(`\n${problems.length} problema(s):`);
    problems.forEach((problem) => console.log(`  ${problem}`));
    process.exit(1);
}
console.log('\nRecorrido completo sin errores de consola ni scroll horizontal.');
