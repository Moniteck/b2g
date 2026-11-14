// Revisión visual y de flujo de Post-adjudicación (contratos).
//
//   node tests/visual/contratos.mjs            (QA_ONLY=escritorio-claro para una sola variante)
//
// En escritorio claro, como qa.admin, hace el recorrido real: crear un contrato, completar sus datos
// (plazo en días hábiles), subir el PDF del contrato, firmarlo y sellarlo, registrar una entrega y
// marcarla entregada con evidencia, pasar a «Producto entregado», registrar un seguimiento con próxima
// acción, ver sus eventos en /calendario y cerrar el contrato. En cada variante (1440×900 y 390×844,
// claro y oscuro) captura la lista, los filtros, cada pestaña de la ficha y cada modal. Con qa.lector
// comprueba que no hay acciones de escritura. Capturas en storage/app/qa/capturas/contratos/.
// Termina con código 1 ante errores de consola, scroll horizontal o pasos fallidos.
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { chromium } from 'playwright';
import { horizontalOverflow, openContext, settle, watchConsole } from './capturas.mjs';

const BASE_URL = process.env.QA_URL ?? 'http://localhost:8000';
const PASSWORD = process.env.QA_PASSWORD ?? 'QaB2g#2026';
const OUTPUT = 'storage/app/qa/capturas/contratos';
const FILES = 'storage/app/qa/contratos';
const ONLY = process.env.QA_ONLY ?? '';
const VIEWPORTS = ['escritorio', 'movil'].filter((name) => !ONLY || ONLY.startsWith(name));
const THEMES = ['claro', 'oscuro'].filter((name) => !ONLY || ONLY.endsWith(name));
const MODAL = '[role="dialog"][aria-modal="true"]:not(.cap-shell-sheet)';

mkdirSync(OUTPUT, { recursive: true });
mkdirSync(FILES, { recursive: true });

// PDF de prueba (contrato y acta) y firma y sello PNG por si el usuario aún no los tiene.
execFileSync('php', ['-r', `
require 'vendor/autoload.php';
$out = '${FILES}';
foreach (['contrato-qa' => 'Contrato de suministro QA', 'acta-qa' => 'Acta de recepcion QA'] as $file => $title) {
  $pdf = new TCPDF('P', 'pt'); $pdf->setPrintHeader(false); $pdf->setPrintFooter(false);
  $pdf->AddPage('P', [612, 792]); $pdf->SetFont('helvetica', '', 24); $pdf->Write(0, $title);
  file_put_contents("$out/$file.pdf", $pdf->Output('x.pdf', 'S'));
}
$im = imagecreatetruecolor(600, 200); imagesavealpha($im, true); imagealphablending($im, false);
imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127)); imagealphablending($im, true);
$ink = imagecolorallocate($im, 20, 40, 160); imagesetthickness($im, 6); imageline($im, 30, 120, 570, 80, $ink);
imagepng($im, "$out/firma.png");
$im = imagecreatetruecolor(300, 300); imagesavealpha($im, true); imagealphablending($im, false);
imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127)); imagealphablending($im, true);
imageellipse($im, 150, 150, 280, 280, imagecolorallocate($im, 200, 20, 30));
imagepng($im, "$out/sello.png");
`]);

const problems = [];
const stamp = Date.now().toString().slice(-6);
const NUMBER = `QA-CTR-${stamp}`;

async function login(page, email) {
    await page.goto(`${BASE_URL}/login`);
    await page.fill('#login-email', email);
    await page.fill('#login-password', PASSWORD);
    await Promise.all([page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 20000 }), page.click('button[type="submit"]')]);
}

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
            await page_wait(1500);
        }
    }
    return null;
}

const page_wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function shot(page, name) {
    await page.waitForTimeout(400);
    let overflow = 0;
    try {
        overflow = await horizontalOverflow(page);
    } catch {
        // Una recarga de Vite (otros agentes editan) destruye el contexto: se espera y se repite.
        await page.waitForLoadState('networkidle').catch(() => {});
        await page.waitForTimeout(1500);
        overflow = await horizontalOverflow(page);
    }
    if (overflow > 1) problems.push(`[${name}] scroll horizontal de ${overflow}px`);
    await page.screenshot({ path: `${OUTPUT}/${name}.png`, fullPage: false, animations: 'disabled' });
    console.log(`ok  ${name}`);
}

async function go(page, path, selector) {
    for (let attempt = 1; attempt <= 4; attempt++) {
        try {
            await page.goto(`${BASE_URL}${path}`);
            await page.waitForSelector(selector, { timeout: 15000 });
            await settle(page);
            return;
        } catch (error) {
            if (attempt === 4) throw error;
            await page.waitForTimeout(2000);
        }
    }
}

const modal = (page) => page.locator(MODAL).last();

async function waitModal(page, text) {
    await page.waitForSelector(MODAL, { timeout: 10000 });
    if (text) await modal(page).getByText(text, { exact: false }).first().waitFor({ timeout: 10000 });
    await page.waitForTimeout(300);
}

async function closeModal(page) {
    await page.keyboard.press('Escape');
    await page.waitForSelector(MODAL, { state: 'detached', timeout: 5000 }).catch(() => {});
}

async function submitModal(page, buttonName) {
    await modal(page).getByRole('button', { name: buttonName }).click();
    await page.waitForSelector(MODAL, { state: 'detached', timeout: 20000 });
    await settle(page);
}

async function tab(page, name) {
    await page.getByRole('tab', { name: new RegExp(`^${name}`) }).click();
    await page.waitForTimeout(500);
}

async function moreActions(page, item) {
    await page.getByRole('button', { name: 'Más acciones' }).click();
    await page.getByRole('menuitem', { name: item }).click();
}

/** Recorrido real completo como administrador. */
async function realFlow(page, label) {
    await go(page, '/post-adjudicacion', 'main table:visible, main ul[aria-label="Contratos"]:visible');
    await page.getByRole('button', { name: 'Nuevo contrato' }).first().click();
    await waitModal(page, 'Identificación');
    const dialog = modal(page);
    await dialog.getByLabel('Número de contrato').fill(NUMBER);
    await dialog.getByLabel('Nombre u objeto').fill('Suministro de equipo de cómputo QA');
    await dialog.getByLabel('Estado inicial').selectOption('En ejecución');
    await dialog.getByLabel('Monto adjudicado').fill('1250000');
    await dialog.getByLabel('Fecha de firma').fill('2026-11-16');
    await dialog.getByLabel('El plazo corre desde').selectOption('Orden de inicio');
    await dialog.getByLabel('Plazo (días)').fill('20');
    await dialog.getByLabel('Tipo de días').selectOption('habiles');
    await dialog.getByLabel('Fecha de inicio').fill('2026-11-17');
    await page.waitForTimeout(1200);
    await shot(page, `${label}-crear-lleno`);
    await dialog.getByRole('button', { name: 'Crear contrato' }).click();
    await page.waitForURL(/\/post-adjudicacion\/\d+/, { timeout: 20000 });
    await settle(page);
    const id = page.url().match(/post-adjudicacion\/(\d+)/)[1];
    await shot(page, `${label}-ficha-nueva`);

    // Completar datos: contacto de la institución y oficial.
    await page.getByRole('button', { name: /Editar/ }).first().click();
    await waitModal(page, 'Responsables');
    const edit = modal(page);
    const institution = edit.getByLabel('Institución');
    await institution.fill('Sec');
    await page.waitForTimeout(1200);
    await page.locator('[role="option"]').first().click().catch(() => {});
    await edit.getByLabel('Oficial de seguimiento').selectOption({ index: 1 });
    await edit.getByLabel('Notas').fill('Entrega en bodega central, horario de 8 a 4.');
    await shot(page, `${label}-editar`);
    await submitModal(page, 'Guardar cambios');

    // Documentos: subir el PDF del contrato y firmarlo.
    await tab(page, 'Documentos');
    await page.getByRole('button', { name: 'Subir documento' }).click();
    await waitModal(page);
    await modal(page).locator('input[type="file"]').setInputFiles(`${FILES}/contrato-qa.pdf`);
    const category = modal(page).getByLabel('Categoría');
    if (await category.count()) await category.selectOption('Contrato');
    await shot(page, `${label}-subir-documento`);
    await modal(page).getByRole('button', { name: /Subir|Agregar/ }).last().click();
    await page.waitForSelector(MODAL, { state: 'detached', timeout: 20000 });
    await page.getByText('contrato-qa', { exact: false }).first().waitFor({ timeout: 15000 });
    await shot(page, `${label}-documentos`);

    await page.getByRole('button', { name: /^Firmar y sellar/ }).first().click();
    await waitModal(page);
    await page.waitForSelector('[aria-label="Opciones de firma"]', { timeout: 15000 });
    await page.waitForSelector('[aria-label="Cargando tu firma y sello"]', { state: 'detached', timeout: 15000 }).catch(() => {});
    for (const type of ['firma', 'sello']) {
        const input = modal(page).locator(`input[type="file"][accept*="png"]`).nth(type === 'firma' ? 0 : 0);
        if (await modal(page).getByText(type === 'firma' ? 'Aún no has subido tu firma.' : 'Aún no has subido tu sello.').count()) {
            await input.setInputFiles(`${FILES}/${type}.png`);
            await page.waitForTimeout(2500);
        }
    }
    await page.waitForTimeout(1500);
    await shot(page, `${label}-firmar`);
    const [signed] = await Promise.all([
        page.waitForResponse((response) => response.url().includes('/firmas/firmar') && response.request().method() === 'POST', { timeout: 30000 }),
        modal(page).getByRole('button', { name: /Firmar y sellar|Firmar/ }).last().click(),
    ]);
    if (signed.status() !== 201) problems.push(`[${label}] firmar respondió ${signed.status()}`);
    await page.waitForSelector(MODAL, { state: 'detached', timeout: 20000 }).catch(() => closeModal(page));
    await page.waitForTimeout(1500);
    await shot(page, `${label}-documentos-firmado`);

    // Entregas: crear, marcar entregada con evidencia.
    await tab(page, 'Entregas');
    await page.getByRole('button', { name: 'Nueva entrega' }).click();
    await waitModal(page, 'Descripción');
    await modal(page).getByLabel('Descripción').fill('Lote único: 40 computadoras de escritorio');
    await modal(page).getByLabel('Cantidad').fill('40');
    await modal(page).getByLabel('Unidad').fill('Equipos');
    await modal(page).getByLabel('Fecha comprometida').fill('2026-12-04');
    await shot(page, `${label}-entrega`);
    await submitModal(page, 'Registrar entrega');
    await page.getByRole('button', { name: 'Marcar entregada' }).first().click();
    await waitModal(page, 'Fecha real de entrega');
    await modal(page).locator('input[type="file"]').setInputFiles(`${FILES}/acta-qa.pdf`);
    await shot(page, `${label}-marcar-entregada`);
    await submitModal(page, 'Marcar entregada');
    await page.getByText('Todas las entregas están completas').first().waitFor({ timeout: 10000 });
    await shot(page, `${label}-entregas`);

    await page.getByRole('button', { name: 'Pasar a Producto entregado' }).click();
    await waitModal(page, 'Nuevo estado');
    await shot(page, `${label}-cambiar-estado`);
    await submitModal(page, 'Cambiar estado');

    // Seguimiento con próxima acción.
    await tab(page, 'Seguimientos');
    await page.getByRole('button', { name: 'Nuevo seguimiento' }).click();
    await waitModal(page, 'Próxima acción');
    await modal(page).getByLabel('Tipo').selectOption('Llamada');
    await modal(page).getByLabel('Nota').fill('Se confirmó con la institución la recepción conforme.');
    await modal(page).getByRole('textbox', { name: /^Próxima acción/ }).fill('Presentar factura');
    await modal(page).getByLabel('Fecha de la próxima acción').fill('2026-11-25');
    await shot(page, `${label}-seguimiento`);
    await submitModal(page, 'Registrar seguimiento');
    await shot(page, `${label}-seguimientos`);

    // Los eventos llegan solos al calendario.
    await go(page, '/calendario?mes=2026-11', '[aria-label="Vista del calendario"]');
    await page.locator('[aria-label="Vista del calendario"] button', { hasText: 'Agenda' }).click();
    await page.waitForTimeout(800);
    const agenda = await page.locator('main').innerText();
    if (!agenda.includes(NUMBER)) problems.push(`[${label}] los eventos del contrato no aparecen en la agenda del calendario`);
    await page.getByText(`Próxima acción: Presentar factura (${NUMBER})`).first().scrollIntoViewIfNeeded().catch(() => {});
    await shot(page, `${label}-calendario`);

    // Cerrar.
    await go(page, `/post-adjudicacion/${id}`, 'main h2:visible');
    await page.getByRole('button', { name: 'Cerrar contrato' }).first().click();
    await waitModal(page, 'Al cerrar');
    await modal(page).getByText('Confirmo que el contrato está cumplido').click();
    await shot(page, `${label}-cerrar`);
    await submitModal(page, 'Cerrar contrato');
    await page.locator('main').getByText('Cerrado', { exact: true }).first().waitFor();
    await tab(page, 'Historial');
    await page.waitForTimeout(1500);
    await shot(page, `${label}-historial`);
    return id;
}

async function screens(page, label, id) {
    await go(page, '/post-adjudicacion', 'main table:visible, main ul[aria-label="Contratos"]:visible');
    await shot(page, `${label}-lista`);
    await page.getByRole('button', { name: 'Filtros' }).click();
    await page.waitForTimeout(300);
    await shot(page, `${label}-filtros`);
    await page.getByRole('button', { name: 'Feriados' }).click();
    await waitModal(page, 'Feriados');
    await page.waitForTimeout(1200);
    await shot(page, `${label}-feriados`);
    await closeModal(page);
    await page.getByRole('button', { name: 'Nuevo contrato' }).first().click();
    await waitModal(page, 'Identificación');
    await shot(page, `${label}-crear`);
    await closeModal(page);

    await go(page, `/post-adjudicacion/${id}`, 'main h2:visible');
    await shot(page, `${label}-resumen`);
    for (const name of ['Documentos', 'Entregas', 'Seguimientos', 'Historial']) {
        await tab(page, name);
        await page.waitForTimeout(800);
        await shot(page, `${label}-${name.toLowerCase()}`);
    }
    await page.getByRole('button', { name: /Editar|Completar datos/ }).first().click();
    await waitModal(page, 'Identificación');
    await shot(page, `${label}-modal-editar`);
    await closeModal(page);
    await moreActions(page, 'Cambiar estado');
    await waitModal(page, 'Estado actual');
    await shot(page, `${label}-modal-estado`);
    await closeModal(page);
    await tab(page, 'Entregas');
    await page.getByRole('button', { name: 'Nueva entrega' }).click();
    await waitModal(page, 'Descripción');
    await shot(page, `${label}-modal-entrega`);
    await closeModal(page);
    const deliver = page.getByRole('button', { name: 'Marcar entregada' }).first();
    if (await deliver.count()) {
        await deliver.click();
        await waitModal(page, 'Fecha real');
        await shot(page, `${label}-modal-marcar-entregada`);
        await closeModal(page);
    }
    await tab(page, 'Seguimientos');
    await page.getByRole('button', { name: 'Nuevo seguimiento' }).click();
    await waitModal(page, 'Próxima acción');
    await shot(page, `${label}-modal-seguimiento`);
    await closeModal(page);
    await tab(page, 'Documentos');
    await page.getByRole('button', { name: 'Subir documento' }).click();
    await waitModal(page);
    await shot(page, `${label}-modal-subir`);
    await closeModal(page);
}

/** Contrato de muestra vivo (de la empresa del admin) para las capturas de cada variante. */
async function sampleContract(page) {
    await go(page, '/post-adjudicacion?group=activos', 'main table:visible, main ul[aria-label="Contratos"]:visible');
    const link = page.locator('main tbody tr:visible, main ul[aria-label="Contratos"] > li:visible').first();
    await link.click();
    await page.waitForURL(/\/post-adjudicacion\/\d+/, { timeout: 15000 });
    return page.url().match(/post-adjudicacion\/(\d+)/)[1];
}

async function main() {
    const browser = await chromium.launch();
    let flowId = null;

    for (const viewport of VIEWPORTS) {
        for (const theme of THEMES) {
            const label = `${viewport}-${theme}`;
            const context = await openContext(browser, viewport, theme);
            const page = await context.newPage();
            page.setDefaultTimeout(10000);
            const seen = watchConsole(page, label);

            await step(`${label} acceso`, () => login(page, 'qa.admin@crm-b2g.test'));
            if (label === 'escritorio-claro') flowId = await step(`${label} flujo`, () => realFlow(page, label), 1);
            const id = (await step(`${label} muestra`, () => sampleContract(page))) ?? flowId;
            if (id) await step(`${label} pantallas`, () => screens(page, label, id), 2);
            if (flowId) {
                await step(`${label} ?contrato=`, async () => {
                    await page.goto(`${BASE_URL}/post-adjudicacion?contrato=${flowId}`);
                    await page.waitForURL(new RegExp(`/post-adjudicacion/${flowId}`), { timeout: 15000 });
                });
            }

            problems.push(...seen);
            await context.close();
        }
    }

    // Solo lectura.
    for (const viewport of VIEWPORTS) {
        const context = await openContext(browser, viewport, 'claro');
        const page = await context.newPage();
        const seen = watchConsole(page, `lector-${viewport}`);
        await step('lector acceso', () => login(page, 'qa.lector@crm-b2g.test'));
        await step(`lector ${viewport}`, async () => {
            await go(page, '/post-adjudicacion', 'main table:visible, main ul[aria-label="Contratos"]:visible');
            if (await page.getByRole('button', { name: 'Nuevo contrato' }).count()) problems.push('[lector] ve «Nuevo contrato»');
            await shot(page, `lector-${viewport}-lista`);
            const id = flowId ?? (await sampleContract(page));
            await go(page, `/post-adjudicacion/${id}`, 'main h2:visible');
            for (const name of ['Editar', 'Completar datos', 'Cerrar contrato']) {
                if (await page.getByRole('button', { name, exact: true }).count()) problems.push(`[lector] ve «${name}»`);
            }
            await shot(page, `lector-${viewport}-resumen`);
            await tab(page, 'Documentos');
            await page.waitForTimeout(1200);
            if (await page.getByRole('button', { name: 'Subir documento' }).count()) problems.push('[lector] puede subir documentos');
            if (await page.getByRole('button', { name: /^Firmar y sellar/ }).count()) problems.push('[lector] puede firmar');
            await shot(page, `lector-${viewport}-documentos`);
            await tab(page, 'Entregas');
            if (await page.getByRole('button', { name: 'Nueva entrega' }).count()) problems.push('[lector] puede registrar entregas');
            await page.getByRole('button', { name: 'Feriados' }).count();
        });
        await step(`lector ${viewport} feriados`, async () => {
            await go(page, '/post-adjudicacion', 'main table:visible, main ul[aria-label="Contratos"]:visible');
            await page.getByRole('button', { name: 'Feriados' }).click();
            await waitModal(page, 'Feriados');
            await page.waitForTimeout(1000);
            if (await modal(page).getByRole('button', { name: 'Agregar' }).count()) problems.push('[lector] puede agregar feriados');
            await shot(page, `lector-${viewport}-feriados`);
        });
        problems.push(...seen);
        await context.close();
    }

    await browser.close();
    if (problems.length) {
        console.log(`\n${problems.length} problema(s):`);
        problems.forEach((problem) => console.log(`  ${problem}`));
        process.exit(1);
    }
    console.log('\nPost-adjudicación sin errores de consola ni scroll horizontal.');
}

await main();
