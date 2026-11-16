// Revisión visual y flujo real de «Mi firma y sello» (/firmas) con Playwright.
//
//   node tests/visual/firmas.mjs
//
// Hace, como administrador: quitar firma y sello; DIBUJAR la firma con el ratón en «Crear firma»,
// probar «Escribir» con un estilo manuscrito y GENERAR el sello; firmar un PDF suelto con esa firma
// dibujada (escritorio, arrastre con ratón); en móvil, dibujar la firma con eventos táctiles reales y
// firmar el documento desde «Elegir del sistema»; el teléfono en horizontal y una foto con «Quitar el
// fondo blanco». Descarga cada PDF firmado y lo convierte a PNG con pdftoppm para revisarlo. Como
// lector, comprueba que no puede firmar pero sí preparar su firma. Capturas en claro y oscuro, 1440 × 900 y 390 × 844, en
// storage/app/qa/capturas/firmas/. Termina con código 1 si hay errores de consola o scroll horizontal.
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { chromium } from 'playwright';

const BASE_URL = process.env.QA_URL ?? 'http://localhost:8000';
const PASSWORD = process.env.QA_PASSWORD ?? 'QaB2g#2026';
const ADMIN = 'qa.admin@crm-b2g.test';
const VIEWER = 'qa.lector@crm-b2g.test';
const OUTPUT = 'storage/app/qa/capturas/firmas';
const FILES = 'storage/app/qa/firmas';
const VIEWPORTS = { escritorio: { width: 1440, height: 900 }, movil: { width: 390, height: 844 }, horizontal: { width: 844, height: 390 } };

mkdirSync(OUTPUT, { recursive: true });
mkdirSync(FILES, { recursive: true });

// Imágenes y PDF de prueba: firma y sello PNG con transparencia y un PDF de 3 páginas (carta, A4 apaisado, oficio).
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
imageellipse($im, 200, 200, 380, 380, $red); imageellipse($im, 200, 200, 290, 290, $red); imagestring($im, 5, 160, 192, 'SELLO', $red);
imagepng($im, "$out/sello.png");
$im = imagecreatetruecolor(900, 420); imagefill($im, 0, 0, imagecolorallocate($im, 246, 244, 238)); $pen = imagecolorallocate($im, 25, 40, 120); imagesetthickness($im, 7);
$px = 80; $py = 230; for ($x = 80; $x < 820; $x += 5) { $y = 220 + (int) (70 * sin($x / 40)); imageline($im, $px, $py, $x, $y, $pen); $px = $x; $py = $y; }
imagejpeg($im, "$out/firma-foto.jpg", 85);
$pdf = new TCPDF('P', 'pt'); $pdf->setPrintHeader(false); $pdf->setPrintFooter(false);
foreach ([[612, 792], [841.89, 595.28], [612, 1008]] as $i => [$w, $h]) {
  $pdf->AddPage($w > $h ? 'L' : 'P', [$w, $h]); $pdf->SetFont('helvetica', '', 28);
  $pdf->Write(0, 'Pagina ' . ($i + 1) . ' de prueba');
  $pdf->SetDrawColor(180, 180, 180); for ($g = 10; $g < 100; $g += 10) { $pdf->Line($w * $g / 100, 0, $w * $g / 100, $h); $pdf->Line(0, $h * $g / 100, $w, $h * $g / 100); }
}
file_put_contents("$out/oferta-qa.pdf", $pdf->Output('x.pdf', 'S'));
`]);

const problems = [];

async function context(browser, viewport, theme) {
    const ctx = await browser.newContext({ viewport: VIEWPORTS[viewport], deviceScaleFactor: 1, isMobile: viewport !== 'escritorio', hasTouch: viewport !== 'escritorio', bypassCSP: true, locale: 'es-HN', timezoneId: 'America/Tegucigalpa', acceptDownloads: true });
    await ctx.addInitScript((value) => localStorage.setItem('theme', value), theme === 'oscuro' ? 'dark' : 'light');
    return ctx;
}

/** Inicia sesión por HTTP (la pantalla de acceso la edita otro agente y su intro retrasa el formulario). */
async function login(page, email) {
    const request = page.context().request;
    await request.get(`${BASE_URL}/login`);
    const xsrf = decodeURIComponent((await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN')?.value ?? '');
    const response = await request.post(`${BASE_URL}/login`, { headers: { 'X-XSRF-TOKEN': xsrf, Accept: 'text/html' }, form: { email, password: PASSWORD }, maxRedirects: 0 });
    if (response.status() >= 400) throw new Error(`acceso ${response.status()}`);
}

/** Abre /firmas y espera a que la página esté estable (Vite puede recargar mientras otros agentes editan). */
async function openPage(page) {
    for (let attempt = 1; attempt <= 4; attempt += 1) {
        try {
            await page.goto(`${BASE_URL}/firmas`);
            await page.waitForSelector('#mis-activos', { timeout: 15000 });
            await page.waitForLoadState('networkidle').catch(() => {});
            await page.evaluate(() => document.fonts.ready);
            await page.waitForTimeout(800);
            return;
        } catch (error) {
            if (attempt === 4) throw error;
            await page.waitForTimeout(2000);
        }
    }
}

async function shot(page, name, fullPage = true) {
    await page.waitForTimeout(400);
    // Página en blanco = Vite recargó a mitad: se reintenta la fase.
    if (!(await page.locator('#mis-activos').count()) && !(await page.getByRole('dialog').count())) throw new Error(`página en blanco en ${name}`);
    await page.screenshot({ path: `${OUTPUT}/${name}.png`, fullPage, animations: 'disabled' });
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    if (overflow > 1) problems.push(`[${name}] scroll horizontal de ${overflow}px`);
    console.log(`ok  ${name}`);
}

async function chooseSystemDocument(page) {
    const segment = page.getByRole('button', { name: 'Elegir del sistema' });
    if (await segment.count()) await segment.click();
    const combo = page.locator('section[aria-labelledby="firmar-documento"] input').first();
    await combo.click();
    await combo.fill('Oferta QA');
    await page.getByRole('option', { name: /Oferta QA firmada/ }).first().click();
    await page.getByRole('button', { name: 'Colocar firma y sello' }).click();
}

async function removeAssets(page) {
    for (const type of ['firma', 'sello']) {
        const card = page.locator(`section[aria-labelledby="asset-${type}"]`);
        if (await card.getByRole('button', { name: 'Quitar' }).count()) {
            await card.getByRole('button', { name: 'Quitar' }).click();
            await page.getByRole('alertdialog').or(page.getByRole('dialog')).getByRole('button', { name: /^Quitar/ }).last().click();
            await card.getByText('Sin cargar').waitFor({ timeout: 15000 });
        }
    }
}

/** Abre «Crear firma» o «Crear sello» desde su tarjeta. */
async function openCreator(page, type) {
    await page.locator(`section[aria-labelledby="asset-${type}"] [data-create-asset]`).click();
    const dialog = page.getByRole('dialog', { name: type === 'firma' ? 'Crear mi firma' : 'Crear mi sello' });
    await dialog.waitFor();
    await page.waitForTimeout(700);
    return dialog;
}

/** Trazos de una firma: dos curvas y un subrayado, con velocidad variable. */
function signaturePath(box) {
    const strokes = [[], [], []];
    for (let i = 0; i <= 70; i += 1) {
        const t = i / 70;
        strokes[0].push({ x: box.x + box.width * (0.12 + 0.42 * t), y: box.y + box.height * (0.55 - 0.25 * Math.sin(t * Math.PI * 3) * (1 - t * 0.5)) });
    }
    for (let i = 0; i <= 50; i += 1) {
        const t = i / 50;
        strokes[1].push({ x: box.x + box.width * (0.5 + 0.32 * t), y: box.y + box.height * (0.5 + 0.18 * Math.cos(t * Math.PI * 4)) });
    }
    for (let i = 0; i <= 25; i += 1) {
        const t = (i / 25) ** 0.6;
        strokes[2].push({ x: box.x + box.width * (0.2 + 0.65 * t), y: box.y + box.height * (0.78 - 0.04 * t) });
    }
    return strokes;
}

/** Caja del lienzo cuando ya terminó la animación de la pestaña (dos lecturas iguales). */
async function stablePad(dialog) {
    const pad = dialog.locator('[data-signature-pad]');
    await pad.waitFor();
    let previous = null;
    for (let i = 0; i < 20; i += 1) {
        const box = await pad.boundingBox();
        if (previous && box && Math.abs(box.x - previous.x) < 0.5 && Math.abs(box.y - previous.y) < 0.5 && box.width > 100) return box;
        previous = box;
        await dialog.page().waitForTimeout(150);
    }
    return previous;
}

/** Dibuja la firma con el ratón (pointer events de ratón reales). */
async function drawWithMouse(page, dialog) {
    const box = await stablePad(dialog);
    for (const stroke of signaturePath(box)) {
        await page.mouse.move(stroke[0].x, stroke[0].y);
        await page.mouse.down();
        for (const [index, point] of stroke.entries()) await page.mouse.move(point.x, point.y, { steps: 1 + (index % 3) });
        await page.mouse.up();
    }
}

/** Dibuja la firma con eventos táctiles de Chromium (CDP), como un dedo. */
async function drawWithTouch(cdp, dialog) {
    const box = await stablePad(dialog);
    for (const stroke of signaturePath(box)) {
        await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [stroke[0]] });
        for (const point of stroke.slice(1)) await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [point] });
        await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
    }
}

async function saveCreator(page, dialog, type) {
    const [response] = await Promise.all([
        page.waitForResponse((r) => r.url().endsWith('/firmas/activos') && r.request().method() === 'POST', { timeout: 30000 }),
        dialog.locator('[data-save-asset]').click(),
    ]);
    if (response.status() >= 400) throw new Error(`guardar ${type} respondió ${response.status()}`);
    await dialog.waitFor({ state: 'hidden', timeout: 15000 });
    await page.locator(`section[aria-labelledby="asset-${type}"]`).getByText('Lista para firmar').waitFor({ timeout: 20000 });
}

/** Guarda la imagen activa tal como la tiene el servidor (PNG transparente y recortado) para mirarla. */
async function saveAssetImage(page, type, name) {
    const image = await page.context().request.get(`${BASE_URL}/firmas/activos/${type}/imagen`);
    if (!image.ok() || image.headers()['content-type'] !== 'image/png') throw new Error(`imagen ${type}: ${image.status()} ${image.headers()['content-type']}`);
    writeFileSync(`${OUTPUT}/${name}.png`, await image.body());
}

/** Espera a que la modal pinte la página objetivo con la firma encima. */
async function waitForModal(page) {
    const dialog = page.getByRole('dialog');
    await dialog.waitFor();
    await dialog.locator('[data-placement="Firma"]').waitFor({ timeout: 30000 });
    await page.waitForTimeout(1500);
    return dialog;
}

/** Posición de la caja en % de su página, leída del DOM. */
async function placementOf(page, label) {
    return page.locator(`[data-placement="${label}"]`).evaluate((element) => {
        const box = element.getBoundingClientRect();
        const sheet = element.closest('.app-pdf-page').getBoundingClientRect();
        return {
            x: ((box.left - sheet.left) / sheet.width) * 100,
            y: ((box.top - sheet.top) / sheet.height) * 100,
            width: (box.width / sheet.width) * 100,
            height: (box.height / sheet.height) * 100,
            page: Number(element.closest('.app-pdf-page').dataset.page),
        };
    });
}

async function sheetBox(page, label) {
    return page.locator(`[data-placement="${label}"]`).evaluate((element) => {
        const rect = element.closest('.app-pdf-page').getBoundingClientRect();
        return { x: rect.left, y: rect.top, width: rect.width, height: rect.height };
    });
}

/** Arrastre con ratón: lleva la esquina superior izquierda de la caja a (fx, fy) de la página y la agranda. */
async function dragWithMouse(page, label, fx, fy, grow) {
    const target = page.locator(`[data-placement="${label}"]`);
    await target.scrollIntoViewIfNeeded();
    const box = await target.boundingBox();
    const sheet = await sheetBox(page, label);
    const grabX = box.x + 12;
    const grabY = box.y + 12;
    await page.mouse.move(grabX, grabY);
    await page.mouse.down();
    await page.mouse.move(sheet.x + sheet.width * fx + 12, sheet.y + sheet.height * fy + 12, { steps: 12 });
    await page.mouse.up();
    const handle = target.locator('[data-resize-handle]');
    const h = await handle.boundingBox();
    await page.mouse.move(h.x + h.width / 2, h.y + h.height / 2);
    await page.mouse.down();
    await page.mouse.move(h.x + h.width / 2 + grow, h.y + h.height / 2, { steps: 8 });
    await page.mouse.up();
}

/** Arrastre táctil real (eventos touch de Chromium por CDP). */
async function dragWithTouch(page, cdp, label, fx, fy) {
    const target = page.locator(`[data-placement="${label}"]`);
    await target.scrollIntoViewIfNeeded();
    const box = await target.boundingBox();
    const sheet = await sheetBox(page, label);
    const start = { x: box.x + 10, y: box.y + 10 };
    const end = { x: sheet.x + sheet.width * fx + 10, y: sheet.y + sheet.height * fy + 10 };
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [start] });
    for (let step = 1; step <= 12; step += 1) {
        const point = { x: start.x + ((end.x - start.x) * step) / 12, y: start.y + ((end.y - start.y) * step) / 12 };
        await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [point] });
    }
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
}

/** Descarga el PDF firmado y rasteriza la página firmada para revisarla a ojo. */
async function saveResult(page, response, name, pageNumber) {
    const file = await page.request.get(`${BASE_URL}${new URL(response.application.downloadUrl).pathname}`);
    if (!file.ok()) throw new Error(`descarga ${file.status()}`);
    const pdf = `${FILES}/${name}.pdf`;
    writeFileSync(pdf, await file.body());
    const info = execFileSync('pdfinfo', ['-f', '1', '-l', '3', pdf]).toString();
    console.log(info.split('\n').filter((line) => /Pages|size/.test(line)).join('\n'));
    execFileSync('pdftoppm', ['-f', String(pageNumber), '-l', String(pageNumber), '-r', '50', '-png', '-singlefile', pdf, `${OUTPUT}/${name}`]);
    return pdf;
}

const browser = await chromium.launch();
const placements = {};

/**
 * Ejecuta una fase con su propio contexto y la reintenta si Vite recarga la página a mitad
 * (otros agentes editan resources/js). Solo cuentan los errores de consola del intento que termina.
 */
async function phase(label, viewport, theme, run) {
    for (let attempt = 1; attempt <= 3; attempt += 1) {
        const ctx = await context(browser, viewport, theme);
        const page = await ctx.newPage();
        const seen = [];
        page.on('console', (message) => {
            if (message.type() === 'error') seen.push(`[${label}] consola: ${message.text()}`);
        });
        page.on('pageerror', (error) => seen.push(`[${label}] excepción: ${error.message}`));
        try {
            await run(page, ctx);
            problems.push(...seen);
            await ctx.close();
            return;
        } catch (error) {
            await ctx.close();
            if (attempt === 3) problems.push(`[${label}] ${error.message.split('\n')[0]}`, ...seen);
            else console.log(`reintento ${label}: ${error.message.split('\n')[0]}`);
        }
    }
}

// 1. Escritorio, claro: flujo completo con ratón.
await phase('escritorio-claro', 'escritorio', 'claro', async (page) => {
    await login(page, ADMIN);
    await openPage(page);
    await removeAssets(page);
    await shot(page, 'pagina-vacia-escritorio-claro');

    // Firma: escribir con un estilo y luego dibujar con el ratón; se guarda la dibujada.
    let creator = await openCreator(page, 'firma');
    await shot(page, 'crear-firma-vacia-escritorio-claro', false);
    await creator.getByRole('tab', { name: 'Escribir' }).click();
    await creator.locator('[data-font="great-vibes"]').waitFor({ timeout: 15000 });
    await creator.getByLabel('Tu nombre como firmas').fill('Mariela Zelaya Cruz');
    await creator.locator('[data-font="herr-von-muellerhoff"]').click();
    await page.waitForTimeout(700);
    await shot(page, 'crear-firma-escrita-escritorio-claro', false);
    await creator.getByRole('button', { name: 'Sobre un documento' }).click();
    await page.waitForTimeout(500);
    await shot(page, 'crear-firma-escrita-documento-escritorio-claro', false);
    await creator.getByRole('button', { name: 'Sobre papel' }).click();
    await creator.getByRole('tab', { name: 'Dibujar' }).click();
    await drawWithMouse(page, creator);
    await page.waitForTimeout(600);
    await shot(page, 'crear-firma-dibujada-escritorio-claro', false);
    await saveCreator(page, creator, 'firma');
    await saveAssetImage(page, 'firma', 'activo-firma-dibujada-raton');

    // Sello: generador con el nombre y el RTN de la empresa activa.
    creator = await openCreator(page, 'sello');
    await creator.getByLabel('Leyenda').fill('Gerencia General');
    await page.waitForTimeout(600);
    await shot(page, 'crear-sello-circular-escritorio-claro', false);
    await creator.getByRole('button', { name: 'Rectangular' }).click();
    await page.waitForTimeout(500);
    await shot(page, 'crear-sello-rectangular-escritorio-claro', false);
    await creator.getByRole('button', { name: 'Circular' }).click();
    await page.waitForTimeout(400);
    await saveCreator(page, creator, 'sello');
    await saveAssetImage(page, 'sello', 'activo-sello-generado');
    await shot(page, 'pagina-con-activos-escritorio-claro');

    await page.getByRole('button', { name: 'Subir un PDF' }).click();
    await page.locator('section[aria-labelledby="firmar-documento"] input[type="file"]').setInputFiles(`${FILES}/oferta-qa.pdf`);
    await page.getByLabel('Nombre del documento').fill('Oferta QA firmada');
    await page.getByRole('button', { name: 'Colocar firma y sello' }).click();
    const dialog = await waitForModal(page);
    await shot(page, 'modal-escritorio-claro', false);

    // Teclado: Mayús + flecha derecha mueve 5 % la firma.
    const before = await placementOf(page, 'Firma');
    await page.locator('[data-placement="Firma"]').focus();
    await page.keyboard.press('Shift+ArrowLeft');
    const after = await placementOf(page, 'Firma');
    if (Math.abs(before.x - after.x - 5) > 0.2) throw new Error(`el teclado no movió la firma 5 % (${before.x} → ${after.x})`);

    await dragWithMouse(page, 'Firma', 0.55, 0.7, 60);
    await dragWithMouse(page, 'Sello', 0.08, 0.62, 30);
    placements.escritorio = { firma: await placementOf(page, 'Firma'), sello: await placementOf(page, 'Sello') };
    console.log('posiciones escritorio', JSON.stringify(placements.escritorio));
    await shot(page, 'modal-colocada-escritorio-claro', false);

    const [response] = await Promise.all([
        page.waitForResponse((r) => r.url().includes('/firmas/firmar-archivo')),
        dialog.getByRole('button', { name: 'Firmar y sellar' }).click(),
    ]);
    const body = await response.json();
    if (response.status() !== 201) throw new Error(`firmar respondió ${response.status()}: ${JSON.stringify(body)}`);
    console.log('firmado', body.message, body.application.pages_label);
    placements.escritorio.servidor = body.application.pages;
    await saveResult(page, body, 'resultado-escritorio', body.application.pages[0]);
    await page.getByText('Documento firmado').first().waitFor();
    await page.waitForTimeout(1200);
    await shot(page, 'pagina-firmado-escritorio-claro');
});

// 2. Móvil, claro: documento del sistema, arrastre táctil y confirmación de «firmar de nuevo».
await phase('movil-claro', 'movil', 'claro', async (page, ctx) => {
    const cdp = await ctx.newCDPSession(page);
    await login(page, ADMIN);
    await openPage(page);
    await shot(page, 'pagina-movil-claro');

    // Firma dibujada con el dedo: la página no debe desplazarse mientras se dibuja.
    const creator = await openCreator(page, 'firma');
    const scrollOf = () => page.evaluate(() => `${window.scrollY}/${document.querySelector('[role="dialog"] .overflow-y-auto')?.scrollTop ?? 0}`);
    const scrollBefore = await scrollOf();
    await drawWithTouch(cdp, creator);
    const scrollAfter = await scrollOf();
    if (scrollBefore !== scrollAfter) problems.push(`[movil-claro] la página se desplazó al dibujar (${scrollBefore} → ${scrollAfter})`);
    await page.waitForTimeout(500);
    await shot(page, 'crear-firma-dibujada-movil-claro', false);
    await saveCreator(page, creator, 'firma');
    await saveAssetImage(page, 'firma', 'activo-firma-dibujada-dedo');

    await chooseSystemDocument(page);
    const dialog = await waitForModal(page);
    await shot(page, 'modal-movil-claro', false);

    // Firma sola, arrastrada con el dedo.
    await dialog.getByRole('checkbox', { name: 'Sello' }).uncheck();
    await dragWithTouch(page, cdp, 'Firma', 0.2, 0.15);
    placements.movil = { firma: await placementOf(page, 'Firma') };
    console.log('posiciones móvil', JSON.stringify(placements.movil));
    await shot(page, 'modal-colocada-movil-claro', false);

    await dialog.getByRole('button', { name: /^Firmar$/ }).click();
    const confirmButton = page.getByRole('button', { name: 'Firmar de nuevo' });
    await confirmButton.waitFor({ timeout: 10000 });
    await shot(page, 'confirmar-de-nuevo-movil-claro', false);
    const [response] = await Promise.all([page.waitForResponse((r) => r.url().endsWith('/firmas/firmar')), confirmButton.click()]);
    const body = await response.json();
    if (response.status() !== 201) throw new Error(`firmar respondió ${response.status()}: ${JSON.stringify(body)}`);
    placements.movil.servidor = body.application.pages;
    await saveResult(page, body, 'resultado-movil', body.application.pages[0]);
    await page.waitForTimeout(1500);
    await shot(page, 'pagina-firmado-movil-claro');
});

// 3. Oscuro, escritorio y móvil: página y modal.
for (const viewport of ['escritorio', 'movil']) {
    await phase(`${viewport}-oscuro`, viewport, 'oscuro', async (page) => {
        await login(page, ADMIN);
        await openPage(page);
        await shot(page, `pagina-${viewport}-oscuro`);
        const creator = await openCreator(page, 'firma');
        await creator.getByRole('tab', { name: 'Escribir' }).click();
        await creator.locator('[data-font="allura"]').click();
        await page.waitForTimeout(600);
        await shot(page, `crear-firma-escrita-${viewport}-oscuro`, false);
        await creator.getByRole('button', { name: 'Cancelar' }).click();
        await creator.waitFor({ state: 'hidden' });
        const seal = await openCreator(page, 'sello');
        await shot(page, `crear-sello-${viewport}-oscuro`, false);
        await seal.getByRole('button', { name: 'Cancelar' }).click();
        await seal.waitFor({ state: 'hidden' });
        await chooseSystemDocument(page);
        await waitForModal(page);
        await shot(page, `modal-${viewport}-oscuro`, false);
    });
}

// 3b. Teléfono en horizontal: la modal ocupa la pantalla y el lienzo usa el alto disponible.
await phase('horizontal-claro', 'horizontal', 'claro', async (page, ctx) => {
    const cdp = await ctx.newCDPSession(page);
    await login(page, ADMIN);
    await openPage(page);
    const creator = await openCreator(page, 'firma');
    await drawWithTouch(cdp, creator);
    await page.waitForTimeout(500);
    await shot(page, 'crear-firma-horizontal-claro', false);
    const pad = await creator.locator('[data-signature-pad]').boundingBox();
    if (pad.height < 140) problems.push(`[horizontal] el lienzo quedó bajo (${Math.round(pad.height)} px)`);
    // Foto de una firma sobre papel (JPG): «Quitar el fondo blanco» deja solo la tinta. En horizontal
    // no hay pestañas mientras se dibuja: se cambia con el enlace de la columna de herramientas.
    await creator.getByRole('button', { name: 'Subir imagen' }).click();
    await creator.locator('input[type="file"]').setInputFiles(`${FILES}/firma-foto.jpg`);
    await page.waitForTimeout(1200);
    await shot(page, 'crear-firma-foto-horizontal-claro', false);
    await creator.getByRole('button', { name: 'Cancelar' }).click();
});

// 4. Lector: ve su pantalla, pero no puede firmar.
for (const viewport of ['escritorio', 'movil']) {
    await phase(`lector-${viewport}`, viewport, 'claro', async (page) => {
        await login(page, VIEWER);
        await openPage(page);
        if (!(await page.getByText('Tu rol no permite firmar en la empresa activa').count())) problems.push(`[lector-${viewport}] falta el aviso de solo lectura`);
        if (!(await page.getByRole('button', { name: 'Colocar firma y sello' }).isDisabled())) problems.push(`[lector-${viewport}] el botón de firmar está habilitado`);
        await shot(page, `lector-${viewport}-claro`);
    });
}

await browser.close();
writeFileSync(`${OUTPUT}/posiciones.json`, JSON.stringify(placements, null, 2));

if (problems.length) {
    console.log(`\n${problems.length} problema(s):`);
    problems.forEach((problem) => console.log(`  ${problem}`));
    process.exit(1);
}
console.log('\nSin errores de consola ni scroll horizontal.');
