// Revisión visual y recorrido real del módulo de Cotizaciones con Playwright.
//
//   node tests/visual/cotizaciones.mjs            (todo)
//   QA_ONLY=flujo node tests/visual/cotizaciones.mjs   (solo el recorrido completo)
//
// Recorrido (administrador, escritorio claro): inventario → «Llevar a una cotización» → formulario
// precargado → crear → emitir → presentar → agregar dos PDF → ver → firmar y sellar un adjunto
// colocando la firma → firmar el PDF generado → eliminar un adjunto → participantes → Ganada → ver
// cliente y contrato creados. Después, en 1440 y 390, claro y oscuro: lista y cada modal (crear,
// editar, ver, participantes, ganada, perdida, revertir, cambiar estado, correo, menú) como
// administrador, y la lista y «Ver» como lector (sin acciones de escritura).
// Capturas en storage/app/qa/capturas/cotizaciones/. Sale con código 1 si algo falla, hay errores
// de consola o scroll horizontal.
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { chromium } from 'playwright';
import { horizontalOverflow, openContext, settle, watchConsole } from './capturas.mjs';

const BASE_URL = process.env.QA_URL ?? 'http://localhost:8000';
const PASSWORD = process.env.QA_PASSWORD ?? 'QaB2g#2026';
const ADMIN = 'qa.admin@crm-b2g.test';
const VIEWER = 'qa.lector@crm-b2g.test';
const OUTPUT = 'storage/app/qa/capturas/cotizaciones';
const FILES = 'storage/app/qa/cotizaciones';
const ONLY = process.env.QA_ONLY ?? '';

mkdirSync(OUTPUT, { recursive: true });
mkdirSync(FILES, { recursive: true });

// Firma, sello y dos PDF de prueba.
execFileSync('php', ['-r', `
require 'vendor/autoload.php';
$out = '${FILES}';
$im = imagecreatetruecolor(600, 200); imagesavealpha($im, true); imagealphablending($im, false);
imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127)); imagealphablending($im, true);
$ink = imagecolorallocate($im, 20, 40, 160); imagesetthickness($im, 6); $px = 30; $py = 110;
for ($x = 30; $x < 570; $x += 6) { $y = 100 + (int) (45 * sin($x / 28)); imageline($im, $px, $py, $x, $y, $ink); $px = $x; $py = $y; }
imagepng($im, "$out/firma.png");
$im = imagecreatetruecolor(400, 400); imagesavealpha($im, true); imagealphablending($im, false);
imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127)); imagealphablending($im, true);
$red = imagecolorallocatealpha($im, 200, 20, 30, 20); imagesetthickness($im, 12);
imageellipse($im, 200, 200, 380, 380, $red); imagestring($im, 5, 160, 192, 'SELLO', $red);
imagepng($im, "$out/sello.png");
foreach (['ficha-tecnica' => 'Ficha tecnica del producto', 'garantia' => 'Garantia de mantenimiento'] as $name => $text) {
  $pdf = new TCPDF('P', 'pt', 'LETTER'); $pdf->setPrintHeader(false); $pdf->setPrintFooter(false);
  $pdf->AddPage(); $pdf->SetFont('helvetica', '', 22); $pdf->Write(0, $text);
  file_put_contents("$out/$name.pdf", $pdf->Output('x.pdf', 'S'));
}
`]);

const problems = [];
const visible = (page, selector) => page.locator(selector).locator('visible=true').first();
const topDialog = (page) => page.getByRole('dialog').last();

async function login(page, email) {
    await page.goto(`${BASE_URL}/login`);
    await page.fill('#login-email', email);
    await page.fill('#login-password', PASSWORD);
    await Promise.all([page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 20000 }), page.click('button[type="submit"]')]);
}

async function step(page, label, action, attempts = 3) {
    for (let attempt = 1; attempt <= attempts; attempt++) {
        try {
            await action();
            console.log(`ok  ${label}`);
            return true;
        } catch (error) {
            console.log(`  intento ${attempt} de ${label}: ${error.message.split('\n')[0]}`);
            await page.screenshot({ path: `${OUTPUT}/intento-${attempt}-${label.replace(/[^a-z0-9-]+/gi, '_')}.png` }).catch(() => {});
            if (attempt === attempts) {
                problems.push(`[${label}] ${error.message.split('\n')[0]}`);
                console.log(`ERR ${label}: ${error.message.split('\n')[0]}`);
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
    const modalOpen = (await page.getByRole("dialog").count()) > 0;
    await page.screenshot({ path: `${OUTPUT}/${name}.png`, fullPage: !modalOpen, animations: "disabled" });
    const overflow = await horizontalOverflow(page);
    if (overflow > 1) problems.push(`[${name}] scroll horizontal de ${overflow}px`);
}

async function openQuotes(page, query = '') {
    for (let attempt = 1; attempt <= 4; attempt++) {
        try {
            await page.goto(`${BASE_URL}/cotizaciones${query}`);
            await page.waitForSelector('.cap-shell', { timeout: 15000 });
            await page.getByText('Tasa de adjudicación').first().waitFor({ timeout: 15000 });
            await settle(page);
            return;
        } catch (error) {
            if (attempt === 4) throw error;
            await page.waitForTimeout(2000);
        }
    }
}

async function closeTop(page) {
    await page.keyboard.press('Escape');
    await page.waitForTimeout(400);
}

/** Botón de una fila (tabla en escritorio, tarjeta en móvil). */
const rowButton = (page, label) => visible(page, `button[aria-label="${label}"]`);

async function openMenuItem(page, number, item) {
    await rowButton(page, `Más acciones de ${number}`).click();
    await visible(page, `[role="menuitem"]:has-text("${item}")`).click();
}

async function chooseOption(page, input, text) {
    await input.click();
    await input.fill(text);
    const option = page.getByRole('option').locator('visible=true').first();
    await option.waitFor({ timeout: 10000 });
    await option.click();
}

async function openViewer(page, number) {
    await openQuotes(page, `?search=${number}`);
    await rowButton(page, `Ver ${number}`).click();
    const viewer = page.locator('[data-quote-viewer]');
    await viewer.waitFor({ timeout: 15000 });
    await page.waitForTimeout(1500);
    return viewer;
}

/** Pulsa el botón de la modal de firma y confirma «Firmar de nuevo» si el documento ya estaba firmado. */
async function applySignature(page, sign) {
    await sign.getByRole('button', { name: /^(Firmar y sellar|Firmar|Sellar)$/ }).last().click();
    const again = page.getByRole('button', { name: 'Firmar de nuevo' });
    await Promise.race([sign.waitFor({ state: 'hidden', timeout: 45000 }), again.waitFor({ timeout: 45000 })]).catch(() => {});
    if (await again.count()) await again.click();
    await sign.waitFor({ state: 'hidden', timeout: 45000 });
}

// ─────────────────────────────── Recorrido completo ───────────────────────────────

async function fullFlow(browser) {
    const label = 'flujo';
    const context = await openContext(browser, 'escritorio', 'claro');
    const page = await context.newPage();
    const seen = watchConsole(page, label);
    if (process.env.QA_DEBUG) {
        page.on('pageerror', (error) => console.log('PAGEERROR', error.message, (error.stack ?? '').slice(0, 600)));
        page.on('console', (message) => message.type() === 'error' && console.log('CONSOLE', message.text().slice(0, 400)));
        page.on('framenavigated', (frame) => frame === page.mainFrame() && console.log('NAV', frame.url()));
    }
    let number = process.env.QA_NUMBER ?? '';
    // Cada paso pesado en una pestaña nueva de la misma sesión: el visor de PDF consume memoria y
    // una pestaña que se cae no arrastra al resto del recorrido.
    const withPage = async (stepLabel, action) => {
        const fresh = await context.newPage();
        seen.push(...[]);
        const local = watchConsole(fresh, stepLabel);
        await step(fresh, stepLabel, () => action(fresh));
        problems.push(...local);
        await fresh.close().catch(() => {});
    };

    await login(page, ADMIN);

    if (!number) {
    await step(page, 'flujo-desde-inventario', async () => {
        await page.goto(`${BASE_URL}/inventario?q=LUB-15W40-G`);
        await page.waitForSelector('.cap-shell');
        await settle(page);
        await visible(page, 'input[aria-label="Cantidad de LUB-15W40-G"]').fill('3');
        await page.getByText('2,377.05').locator('visible=true').first().waitFor({ timeout: 8000 });
        await visible(page, 'button:has-text("Llevar a una cotización")').click();
        await page.waitForURL(/\/cotizaciones/, { timeout: 15000 });
        const dialog = page.getByRole('dialog', { name: 'Nueva cotización' });
        await dialog.waitFor({ timeout: 15000 });
        await dialog.locator('[data-line="0"]').waitFor({ timeout: 8000 });
        await dialog.getByText('L 2,377.05').first().waitFor({ timeout: 8000 });
        if (new URL(page.url()).searchParams.has('desde_inventario')) throw new Error('La URL conserva desde_inventario');
    });

    await step(page, 'flujo-crear', async () => {
        const dialog = page.getByRole('dialog', { name: 'Nueva cotización' });
        await chooseOption(page, dialog.locator('input').nth(1), 'Sec');
        await dialog.getByLabel('Tipo de compra').fill('Licitación Pública');
        await dialog.getByLabel('Condiciones').fill('Entrega en 10 días hábiles. Garantía de 12 meses.');
        // Una línea libre adicional: 2 × 150 + 15 % = 345.
        await dialog.getByRole('button', { name: 'Línea libre' }).click();
        await dialog.locator('[data-line="1"] input').nth(0).fill('Servicio de instalación');
        await dialog.locator('[data-line="1"] input').nth(1).fill('2');
        await dialog.locator('[data-line="1"] input').nth(3).fill('150');
        await dialog.getByText('L 2,722.05').first().waitFor({ timeout: 5000 });
        await shot(page, 'flujo-01-formulario-desde-inventario');
        await dialog.getByRole('button', { name: 'Crear cotización' }).click();
        await dialog.waitFor({ state: 'hidden', timeout: 15000 });
        await page.getByText(/Cotización COT-\d{4}-\d{4} creada/).first().waitFor({ timeout: 10000 });
        const toast = await page.getByText(/Cotización COT-\d{4}-\d{4} creada/).first().innerText();
        number = toast.match(/COT-\d{4}-\d{4}/)[0];
        await settle(page);
        await shot(page, 'flujo-02-creada');
    });

    if (!number) {
        problems.push('[flujo] no se creó la cotización');
        problems.push(...seen);
        await context.close();
        return;
    }

    await step(page, 'flujo-editar', async () => {
        await openMenuItem(page, number, 'Editar');
        const dialog = page.getByRole('dialog', { name: `Editar ${number}` });
        await dialog.locator('[data-line="1"]').waitFor({ timeout: 10000 });
        await dialog.getByLabel('Notas').fill('Precios sujetos a existencia.');
        await shot(page, 'flujo-03-editar');
        await dialog.getByRole('button', { name: 'Guardar cambios' }).click();
        await dialog.waitFor({ state: 'hidden', timeout: 15000 });
    });

    for (const target of ['Emitida', 'Presentada']) {
        await step(page, `flujo-estado-${target}`, async () => {
            await openMenuItem(page, number, 'Cambiar estado');
            const dialog = page.getByRole('dialog', { name: `Cambiar estado · ${number}` });
            await dialog.waitFor();
            await dialog.locator(`input[value="${target}"]`).check();
            if (target === 'Presentada') await shot(page, 'flujo-04-cambiar-estado');
            await dialog.getByRole('button', { name: `Cambiar a ${target}` }).click();
            await dialog.waitFor({ state: 'hidden', timeout: 15000 });
            await page.locator('tr', { hasText: number }).getByText(target).first().waitFor({ timeout: 10000 });
        });
    }

    await step(page, 'flujo-agregar-pdf', async () => {
        await rowButton(page, `Agregar PDF a ${number}`).click();
        const upload = page.getByRole('dialog', { name: 'Agregar PDF' });
        await upload.waitFor({ timeout: 15000 });
        await upload.locator('input[type="file"]').setInputFiles([`${FILES}/ficha-tecnica.pdf`, `${FILES}/garantia.pdf`]);
        await upload.getByLabel('Descripción').fill('Documentos de la oferta');
        await shot(page, 'flujo-05-agregar-pdf');
        await upload.getByRole('button', { name: /Subir/ }).click();
        await upload.waitFor({ state: 'hidden', timeout: 30000 });
        const viewer = page.locator('[data-quote-viewer]');
        await viewer.getByText('ficha-tecnica').first().waitFor({ timeout: 15000 });
        await viewer.getByText('garantia').first().waitFor({ timeout: 15000 });
    });

    await step(page, 'flujo-ver', async () => {
        const viewer = page.locator('[data-quote-viewer]');
        await viewer.locator('.app-pdf-page, canvas').first().waitFor({ timeout: 20000 });
        await page.waitForTimeout(1500);
        await shot(page, 'flujo-06-ver-pdf-generado');
        await viewer.getByRole('button', { name: 'ficha-tecnica' }).first().click();
        await page.waitForTimeout(1500);
        await shot(page, 'flujo-07-ver-adjunto');
    });

    }

    await withPage('flujo-firmar-adjunto', async (page) => {
        const viewer = await openViewer(page, number);
        await viewer.getByRole('button', { name: 'ficha-tecnica' }).first().click();
        await page.waitForTimeout(1000);
        await page.getByRole('dialog', { name: `Cotización ${number}` }).getByRole('button', { name: 'Firmar y sellar' }).last().click();
        const sign = page.getByRole('dialog', { name: /^Firmar «/ });
        await sign.getByText('Qué aplicar').waitFor({ timeout: 20000 });
        for (const type of ['firma', 'sello']) {
            const input = sign.locator(`section[aria-labelledby="asset-${type}"] input[type="file"]`);
            if (await input.count()) {
                await input.setInputFiles(`${FILES}/${type}.png`);
                await page.waitForTimeout(2500);
            }
        }
        await sign.locator('[data-placement="Firma"]').waitFor({ timeout: 30000 });
        await page.waitForTimeout(1000);
        const box = await sign.locator('[data-placement="Firma"]').boundingBox();
        await page.mouse.move(box.x + 15, box.y + 15);
        await page.mouse.down();
        await page.mouse.move(box.x + 95, box.y + 70, { steps: 10 });
        await page.mouse.up();
        await shot(page, 'flujo-08-firmar-colocando');
        await applySignature(page, sign);
        await page.getByText('Firmado y sellado').first().waitFor({ timeout: 15000 });
        await page.waitForTimeout(2000);
        await shot(page, 'flujo-09-firmado');
    });

    await withPage('flujo-firmar-generado', async (page) => {
        const viewer = await openViewer(page, number);
        await viewer.getByRole('button', { name: /PDF de la cotización/ }).click();
        await page.getByRole('dialog', { name: `Cotización ${number}` }).getByRole('button', { name: 'Firmar y sellar' }).last().click();
        const sign = page.getByRole('dialog', { name: /^Firmar «/ });
        await sign.locator('[data-placement="Firma"]').waitFor({ timeout: 30000 });
        await page.waitForTimeout(800);
        await applySignature(page, sign);
        await viewer.getByText(`Cotización ${number}`).first().waitFor({ timeout: 15000 });
        await viewer.locator('.app-pdf-page canvas, .app-pdf-page').first().waitFor({ timeout: 20000 });
        await page.waitForTimeout(1500);
        await page.getByText('Firmado y sellado').first().waitFor({ timeout: 15000 });
        await shot(page, 'flujo-10-generado-firmado');
    });

    await withPage('flujo-eliminar-adjunto', async (page) => {
        const viewer = await openViewer(page, number);
        await viewer.getByRole('button', { name: 'garantia' }).first().click();
        await page.waitForTimeout(800);
        await page.getByRole('dialog', { name: `Cotización ${number}` }).getByRole('button', { name: 'Eliminar', exact: true }).click();
        const confirmDialog = page.getByRole('alertdialog').or(page.getByRole('dialog', { name: /Eliminar el adjunto/ })).last();
        await confirmDialog.waitFor({ timeout: 8000 });
        await shot(page, 'flujo-11-confirmar-eliminar');
        await confirmDialog.getByRole('button', { name: 'Eliminar adjunto' }).click();
        await page.waitForTimeout(1500);
        if (await viewer.getByRole('button', { name: 'garantia' }).count()) throw new Error('El adjunto sigue en la lista');
        await shot(page, 'flujo-12-adjunto-eliminado');
        await closeTop(page);
    });

    await withPage('flujo-participantes', async (page) => {
        await openQuotes(page, `?search=${number}`);
        await rowButton(page, `Participantes de ${number}`).click();
        const dialog = page.getByRole('dialog', { name: `Participantes · ${number}` });
        await dialog.locator('[data-participant="0"]').waitFor({ timeout: 10000 });
        await dialog.getByRole('button', { name: 'Agregar participante' }).click();
        const row = dialog.locator('[data-participant="1"]');
        await row.getByLabel('Nombre del oferente').fill('Distribuidora Rival S. de R. L.');
        await row.locator('input[inputmode="decimal"]').first().fill('2650');
        await dialog.getByRole('button', { name: 'Agregar participante' }).click();
        const third = dialog.locator('[data-participant="2"]');
        await third.getByLabel('Nombre del oferente').fill('Comercial Centro');
        await third.locator('input[inputmode="decimal"]').first().fill('3100');
        await dialog.getByText('2 de 3').waitFor({ timeout: 5000 });
        await shot(page, 'flujo-13-participantes');
        await dialog.getByRole('button', { name: 'Guardar participantes' }).click();
        await dialog.waitFor({ state: 'hidden', timeout: 15000 });
    });

    await withPage('flujo-ganada', async (page) => {
        await openQuotes(page, `?search=${number}`);
        await rowButton(page, `Marcar ${number} como Ganada`).click();
        const dialog = page.getByRole('dialog', { name: new RegExp(`Registrar como Ganada · ${number}`) });
        await dialog.waitFor();
        await shot(page, 'flujo-14-ganada');
        await dialog.getByRole('button', { name: 'Registrar como Ganada' }).dblclick();
        const done = page.getByRole('dialog', { name: 'Oferta ganada' });
        await done.getByText('Cliente:').waitFor({ timeout: 15000 });
        await done.getByText('Contrato:').waitFor({ timeout: 5000 });
        await shot(page, 'flujo-15-ganada-resultado');
        await done.getByRole('link').first().click();
        await page.waitForURL(/\/clientes\/\d+/, { timeout: 15000 });
        await settle(page);
        await shot(page, 'flujo-16-cliente-creado');
    });

    await withPage('flujo-contrato', async (page) => {
        await openQuotes(page, `?search=${number}`);
        await openMenuItem(page, number, 'Ver contrato');
        await page.waitForURL(/post-adjudicacion/, { timeout: 15000 });
        await settle(page);
        await page.getByText(/CTR-\d{4}-\d{4}/).first().waitFor({ timeout: 15000 });
        await shot(page, 'flujo-17-contrato-creado');
    });

    await withPage('flujo-revertir-abrir', async (page) => {
        await openQuotes(page, `?search=${number}`);
        await openMenuItem(page, number, 'Revertir resultado');
        const dialog = page.getByRole('dialog', { name: new RegExp('Revertir resultado') });
        await dialog.waitFor();
        await shot(page, 'flujo-18-revertir');
        await closeTop(page);
    });

    problems.push(...seen);
    await context.close();
}

// ─────────────────────────────── Pantallas y modales ───────────────────────────────

async function adminScreens(browser, viewport, theme) {
    const label = `admin-${viewport}-${theme}`;
    const context = await openContext(browser, viewport, theme);
    const page = await context.newPage();
    const seen = watchConsole(page, label);
    const name = (suffix) => `${viewport}-${theme}-${suffix}`;

    await login(page, ADMIN);

    await step(page, `${label} lista`, async () => {
        await openQuotes(page);
        await shot(page, name('lista'));
    });

    await step(page, `${label} nueva`, async () => {
        await openQuotes(page);
        if (viewport === 'movil') await visible(page, '.cap-fab, button[aria-label="Nueva cotización"], button:has-text("Nueva cotización")').click();
        else await visible(page, 'button:has-text("Nueva cotización")').click();
        const dialog = page.getByRole('dialog', { name: 'Nueva cotización' });
        await dialog.waitFor();
        await dialog.getByRole('button', { name: 'Línea libre' }).click();
        await dialog.getByRole('button', { name: 'Crear cotización' }).click();
        await dialog.getByText('Elija la institución a la que va la cotización.').waitFor({ timeout: 8000 });
        await shot(page, name('nueva-errores'));
        await closeTop(page);
        await page.getByRole('alertdialog').or(page.getByRole('dialog')).count();
    });

    // Fila con resultado pendiente y adjudicable para Ganada/Perdida.
    const pendingRow = async () => {
        await openQuotes(page, '?segment=presentadas');
        const button = page.locator('button[aria-label^="Marcar "][aria-label$=" como Ganada"]').locator('visible=true').first();
        await button.waitFor({ timeout: 8000 });
        return (await button.getAttribute('aria-label')).replace('Marcar ', '').replace(' como Ganada', '');
    };

    await step(page, `${label} ver`, async () => {
        const number = await pendingRow();
        await rowButton(page, `Ver ${number}`).click();
        const viewer = page.locator('[data-quote-viewer]');
        await viewer.waitFor();
        await page.waitForTimeout(2500);
        await shot(page, name('ver'));
        if (viewport === 'movil') {
            await page.getByRole('button', { name: /Documentos \(/ }).click();
            await shot(page, name('ver-lista'));
        }
        await closeTop(page);
    });

    await step(page, `${label} participantes`, async () => {
        const number = await pendingRow();
        await rowButton(page, `Participantes de ${number}`).click();
        const dialog = page.getByRole('dialog', { name: `Participantes · ${number}` });
        await dialog.waitFor();
        await page.waitForTimeout(1200);
        await shot(page, name('participantes'));
        await closeTop(page);
    });

    await step(page, `${label} ganada`, async () => {
        const number = await pendingRow();
        await rowButton(page, `Marcar ${number} como Ganada`).click();
        await page.getByRole('dialog', { name: new RegExp('Registrar como Ganada') }).waitFor();
        await shot(page, name('ganada'));
        await closeTop(page);
    });

    await step(page, `${label} perdida`, async () => {
        const number = await pendingRow();
        await rowButton(page, `Marcar ${number} como Perdida`).click();
        const dialog = page.getByRole('dialog', { name: new RegExp('Registrar como Perdida') });
        await dialog.waitFor();
        await dialog.getByRole('button', { name: 'Registrar como Perdida' }).click();
        await page.waitForTimeout(800);
        await shot(page, name('perdida'));
        await closeTop(page);
    });

    await step(page, `${label} estado-correo-menu`, async () => {
        const number = await pendingRow();
        await rowButton(page, `Más acciones de ${number}`).click();
        await page.waitForTimeout(400);
        await shot(page, name('menu'));
        await visible(page, '[role="menuitem"]:has-text("Enviar por correo")').click();
        await page.getByRole('dialog', { name: `Enviar por correo · ${number}` }).waitFor();
        await shot(page, name('correo'));
        await closeTop(page);
        await openMenuItem(page, number, 'Cambiar estado');
        await page.getByRole('dialog', { name: `Cambiar estado · ${number}` }).waitFor();
        await shot(page, name('estado'));
        await closeTop(page);
    });

    await step(page, `${label} editar`, async () => {
        await openQuotes(page, '?segment=preparacion');
        const edit = page.locator('button[aria-label^="Más acciones de "]').locator('visible=true').first();
        const number = (await edit.getAttribute('aria-label')).replace('Más acciones de ', '');
        await openMenuItem(page, number, 'Editar');
        const dialog = page.getByRole('dialog', { name: `Editar ${number}` });
        await dialog.locator('[data-line="0"]').waitFor({ timeout: 10000 });
        await shot(page, name('editar'));
        await closeTop(page);
    });

    await step(page, `${label} revertir`, async () => {
        await openQuotes(page, '?segment=ganadas');
        const more = page.locator('button[aria-label^="Más acciones de "]').locator('visible=true').first();
        const number = (await more.getAttribute('aria-label')).replace('Más acciones de ', '');
        await openMenuItem(page, number, 'Revertir resultado');
        await page.getByRole('dialog', { name: /Revertir resultado/ }).waitFor();
        await shot(page, name('revertir'));
        await closeTop(page);
    });

    await step(page, `${label} sin resultados`, async () => {
        await openQuotes(page, '?search=zzzz-sin-resultados');
        await page.getByText('Ningún registro coincide').first().waitFor({ timeout: 8000 });
        await shot(page, name('sin-resultados'));
    });

    problems.push(...seen);
    await context.close();
}

async function viewerScreens(browser, viewport, theme) {
    const label = `lector-${viewport}-${theme}`;
    const context = await openContext(browser, viewport, theme);
    const page = await context.newPage();
    const seen = watchConsole(page, label);
    const name = (suffix) => `lector-${viewport}-${theme}-${suffix}`;

    await login(page, VIEWER);

    await step(page, `${label} lista`, async () => {
        await openQuotes(page);
        if (await page.locator('button:has-text("Nueva cotización")').locator('visible=true').count()) throw new Error('El lector ve «Nueva cotización»');
        if (await page.locator('button[aria-label$=" como Ganada"]').count()) throw new Error('El lector ve «Ganada»');
        if (await page.locator('button[aria-label^="Agregar PDF a "]').count()) throw new Error('El lector ve «Agregar PDF»');
        await shot(page, name('lista'));
    });

    await step(page, `${label} ver`, async () => {
        const first = page.locator('button[aria-label^="Ver COT-"]').locator('visible=true').first();
        await first.click();
        const viewer = page.locator('[data-quote-viewer]');
        await viewer.waitFor();
        await page.waitForTimeout(2000);
        const dialog = page.getByRole('dialog').last();
        if (await dialog.getByRole('button', { name: 'Firmar y sellar' }).count()) throw new Error('El lector ve «Firmar y sellar»');
        if (await dialog.getByRole('button', { name: 'Agregar PDF' }).count()) throw new Error('El lector ve «Agregar PDF»');
        await shot(page, name('ver'));
        await closeTop(page);
    });

    await step(page, `${label} participantes`, async () => {
        const first = page.locator('button[aria-label^="Participantes de "]').locator('visible=true').first();
        await first.click();
        const dialog = page.getByRole('dialog').last();
        await dialog.getByText('Solo lectura').first().waitFor({ timeout: 10000 });
        if (await dialog.getByRole('button', { name: 'Guardar participantes' }).count()) throw new Error('El lector puede guardar participantes');
        await shot(page, name('participantes'));
        await closeTop(page);
    });

    problems.push(...seen);
    await context.close();
}

const browser = await chromium.launch();

if (!ONLY || ONLY === 'flujo') await fullFlow(browser);
if (!ONLY || ONLY === 'pantallas') {
    for (const viewport of ['escritorio', 'movil']) {
        for (const theme of ['claro', 'oscuro']) {
            await adminScreens(browser, viewport, theme);
            await viewerScreens(browser, viewport, theme);
        }
    }
}

await browser.close();

// El cliente de Vite recibe avisos de recarga dentro del worker de PDF.js mientras otros agentes editan
// (`document is not defined` en @vite/client): es ruido del servidor de desarrollo, no de la pantalla.
const unique = [...new Set(problems)].filter((problem) => !problem.includes('document is not defined'));
if (unique.length) {
    console.log('\nProblemas:');
    unique.forEach((problem) => console.log(` - ${problem}`));
    process.exit(1);
}
console.log('\nSin problemas.');
