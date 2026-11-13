// Revisión visual y flujo real del Directorio Institucional y del panel de adjuntos.
//
//   node tests/visual/documentos.mjs            todo (flujo real + capturas en 4 combinaciones + lector + adjuntos)
//   node tests/visual/documentos.mjs rapido     solo escritorio claro (flujo real)
//   node tests/visual/documentos.mjs adjuntos   solo el panel de adjuntos (admin y lector)
//
// Flujo real (escritorio claro, admin): sube 5 archivos (uno truncado, que el servidor rechaza),
// ve, reemplaza, revisa el historial, renueva con archivo, edita, descarga el ZIP para oferta
// (con aviso de vencidos) y elimina. Antes de empezar borra lo que dejó una corrida anterior
// (documentos con referencia «QA-…»). Capturas en storage/app/qa/capturas/documentos/.
// Termina con código 1 si hay errores de consola, scroll horizontal o un paso falla.
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { chromium } from 'playwright';
import { horizontalOverflow, openContext, settle, watchConsole } from './capturas.mjs';

const BASE_URL = process.env.QA_URL ?? 'http://localhost:8000';
const PASSWORD = process.env.QA_PASSWORD ?? 'QaB2g#2026';
const ADMIN = 'qa.admin@crm-b2g.test';
const READER = 'qa.lector@crm-b2g.test';
const OUTPUT = 'storage/app/qa/capturas/documentos';
const FIXTURES = `${OUTPUT}/archivos`;
const quick = process.argv.includes('rapido');
const problems = [];
let currentPage = null;

// ---------- Archivos de prueba ----------

function pdf(text) {
    const stream = `BT /F1 28 Tf 72 700 Td (${text}) Tj ET`;
    const objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        `<< /Length ${stream.length} >>\nstream\n${stream}\nendstream`,
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];
    let out = '%PDF-1.4\n';
    const offsets = [];
    objects.forEach((object, index) => {
        offsets.push(out.length);
        out += `${index + 1} 0 obj\n${object}\nendobj\n`;
    });
    const xref = out.length;
    out += `xref\n0 ${objects.length + 1}\n0000000000 65535 f \n`;
    offsets.forEach((offset) => {
        out += `${String(offset).padStart(10, '0')} 00000 n \n`;
    });
    return `${out}trailer\n<< /Size ${objects.length + 1} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`;
}

function fixtures() {
    mkdirSync(FIXTURES, { recursive: true });
    const files = {
        rtn: ['RTN.pdf', pdf('RTN de la empresa')],
        solvencia: ['Solvencia SAR.pdf', pdf('Solvencia fiscal SAR')],
        ihss: ['Constancia IHSS.pdf', pdf('Constancia IHSS')],
        oncae: ['Constancia ONCAE 2025.pdf', pdf('Constancia ONCAE 2025')],
        roto: ['Escritura dañada.pdf', pdf('Escritura').slice(0, 300)],
        rtn2: ['RTN actualizado.pdf', pdf('RTN actualizado')],
        ihss2: ['Constancia IHSS 2027.pdf', pdf('Constancia IHSS renovada')],
        adjunto: ['Ficha técnica.pdf', pdf('Ficha tecnica del producto')],
    };
    return Object.fromEntries(Object.entries(files).map(([key, [name, contents]]) => {
        const path = `${FIXTURES}/${name}`;
        writeFileSync(path, contents, 'latin1');
        return [key, path];
    }));
}

function cleanup() {
    const code = [
        '$service = app(App\\Services\\Documents\\DocumentService::class);',
        'App\\Models\\Document::withoutGlobalScopes()->where("reference_number", "like", "QA-%")->orWhere("description", "Adjunto de la prueba visual")->get()->each(fn ($d) => $service->delete($d));',
        'echo App\\Models\\Quote::withoutGlobalScopes()->where("company_id", App\\Models\\User::where("email", "qa.admin@crm-b2g.test")->first()->companies()->value("companies.id"))->where("status", "Borrador")->value("id");',
    ].join(' ');
    return execFileSync('php', ['artisan', 'tinker', `--execute=${code}`], { encoding: 'utf8' }).trim().split('\n').pop();
}

// ---------- Utilidades ----------

const isoDate = (days) => {
    const date = new Date(Date.now() + days * 86_400_000);
    return date.toLocaleDateString('en-CA', { timeZone: 'America/Tegucigalpa' });
};

async function login(page, email) {
    await page.goto(`${BASE_URL}/login`);
    await page.fill('#login-email', email);
    await page.fill('#login-password', PASSWORD);
    await Promise.all([page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 20000 }), page.click('button[type="submit"]')]);
}

/** Abre una pantalla y espera a que el marco esté montado (Vite puede recargar la página). */
async function openPage(page, path, label) {
    for (let attempt = 1; attempt <= 3; attempt += 1) {
        const response = await page.goto(`${BASE_URL}${path}`);
        if ((response?.status() ?? 200) >= 400) problems.push(`[${label}] HTTP ${response.status()} en ${path}`);
        try {
            await page.waitForSelector('.cap-shell__content', { timeout: 15000 });
            await settle(page);
            return;
        } catch (error) {
            if (attempt === 3) throw error;
        }
    }
}

async function shot(page, label, name, { full = false } = {}) {
    await page.waitForTimeout(400);
    await page.screenshot({ path: `${OUTPUT}/${label}-${name}.png`, fullPage: full, animations: 'disabled' });
}

const dialog = (page, title) => page.locator('[role="dialog"]').filter({ has: page.getByRole('heading', { name: title }) }).last();

async function waitClosed(page, title) {
    await dialog(page, title).waitFor({ state: 'detached', timeout: 20000 });
}

async function checkOverflow(page, label) {
    const overflow = await horizontalOverflow(page);
    if (overflow > 1) problems.push(`[${label}] scroll horizontal de ${overflow}px`);
}

async function rowMenu(page, name, item) {
    await page.getByRole('button', { name: `Acciones de ${name}`, exact: true }).filter({ visible: true }).first().click();
    await page.getByRole('menuitem', { name: item }).filter({ visible: true }).first().click();
}

async function step(label, name, action) {
    for (let attempt = 1; attempt <= 2; attempt += 1) {
        try {
            await action();
            console.log(`ok  ${label} · ${name}`);
            return true;
        } catch (error) {
            if (attempt === 2) {
                problems.push(`[${label}] ${name}: ${error.message.split('\n')[0]}`);
                console.log(`ERR ${label} · ${name}`);
                return false;
            }
            await escapeAll(currentPage);
        }
    }
    return false;
}

async function escapeAll(page) {
    if (!page) return;
    for (let index = 0; index < 3; index += 1) await page.keyboard.press('Escape').catch(() => {});
}

// ---------- Flujo real ----------

async function realFlow(page, label, files) {
    await openPage(page, '/directorio', label);
    await shot(page, label, '01-inicial', { full: true });

    await step(label, 'subir varios (uno truncado)', async () => {
        await page.getByRole('button', { name: 'Subir documentos' }).filter({ visible: true }).first().click();
        const modal = dialog(page, 'Subir documentos');
        await modal.locator('input[type="file"]').setInputFiles([files.rtn, files.solvencia, files.ihss, files.oncae, files.roto]);
        const cards = modal.locator('ol[aria-label="Documentos por subir"] > li');
        await cards.nth(4).waitFor();
        const data = [
            ['rtn', 'RTN de la empresa', 'QA-RTN-0801', isoDate(-200), isoDate(400)],
            ['solvencia_sar', 'Solvencia fiscal SAR', 'QA-SAR-2026', isoDate(-20), isoDate(12)],
            ['ihss', 'Constancia IHSS', 'QA-IHSS-77', isoDate(-400), isoDate(-5)],
            ['oncae', 'Constancia ONCAE', 'QA-ONCAE-15', isoDate(-500), isoDate(-40)],
            ['escritura', 'Escritura de constitución', 'QA-ESC-1', '', ''],
        ];
        for (const [index, [category, name, reference, issued, expires]] of data.entries()) {
            const card = cards.nth(index);
            await card.locator('select').selectOption(category);
            await card.getByLabel(/^Nombre/).fill(name);
            await card.getByLabel(/^Número o referencia/).fill(reference);
            if (issued) await card.getByLabel(/^Emisión/).fill(issued);
            if (expires) await card.getByLabel(/^Vencimiento/).fill(expires);
        }
        await shot(page, label, '02-subir-datos');
        await modal.getByRole('button', { name: 'Subir 5 documentos' }).click();
        await modal.getByText('incompleto o dañado').waitFor({ timeout: 30000 });
        await shot(page, label, '03-subir-error-truncado');
        await modal.getByRole('button', { name: 'Cancelar' }).click();
        await waitClosed(page, 'Subir documentos');
        await settle(page);
        await page.getByText('RTN de la empresa').filter({ visible: true }).first().waitFor();
        await shot(page, label, '04-lista', { full: true });
    });

    await step(label, 'ver documento', async () => {
        await page.getByText('RTN de la empresa', { exact: true }).filter({ visible: true }).first().click();
        const modal = dialog(page, 'RTN de la empresa');
        await modal.locator('canvas').first().waitFor({ timeout: 20000 });
        await shot(page, label, '05-ver');
        await modal.getByRole('button', { name: 'Cerrar', exact: true }).last().click();
        await waitClosed(page, 'RTN de la empresa');
    });

    await step(label, 'reemplazar', async () => {
        await rowMenu(page, 'RTN de la empresa', 'Reemplazar archivo');
        const modal = dialog(page, 'Reemplazar archivo');
        await modal.locator('input[type="file"]').setInputFiles(files.rtn2);
        await shot(page, label, '06-reemplazar');
        await modal.getByRole('button', { name: 'Reemplazar archivo' }).click();
        await waitClosed(page, 'Reemplazar archivo');
    });

    await step(label, 'historial y versión anterior', async () => {
        await rowMenu(page, 'RTN de la empresa', 'Historial de versiones');
        const modal = dialog(page, 'Historial de versiones');
        await modal.getByText('RTN actualizado.pdf').waitFor();
        await shot(page, label, '07-historial');
        await modal.getByRole('button', { name: 'Ver la versión 1' }).click();
        const viewer = dialog(page, 'RTN de la empresa · versión 1');
        await viewer.locator('canvas').first().waitFor({ timeout: 20000 });
        await shot(page, label, '08-ver-version-1');
        await page.keyboard.press('Escape');
        await waitClosed(page, 'RTN de la empresa · versión 1');
        await page.keyboard.press('Escape');
        await waitClosed(page, 'Historial de versiones');
    });

    await step(label, 'renovar con archivo', async () => {
        await rowMenu(page, 'Constancia IHSS', 'Renovar');
        const modal = dialog(page, 'Renovar documento');
        await modal.getByLabel(/^Nuevo vencimiento/).fill(isoDate(365));
        await modal.locator('input[type="file"]').setInputFiles(files.ihss2);
        await shot(page, label, '09-renovar');
        await modal.getByRole('button', { name: 'Renovar', exact: true }).click();
        await waitClosed(page, 'Renovar documento');
    });

    await step(label, 'editar datos', async () => {
        await rowMenu(page, 'Solvencia fiscal SAR', 'Editar datos');
        const modal = dialog(page, 'Editar datos del documento');
        await modal.getByLabel(/^Titular/).fill('Representante legal');
        await modal.getByLabel(/^Número o referencia/).fill('QA-SAR-2026-B');
        await shot(page, label, '10-editar');
        await modal.getByRole('button', { name: 'Guardar cambios' }).click();
        await waitClosed(page, 'Editar datos del documento');
    });

    await step(label, 'descargar para oferta con vencido', async () => {
        for (const name of ['RTN de la empresa', 'Solvencia fiscal SAR', 'Constancia ONCAE']) {
            await page.getByRole('checkbox', { name: `Elegir ${name}` }).filter({ visible: true }).first().check();
        }
        await shot(page, label, '11-seleccion', { full: true });
        await page.getByRole('button', { name: /Descargar para oferta \(3\)/ }).click();
        await page.getByRole('alertdialog').or(page.locator('[role="dialog"]').filter({ hasText: 'vencido' })).first().waitFor();
        await shot(page, label, '12-aviso-vencido');
        const [download] = await Promise.all([page.waitForEvent('download', { timeout: 20000 }), page.getByRole('button', { name: 'Descargar 2 vigentes' }).click()]);
        const name = download.suggestedFilename();
        if (!/^Documentos para oferta .*\.zip$/.test(name)) throw new Error(`ZIP con nombre inesperado: ${name}`);
        await download.saveAs(`${OUTPUT}/paquete-oferta.zip`);
        await page.getByRole('button', { name: 'Quitar selección' }).click();
    });

    await step(label, 'eliminar', async () => {
        await rowMenu(page, 'Constancia ONCAE', 'Eliminar');
        await page.getByText('¿Eliminar «Constancia ONCAE»?').waitFor();
        await shot(page, label, '13-confirmar-eliminar');
        await page.getByRole('button', { name: 'Eliminar documento' }).click();
        await page.getByRole('checkbox', { name: 'Elegir Constancia ONCAE' }).first().waitFor({ state: 'detached', timeout: 15000 });
        await shot(page, label, '14-final', { full: true });
    });

    await checkOverflow(page, label);
}

// ---------- Capturas de cada modal en las otras combinaciones ----------

async function modalTour(page, label, files) {
    await openPage(page, '/directorio', label);
    await shot(page, label, '01-lista', { full: true });
    await checkOverflow(page, label);

    await step(label, 'filtro por vencer', async () => {
        await page.getByRole('button', { name: /Por vencer/ }).first().click();
        await page.waitForURL(/status=por_vencer/);
        await settle(page);
        await shot(page, label, '02-filtro');
        await page.getByRole('button', { name: /Por vencer/ }).first().click();
        await page.waitForURL((url) => !url.search.includes('status='));
    });

    await step(label, 'modal subir', async () => {
        await page.getByRole('button', { name: 'Subir documentos' }).filter({ visible: true }).first().click();
        const modal = dialog(page, 'Subir documentos');
        await modal.locator('input[type="file"]').setInputFiles([files.rtn]);
        await modal.locator('ol[aria-label="Documentos por subir"] > li').first().waitFor();
        await shot(page, label, '03-subir');
        await checkOverflow(page, `${label} subir`);
        await modal.getByRole('button', { name: 'Cancelar' }).click();
        await waitClosed(page, 'Subir documentos');
    });

    await step(label, 'modal ver', async () => {
        await page.getByText('RTN de la empresa', { exact: true }).filter({ visible: true }).first().click();
        const modal = dialog(page, 'RTN de la empresa');
        await modal.locator('canvas').first().waitFor({ timeout: 20000 });
        await shot(page, label, '04-ver');
        await page.keyboard.press('Escape');
        await waitClosed(page, 'RTN de la empresa');
    });

    for (const [item, title, file] of [['Historial de versiones', 'Historial de versiones', '05-historial'], ['Renovar', 'Renovar documento', '06-renovar'], ['Reemplazar archivo', 'Reemplazar archivo', '07-reemplazar'], ['Editar datos', 'Editar datos del documento', '08-editar']]) {
        await step(label, `modal ${item}`, async () => {
            await rowMenu(page, 'RTN de la empresa', item);
            await dialog(page, title).waitFor();
            await settle(page);
            await shot(page, label, file);
            await checkOverflow(page, `${label} ${item}`);
            await page.keyboard.press('Escape');
            await waitClosed(page, title);
        });
    }

    await step(label, 'confirmar eliminar', async () => {
        await rowMenu(page, 'Solvencia fiscal SAR', 'Eliminar');
        await page.getByText('¿Eliminar «Solvencia fiscal SAR»?').waitFor();
        await shot(page, label, '09-confirmar-eliminar');
        await page.getByRole('button', { name: 'Cancelar' }).last().click();
    });
}

// ---------- Lector y panel de adjuntos ----------

async function readerTour(page, label) {
    await openPage(page, '/directorio', label);
    await shot(page, label, '01-lista', { full: true });
    const uploads = await page.getByRole('button', { name: 'Subir documentos' }).filter({ visible: true }).count();
    if (uploads) problems.push(`[${label}] el lector ve «Subir documentos»`);
    await step(label, 'menú sin acciones de escritura', async () => {
        await page.getByRole('button', { name: 'Acciones de RTN de la empresa', exact: true }).filter({ visible: true }).first().click();
        await shot(page, label, '02-menu');
        for (const forbidden of ['Renovar', 'Reemplazar archivo', 'Editar datos', 'Eliminar']) {
            if (await page.getByRole('menuitem', { name: forbidden }).filter({ visible: true }).count()) problems.push(`[${label}] el lector ve «${forbidden}»`);
        }
        await page.keyboard.press('Escape');
    });
}

async function attachmentsTour(page, label, files, quoteId, canEdit, flow) {
    await openPage(page, `/directorio/adjuntos-prueba/quote/${quoteId}`, label);
    await page.locator('h3', { hasText: 'Adjuntos' }).first().waitFor();
    await settle(page);
    await shot(page, label, '01-panel');

    if (!flow) return;

    await step(label, 'adjuntar PDF', async () => {
        await page.getByRole('button', { name: 'Agregar PDF' }).first().click();
        const modal = dialog(page, 'Agregar PDF');
        await modal.locator('input[type="file"]').setInputFiles([files.adjunto]);
        await modal.getByLabel(/^Descripción/).fill('Adjunto de la prueba visual');
        await shot(page, label, '02-agregar');
        await modal.getByRole('button', { name: 'Subir', exact: true }).click();
        await waitClosed(page, 'Agregar PDF');
        await page.getByRole('button', { name: 'Ficha técnica', exact: true }).waitFor();
        await shot(page, label, '03-lista');
    });

    await step(label, 'ver adjunto', async () => {
        await page.getByRole('button', { name: 'Ficha técnica', exact: true }).click();
        const modal = dialog(page, 'Ficha técnica');
        await modal.locator('canvas').first().waitFor({ timeout: 20000 });
        await shot(page, label, '04-ver');
        await page.keyboard.press('Escape');
        await waitClosed(page, 'Ficha técnica');
    });

    await step(label, 'eliminar adjunto', async () => {
        await page.getByRole('button', { name: 'Eliminar Ficha técnica' }).click();
        await page.getByText('¿Eliminar el adjunto «Ficha técnica»?').waitFor();
        await shot(page, label, '05-confirmar');
        await page.getByRole('button', { name: 'Eliminar adjunto' }).click();
        await page.getByText('Sin adjuntos').waitFor({ timeout: 15000 });
        await shot(page, label, '06-vacio');
    });
}

// ---------- Principal ----------

async function session(browser, viewport, theme, email) {
    const context = await openContext(browser, viewport, theme);
    const page = await context.newPage();
    const label = `${viewport}-${theme}${email === READER ? '-lector' : ''}`;
    const seen = watchConsole(page, label);
    currentPage = page;
    await login(page, email);
    return { context, page, label, seen };
}

async function main() {
    mkdirSync(OUTPUT, { recursive: true });
    const files = fixtures();
    const quoteId = cleanup();
    const browser = await chromium.launch();

    const onlyAttachments = process.argv.includes('adjuntos');
    const runs = onlyAttachments ? [] : [['escritorio', 'claro', ADMIN, 'flow']];
    if (onlyAttachments) runs.push(['escritorio', 'claro', ADMIN, 'attachments'], ['movil', 'oscuro', ADMIN, 'attachments'], ['movil', 'claro', READER, 'attachments']);
    else if (!quick) runs.push(['escritorio', 'oscuro', ADMIN, 'tour'], ['movil', 'claro', ADMIN, 'tour'], ['movil', 'oscuro', ADMIN, 'tour'], ['escritorio', 'claro', READER, 'reader'], ['movil', 'claro', READER, 'reader']);

    for (const [viewport, theme, email, mode] of runs) {
        const { context, page, label, seen } = await session(browser, viewport, theme, email);
        try {
            if (mode === 'flow') await realFlow(page, label, files);
            if (mode === 'tour') await modalTour(page, label, files);
            if (mode === 'reader') await readerTour(page, label);
            const adminFlow = (mode === 'flow' || mode === 'attachments' || (mode === 'tour' && viewport === 'movil' && theme === 'oscuro')) && email === ADMIN;
            if (mode !== 'tour' || adminFlow) await attachmentsTour(page, `${label}-adjuntos`, files, quoteId, email === ADMIN, adminFlow);
        } catch (error) {
            problems.push(`[${label}] ${error.message.split('\n')[0]}`);
            await page.screenshot({ path: `${OUTPUT}/${label}-fallo.png`, fullPage: true }).catch(() => {});
        }
        problems.push(...seen);
        await context.close();
    }

    await browser.close();
    if (problems.length) {
        console.log(`\n${problems.length} problema(s):`);
        problems.forEach((problem) => console.log(`  ${problem}`));
        process.exit(1);
    }
    console.log('\nSin errores de consola, sin scroll horizontal y todos los pasos completos.');
}

await main();
