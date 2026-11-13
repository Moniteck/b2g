// Validación visual y de punta a punta de las herramientas B2G: Historial HonduCompras, Inteligencia
// competitiva, Relaciones institucionales, Consorcios, Matriz documental, Agente Legal, Generador de
// documentos, Capacitación y SOP.
//
//   node tests/visual/herramientas.mjs            flujo real + capturas (escritorio y móvil, claro y oscuro)
//   node tests/visual/herramientas.mjs --rapido   solo escritorio claro
//   QA_PASOS=historial,consorcios node tests/visual/herramientas.mjs --rapido   solo esos pasos (depuración)
//
// 1. Con qa.admin crea, edita y abre cada modal con datos reales (nombres «QA herramientas <marca>»).
// 2. Captura cada pantalla y sus modales en 1440×900 y 390×844, claro y oscuro.
// 3. Con qa.lector comprueba que solo lee (sin botones de escritura y sin Agente Legal).
// 4. Elimina todo lo creado (el competidor, catálogo compartido, lo elimina qa.root).
// Las imágenes quedan en storage/app/qa/capturas/herramientas/. Sale con 1 si hubo errores de consola,
// scroll horizontal o pasos fallidos.
import { mkdirSync } from 'node:fs';
import { chromium } from 'playwright';
import { horizontalOverflow, openContext, settle, watchConsole } from './capturas.mjs';

const BASE_URL = process.env.QA_URL ?? 'http://localhost:8000';
const PASSWORD = process.env.QA_PASSWORD ?? 'QaB2g#2026';
const OUTPUT = 'storage/app/qa/capturas/herramientas';
const QUICK = process.argv.includes('--rapido');
const MARK = `QA herramientas ${Date.now().toString(36)}`;
const ONLY_STEPS = process.env.QA_PASOS ? process.env.QA_PASOS.split(',') : null;

const SCREENS = [
    ['historial', '/historial'],
    ['competidores', '/inteligencia-competitiva'],
    ['relaciones', '/relaciones-institucionales'],
    ['consorcios', '/consorcios'],
    ['matrices', '/matriz-cumplimiento'],
    ['agente-legal', '/agente-legal'],
    ['generador', '/generador-documentos'],
    ['capacitacion', '/capacitacion'],
    ['sops', '/sops'],
];

const problems = [];
let matrixUrl = null;

async function login(page, user) {
    for (let attempt = 0; attempt < 3; attempt += 1) {
        try {
            await page.goto(`${BASE_URL}/login`);
            await page.fill('#login-email', `qa.${user}@crm-b2g.test`);
            await page.fill('#login-password', PASSWORD);
            await Promise.all([page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 15000 }), page.click('button[type="submit"]')]);
            return;
        } catch (error) {
            if (attempt === 2) throw error;
            await page.waitForTimeout(3000);
        }
    }
}

/** Abre una ruta y espera el marco; si Vite recargó y dejó la página en blanco, reintenta. */
async function go(page, path) {
    for (let attempt = 0; attempt < 3; attempt += 1) {
        try {
            await page.goto(`${BASE_URL}${path}`);
            await page.waitForSelector('.cap-shell main, .cap-shell__content', { timeout: 15000 });
            await settle(page);
            return;
        } catch (error) {
            if (attempt === 2) throw error;
        }
    }
}

async function shot(page, name) {
    await page.waitForTimeout(400);
    await page.screenshot({ path: `${OUTPUT}/${name}.png`, fullPage: true, animations: 'disabled' });
    const overflow = await horizontalOverflow(page);
    if (overflow > 1) problems.push(`[${name}] scroll horizontal de ${overflow}px`);
}

async function step(name, action) {
    if (ONLY_STEPS && !ONLY_STEPS.some((prefix) => name.startsWith(prefix))) return;
    try {
        await action();
        console.log(`ok  ${name}`);
    } catch (error) {
        problems.push(`[${name}] ${error.message.split('\n').slice(0, 3).join(' | ')}`);
        console.log(`ERR ${name}`);
    }
}

const dialog = (page) => page.getByRole('dialog').last();
const escapeRegex = (text) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
/** Campo por su etiqueta, anclada al inicio: «Resultado» no confunde «Causa del resultado» ni el «*». */
const field = (scope, label, index = 0) => scope.getByLabel(new RegExp(`^${escapeRegex(label)}(\\s|\\*|$)`)).nth(index);
const button = (scope, name) => scope.getByRole('button', { name, exact: true }).filter({ visible: true }).first();

async function closeDialogs(page) {
    for (let index = 0; index < 3 && (await page.getByRole('dialog').count()); index += 1) {
        await page.keyboard.press('Escape');
        await page.waitForTimeout(300);
    }
}

/** Primer botón visible con ese texto (barra de acciones o estado vacío). */
async function clickVisible(page, name) {
    const candidates = page.getByRole('button', { name, exact: true });
    const count = await candidates.count();
    for (let index = 0; index < count; index += 1) {
        if (await candidates.nth(index).isVisible()) return candidates.nth(index).click();
    }
    throw new Error(`No hay un botón visible «${name}»`);
}

async function pickRemote(scope, label, term) {
    const combo = field(scope, label);
    await combo.click();
    await combo.fill(term);
    const option = scope.page().locator('[role="option"]').first();
    await option.waitFor({ timeout: 8000 });
    await option.click();
}

async function waitToast(page) {
    await page.waitForTimeout(1200);
}

async function rowAction(page, rowName, action) {
    await page.getByRole('button', { name: `Acciones de ${rowName}` }).first().click();
    await page.getByRole('menuitem', { name: action, exact: true }).first().click();
}

async function confirmAction(page, label) {
    const alert = page.getByRole('alertdialog');
    await alert.waitFor();
    await alert.getByRole('button', { name: label }).click();
    await alert.waitFor({ state: 'detached', timeout: 15000 });
}

// ---------------------------------------------------------------- flujo real con qa.admin

async function adminFlow(page) {
    const name = (suffix) => `${MARK} ${suffix}`;

    await step('historial: registrar, ver y editar', async () => {
        await go(page, '/historial');
        await clickVisible(page, 'Registrar proceso');
        const modal = dialog(page);
        await field(modal, 'Número de proceso').fill(name('LPN'));
        await field(modal, 'Nombre del proceso').fill('Suministro de llantas para flota');
        await field(modal, 'Monto estimado').fill('150000');
        await field(modal, 'Monto ofertado').fill('138500');
        await field(modal, 'Resultado').selectOption('Adjudicado');
        await shot(page, 'historial-modal-registrar');
        await button(modal, 'Registrar proceso').click();
        await modal.waitFor({ state: 'detached' });
        await waitToast(page);
        await page.getByText(name('LPN')).filter({ visible: true }).first().click();
        await shot(page, 'historial-modal-detalle');
        await button(dialog(page), 'Editar').click();
        const editor = page.getByRole('dialog', { name: 'Editar proceso' });
        await field(editor, 'Causa del resultado').fill('Mejor precio y entrega inmediata');
        await button(editor, 'Guardar cambios').click();
        await editor.waitFor({ state: 'detached' });
        await waitToast(page);
    });

    await step('historial: validación en línea', async () => {
        await clickVisible(page, 'Registrar proceso');
        await button(dialog(page), 'Registrar proceso').click();
        await dialog(page).getByText('El campo número de proceso es obligatorio.').waitFor();
        await shot(page, 'historial-modal-validacion');
        await closeDialogs(page);
    });

    await step('competidores: crear y ver ficha', async () => {
        await go(page, '/inteligencia-competitiva');
        await clickVisible(page, 'Nuevo competidor');
        const modal = dialog(page);
        await field(modal, 'Nombre').fill(name('Rival'));
        await field(modal, 'Categoría').fill('Llantas');
        await field(modal, 'Adjudicaciones').fill('4');
        await field(modal, 'No adjudicaciones').fill('2');
        await field(modal, 'Instituciones donde participa').fill('SESAL, ENEE');
        await shot(page, 'competidores-modal-crear');
        await button(modal, 'Crear competidor').click();
        await modal.waitFor({ state: 'detached' });
        await waitToast(page);
        await page.getByText(name('Rival')).filter({ visible: true }).first().click();
        await shot(page, 'competidores-modal-ficha');
        await dialog(page).getByRole('tab', { name: /Participaciones/ }).click();
        await page.waitForTimeout(800);
        await shot(page, 'competidores-modal-participaciones');
        await closeDialogs(page);
    });

    await step('relaciones: crear, interacción y línea de tiempo', async () => {
        await go(page, '/relaciones-institucionales');
        await clickVisible(page, 'Nueva relación');
        const modal = dialog(page);
        await pickRemote(modal, 'Institución', 'Sec');
        await field(modal, 'Influencia').selectOption('Alta');
        await field(modal, 'Interés en el POA').fill(name('POA'));
        await shot(page, 'relaciones-modal-crear');
        await button(modal, 'Crear relación').click();
        await modal.waitFor({ state: 'detached' });
        await waitToast(page);
        await page.getByText(name('POA')).count().catch(() => 0);
        await page.locator('tbody tr, [data-row]').first().click().catch(async () => page.getByRole('button', { name: /Acciones de la relación/ }).first().click());
        await dialog(page).getByText('Interacciones').first().waitFor();
        await button(dialog(page), 'Registrar interacción').click();
        const form = dialog(page);
        await field(form, 'Qué se trató').fill('Presentación del catálogo de llantas y plan de entregas.');
        await field(form, 'Resultado').fill('Pidió cotización formal');
        await shot(page, 'relaciones-modal-interaccion');
        await button(form, 'Registrar interacción').click();
        await page.waitForTimeout(1500);
        await shot(page, 'relaciones-modal-ficha');
        await closeDialogs(page);
    });

    await step('consorcios: suma distinta de 100 y luego correcta', async () => {
        await go(page, '/consorcios');
        await clickVisible(page, 'Nuevo consorcio');
        const modal = dialog(page);
        await field(modal, 'Nombre').fill(name('Consorcio'));
        await button(modal, 'Agregar miembro').click();
        await button(modal, 'Agregar miembro').click();
        const names = { nth: (index) => field(modal, 'Nombre', index) };
        await names.nth(1).fill('Empresa socia A');
        await names.nth(2).fill('Empresa socia B');
        const shares = { nth: (index) => field(modal, 'Participación %', index) };
        await shares.nth(0).fill('60');
        await shares.nth(1).fill('30');
        await button(modal, 'Crear consorcio').click();
        await modal.getByText(/debe sumar 100/).first().waitFor();
        await shot(page, 'consorcios-modal-suma-invalida');
        await shares.nth(1).fill('40');
        await button(modal, 'Crear consorcio').click();
        await modal.waitFor({ state: 'detached' });
        await waitToast(page);
        await page.getByText(name('Consorcio')).filter({ visible: true }).first().click();
        await shot(page, 'consorcios-modal-detalle');
        await closeDialogs(page);
    });

    await step('matriz: crear, marcar, renovar y PDF', async () => {
        await go(page, '/matriz-cumplimiento');
        await clickVisible(page, 'Nueva matriz');
        const modal = dialog(page);
        await field(modal, 'Licitación').click();
        await page.locator('[role="option"]').first().click();
        await field(modal, 'Nombre de la matriz').fill(name('Matriz'));
        await button(modal, 'Agregar los habituales').click();
        await shot(page, 'matrices-modal-crear');
        await button(modal, 'Crear matriz').click();
        await page.waitForURL(/\/matriz-cumplimiento\/\d+/);
        matrixUrl = new URL(page.url()).pathname;
        await settle(page);
        await rowAction(page, 'RTN numérico de la empresa', 'Marcar como cumplido');
        await waitToast(page);
        await rowAction(page, 'Solvencia fiscal (SAR)', 'Renovar');
        await shot(page, 'matrices-modal-renovar');
        await closeDialogs(page);
        await rowAction(page, 'Constancia del IHSS', 'Editar');
        await shot(page, 'matrices-modal-editar');
        await closeDialogs(page);
        await clickVisible(page, 'Renombrar');
        await shot(page, 'matrices-modal-renombrar');
        await closeDialogs(page);
        const pdf = await page.request.get(`${BASE_URL}${matrixUrl}/pdf`);
        if (pdf.headers()['content-type'] !== 'application/pdf') throw new Error(`PDF de la matriz: ${pdf.status()}`);
        await shot(page, 'matrices-ficha');
    });

    await step('agente legal: consulta (IA real o error controlado)', async () => {
        await go(page, '/agente-legal');
        await field(page, 'Escribe tu consulta').fill('¿Qué plazo tengo para impugnar una adjudicación? <b>no es HTML</b>');
        await button(page, 'Enviar').click();
        await page.getByText(/El agente está escribiendo/).waitFor({ timeout: 5000 }).catch(() => {});
        await shot(page, 'agente-legal-escribiendo');
        await page.waitForFunction(() => !document.body.innerText.includes('El agente está escribiendo'), null, { timeout: 120000 });
        await shot(page, 'agente-legal-respuesta');
        if (await page.locator('.legal-answer b').count()) throw new Error('Se interpretó HTML del usuario');
    });

    await step('agente legal: redactar escrito', async () => {
        await clickVisible(page, 'Redactar escrito');
        const modal = dialog(page);
        await field(modal, 'Tipo de escrito').selectOption({ label: 'Solicitud de aclaración' });
        await field(modal, 'Institución destinataria').fill('Secretaría de Salud');
        await field(modal, 'Hechos y solicitud').fill('Solicitamos aclarar si el lote 2 admite marcas equivalentes a las especificadas en el pliego LPN-007.');
        await shot(page, 'agente-legal-modal-escrito');
        await button(modal, 'Generar borrador').click();
        await page.waitForFunction(() => !document.body.innerText.includes('Redactando…'), null, { timeout: 120000 });
        await shot(page, 'agente-legal-modal-escrito-resultado');
        await closeDialogs(page);
    });

    await step('generador: formulario por tipo y resultado', async () => {
        await go(page, '/generador-documentos');
        await page.getByRole('button', { name: /Carta de presentación/ }).first().click();
        const modal = dialog(page);
        await field(modal, 'Institución destinataria').fill('Secretaría de Salud');
        await field(modal, 'Qué quieres destacar').fill('Distribuidores de llantas con diez años de experiencia con el Estado.');
        await shot(page, 'generador-modal');
        await button(modal, 'Generar borrador').click();
        await page.waitForFunction(() => !document.body.innerText.includes('Redactando…'), null, { timeout: 120000 });
        await shot(page, 'generador-modal-resultado');
        await closeDialogs(page);
    });

    await step('capacitación: leer lección', async () => {
        await go(page, '/capacitacion');
        await page.getByRole('button', { name: /Empezar|Continuar|Repasar/ }).first().click();
        await shot(page, 'capacitacion-modal-leccion');
        await dialog(page).getByRole('button', { name: /Siguiente lección|Terminar curso/ }).click();
        await page.waitForTimeout(1200);
        await closeDialogs(page);
    });

    await step('SOP: marcar como leído y PDF', async () => {
        await go(page, '/sops');
        await clickVisible(page, 'Marcar como leído');
        await page.getByRole('button', { name: 'Marcar como no leído' }).first().waitFor();
        await shot(page, 'sops-leido');
        await clickVisible(page, 'Marcar como no leído');
        const pdf = await page.request.get(`${BASE_URL}/sops/gestion-licitaciones/pdf`);
        if (pdf.headers()['content-type'] !== 'application/pdf') throw new Error(`PDF del SOP: ${pdf.status()}`);
    });
}

// ---------------------------------------------------------------- capturas en las cuatro combinaciones

async function captureAll(browser, viewport, theme) {
    const context = await openContext(browser, viewport, theme);
    const page = await context.newPage();
    const seen = watchConsole(page, `${viewport}-${theme}`);
    await login(page, 'admin');
    for (const [key, path] of SCREENS) {
        await step(`captura ${key} ${viewport} ${theme}`, async () => {
            await go(page, path);
            await shot(page, `${key}-${viewport}-${theme}`);
        });
    }
    await step(`modales ${viewport} ${theme}`, async () => {
        await go(page, '/historial');
        await page.getByText(`${MARK} LPN`).filter({ visible: true }).first().click();
        await shot(page, `historial-detalle-${viewport}-${theme}`);
        await button(dialog(page), 'Editar').click();
        await shot(page, `historial-editar-${viewport}-${theme}`);
        await closeDialogs(page);

        await go(page, '/consorcios');
        await page.getByText(`${MARK} Consorcio`).filter({ visible: true }).first().click();
        await button(dialog(page), 'Editar').click();
        await shot(page, `consorcios-editar-${viewport}-${theme}`);
        await closeDialogs(page);

        await go(page, '/relaciones-institucionales');
        await rowActionFirst(page, /Acciones de la relación/, 'Ver ficha');
        await page.waitForTimeout(1200);
        await shot(page, `relaciones-ficha-${viewport}-${theme}`);
        await closeDialogs(page);

        if (matrixUrl) {
            await go(page, matrixUrl);
            await shot(page, `matriz-ficha-${viewport}-${theme}`);
        }

        await go(page, '/agente-legal');
        await clickVisible(page, 'Redactar escrito');
        await shot(page, `agente-legal-escrito-${viewport}-${theme}`);
        await closeDialogs(page);
        if (viewport === 'movil') {
            await clickVisible(page, 'Conversaciones');
            await shot(page, `agente-legal-conversaciones-${viewport}-${theme}`);
            await closeDialogs(page);
        }

        await go(page, '/generador-documentos');
        await page.getByRole('button', { name: /Declaración jurada/ }).first().click();
        await shot(page, `generador-form-${viewport}-${theme}`);
        await closeDialogs(page);

        await go(page, '/capacitacion');
        await page.getByRole('button', { name: /Empezar|Continuar|Repasar/ }).first().click();
        await shot(page, `capacitacion-leccion-${viewport}-${theme}`);
        await closeDialogs(page);
    });
    problems.push(...seen);
    await context.close();
}

async function rowActionFirst(page, labelPattern, action) {
    await page.getByRole('button', { name: labelPattern }).first().click();
    await page.getByRole('menuitem', { name: action, exact: true }).first().click();
}

// ---------------------------------------------------------------- lector y limpieza

async function viewerChecks(browser) {
    const context = await openContext(browser, 'escritorio', 'claro');
    const page = await context.newPage();
    const seen = watchConsole(page, 'lector');
    await login(page, 'lector');
    const forbidden = { '/historial': 'Registrar proceso', '/inteligencia-competitiva': 'Nuevo competidor', '/relaciones-institucionales': 'Nueva relación', '/consorcios': 'Nuevo consorcio', '/matriz-cumplimiento': 'Nueva matriz' };
    for (const [path, label] of Object.entries(forbidden)) {
        await step(`lector ${path}`, async () => {
            await go(page, path);
            if (await page.getByRole('button', { name: label, exact: true }).count()) throw new Error(`El lector ve «${label}»`);
        });
    }
    await step('lector sin Agente Legal ni generador', async () => {
        for (const path of ['/agente-legal', '/generador-documentos']) {
            const response = await page.goto(`${BASE_URL}${path}`, { waitUntil: 'commit' });
            if (response.status() !== 403) throw new Error(`${path} respondió ${response.status()}`);
        }
        await go(page, '/sops');
        await shot(page, 'lector-sops');
    });
    problems.push(...seen.filter((message) => !message.includes('403')));
    await context.close();
}

async function cleanup(browser) {
    const context = await openContext(browser, 'escritorio', 'claro');
    const page = await context.newPage();
    await login(page, 'admin');

    await step('limpieza historial', async () => {
        await go(page, '/historial');
        await rowAction(page, `${MARK} LPN`, 'Eliminar');
        await confirmAction(page, 'Eliminar proceso');
    });
    await step('limpieza relación', async () => {
        await go(page, '/relaciones-institucionales');
        const rows = page.getByRole('button', { name: /Acciones de la relación/ });
        for (let index = await rows.count(); index > 0; index -= 1) {
            await rows.first().click();
            await page.getByRole('menuitem', { name: 'Ver ficha' }).click();
            await page.waitForTimeout(800);
            const mine = await dialog(page).getByText(`${MARK} POA`).count();
            if (!mine) {
                await closeDialogs(page);
                break;
            }
            await button(dialog(page), 'Eliminar').click();
            await confirmAction(page, 'Eliminar relación');
            await page.waitForTimeout(800);
        }
    });
    await step('limpieza consorcio', async () => {
        await go(page, '/consorcios');
        await rowAction(page, `${MARK} Consorcio`, 'Eliminar');
        await confirmAction(page, 'Eliminar consorcio');
    });
    await step('limpieza matriz', async () => {
        if (!matrixUrl) return;
        await go(page, matrixUrl);
        await clickVisible(page, 'Eliminar matriz');
        await shot(page, 'matrices-confirmar-eliminar');
        await confirmAction(page, 'Eliminar matriz');
    });
    await step('limpieza documentos generados y conversaciones', async () => {
        await go(page, '/generador-documentos');
        while (await page.getByRole('button', { name: /Acciones de Carta de presentación — Secretaría de Salud/ }).count()) {
            await rowActionFirst(page, /Acciones de Carta de presentación — Secretaría de Salud/, 'Eliminar');
            await confirmAction(page, 'Eliminar documento');
            await page.waitForTimeout(800);
        }
        await go(page, '/agente-legal');
        const remove = page.getByRole('button', { name: /Eliminar la conversación ¿Qué plazo tengo para impugnar/ });
        while (await remove.count()) {
            await remove.first().click();
            await confirmAction(page, 'Eliminar conversación');
        }
    });
    await context.close();

    const root = await openContext(browser, 'escritorio', 'claro');
    const rootPage = await root.newPage();
    await login(rootPage, 'root');
    await step('limpieza competidor (raíz)', async () => {
        await go(rootPage, '/inteligencia-competitiva');
        await rowAction(rootPage, `${MARK} Rival`, 'Eliminar');
        await confirmAction(rootPage, 'Eliminar competidor');
    });
    await root.close();
}

async function main() {
    mkdirSync(OUTPUT, { recursive: true });
    const browser = await chromium.launch();

    const context = await openContext(browser, 'escritorio', 'claro');
    const page = await context.newPage();
    const seen = watchConsole(page, 'flujo');
    await login(page, 'admin');
    await adminFlow(page);
    // Los fallos de la IA llegan como 422 controlado: no son errores de la pantalla.
    problems.push(...seen.filter((message) => !message.includes('422') && !message.includes('429')));
    await context.close();

    const combos = QUICK ? [['escritorio', 'claro']] : [['escritorio', 'claro'], ['escritorio', 'oscuro'], ['movil', 'claro'], ['movil', 'oscuro']];
    for (const [viewport, theme] of combos) await captureAll(browser, viewport, theme);

    await viewerChecks(browser);
    await cleanup(browser);
    await browser.close();

    if (problems.length) {
        console.log(`\n${problems.length} problema(s):`);
        problems.forEach((problem) => console.log(`  ${problem}`));
        process.exit(1);
    }
    console.log('\nHerramientas sin errores de consola, sin scroll horizontal y con todos los pasos completos.');
}

await main();
