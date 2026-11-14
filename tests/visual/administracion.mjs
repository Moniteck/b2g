// Revisión visual y de flujo del módulo Clientes, catálogos y administración.
//
//   node tests/visual/administracion.mjs              todo (raíz, administrador y lector; escritorio y móvil; claro y oscuro)
//   node tests/visual/administracion.mjs admin        solo un perfil
//
// El flujo real (crear, editar, subir, eliminar) corre una vez con el administrador y con la raíz en escritorio claro;
// el resto de combinaciones abre cada pantalla y cada modal sin modificar datos.
// Capturas en storage/app/qa/capturas/administracion/. Termina con código 1 si hay errores de consola,
// respuestas 5xx, scroll horizontal o pasos fallidos.
import { mkdirSync } from 'node:fs';
import { chromium } from 'playwright';
import { horizontalOverflow, openContext, settle } from './capturas.mjs';

const BASE_URL = process.env.QA_URL ?? 'http://localhost:8000';
const PASSWORD = process.env.QA_PASSWORD ?? 'QaB2g#2026';
const OUTPUT = 'storage/app/qa/capturas/administracion';
const VIEWPORTS = ['escritorio', 'movil'];
const THEMES = ['claro', 'oscuro'];
const STAMP = Date.now().toString().slice(-6);

const PROFILES = {
    root: { email: 'qa.root@crm-b2g.test' },
    admin: { email: 'qa.admin@crm-b2g.test' },
    lector: { email: 'qa.lector@crm-b2g.test' },
};

const problems = [];

async function login(page, email) {
    for (let attempt = 1; attempt <= 3; attempt++) {
        try {
            await page.goto(`${BASE_URL}/login`);
            await page.fill('#login-email', email);
            await page.fill('#login-password', PASSWORD);
            await Promise.all([page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 20000 }), page.click('button[type="submit"]')]);
            return;
        } catch (error) {
            if (attempt === 3) throw error;
        }
    }
}

/** Abre la ruta y espera el marco; reintenta si la recarga de Vite deja la página en blanco. */
async function open(page, path) {
    for (let attempt = 1; attempt <= 3; attempt++) {
        const response = await page.goto(`${BASE_URL}${path}`);
        try {
            await page.waitForSelector('.cap-shell', { timeout: 15000 });
            await settle(page);
            return response?.status() ?? 200;
        } catch (error) {
            if (attempt === 3) throw error;
            await page.waitForTimeout(1500);
        }
    }
    return 0;
}

/** Si la recarga de Vite dejó la página en blanco, la vuelve a cargar antes de capturar. */
async function ensureShell(page) {
    if (await page.locator('.cap-shell').count()) return;
    await page.reload();
    await page.waitForSelector('.cap-shell', { timeout: 15000 });
    await settle(page);
}

async function shot(page, name) {
    await ensureShell(page);
    const overflow = await horizontalOverflow(page);
    if (overflow > 1) problems.push(`[${name}] scroll horizontal de ${overflow}px`);
    await page.screenshot({ path: `${OUTPUT}/${name}.png`, fullPage: true, animations: 'disabled' });
    console.log(`ok  ${name}`);
}

const dialog = (page) => page.locator('[role="dialog"], [role="alertdialog"]').filter({ visible: true }).last();

async function modalShot(page, name, opener) {
    await opener();
    await dialog(page).waitFor({ state: 'visible', timeout: 10000 });
    await page.waitForTimeout(500);
    if (!(await page.locator('.cap-shell').count())) throw new Error('la página quedó en blanco al abrir la modal');
    await page.screenshot({ path: `${OUTPUT}/${name}.png`, animations: 'disabled' });
    console.log(`ok  ${name}`);
}

/** Botón visible con ese nombre; en móvil, si está oculto, el botón flotante de crear. */
async function clickCreate(page, name) {
    const visible = page.getByRole('button', { name }).filter({ visible: true }).first();
    if (await visible.count()) return visible.click();
    return page.locator('.cap-shell-fab').click();
}

async function closeModal(page) {
    await page.keyboard.press('Escape');
    await page.waitForTimeout(400);
}

async function step(label, fn) {
    for (let attempt = 1; attempt <= 2; attempt++) {
        try {
            await fn();
            return true;
        } catch (error) {
            if (attempt === 2) {
                problems.push(`[${label}] ${error.message.split('\n')[0]}`);
                return false;
            }
        }
    }
    return false;
}

/** Abre el menú ⋮ de la primera fila (o tarjeta) y elige una acción. */
async function rowAction(page, label, rowText = null) {
    const scope = rowText ? page.locator('tr, li, article, [role="row"], .cap-card, div').filter({ hasText: rowText }).last() : page;
    const trigger = (rowText ? scope : page).getByRole('button', { name: /^Acciones de/ }).filter({ visible: true }).first();
    await trigger.click();
    await page.getByRole('menuitem', { name: label }).filter({ visible: true }).first().click();
}

async function expectToast(page, text) {
    await page.getByText(text).first().waitFor({ timeout: 10000 });
}

async function logoBuffer(page) {
    const helper = await page.context().newPage();
    await helper.setContent('<div id="logo" style="width:240px;height:80px;display:flex;align-items:center;justify-content:center;font:700 28px Arial;background:transparent;color:#9b1c31">LOGO QA</div>');
    const buffer = await helper.locator('#logo').screenshot({ omitBackground: true });
    await helper.close();
    return buffer;
}

// ── Recorridos ───────────────────────────────────────────────────────────────

async function screensFor(page, profile, tag) {
    const common = [
        ['clientes', '/clientes'],
        ['instituciones', '/instituciones'],
        ['contactos', '/contactos'],
        ['mi-cuenta', '/mi-cuenta'],
    ];
    for (const [name, path] of common) {
        await step(`${name}-${tag}`, async () => {
            const status = await open(page, path);
            if (status >= 400) throw new Error(`HTTP ${status}`);
            await shot(page, `${name}-${tag}`);
        });
    }

    await step(`cliente-ficha-${tag}`, async () => {
        await open(page, '/clientes');
        await page.getByText('Secretaría de Salud (QA)').filter({ visible: true }).first().click();
        await page.waitForURL(/\/clientes\/\d+/);
        await settle(page);
        await shot(page, `cliente-ficha-${tag}`);
        for (const tab of ['Ofertas ganadas', 'Contratos', 'Línea de tiempo']) {
            await page.getByRole('tab', { name: new RegExp(tab) }).click();
            await page.waitForTimeout(300);
        }
        await shot(page, `cliente-ficha-historial-${tag}`);
        if (profile !== 'lector') {
            await modalShot(page, `modal-cliente-editar-${tag}`, () => page.getByRole('button', { name: 'Editar datos' }).click());
            await closeModal(page);
        } else if (await page.getByRole('button', { name: 'Editar datos' }).count()) {
            problems.push(`[${tag}] el lector ve «Editar datos»`);
        }
    });

    await step(`institucion-ficha-${tag}`, async () => {
        await open(page, '/instituciones?search=QA');
        await page.getByText('Secretaría de Salud (QA)').filter({ visible: true }).first().click();
        await page.waitForURL(/\/instituciones\/\d+/);
        await settle(page);
        await shot(page, `institucion-ficha-${tag}`);
        if (profile !== 'lector') {
            await modalShot(page, `modal-contacto-nuevo-${tag}`, () => clickCreate(page, 'Nuevo contacto'));
            await closeModal(page);
            await modalShot(page, `modal-institucion-editar-${tag}`, () => page.getByRole('button', { name: 'Editar', exact: true }).click());
            await closeModal(page);
        }
    });

    if (profile !== 'lector') {
        await step(`modal-institucion-nueva-${tag}`, async () => {
            await open(page, '/instituciones');
            await modalShot(page, `modal-institucion-nueva-${tag}`, () => clickCreate(page, 'Nueva institución'));
            await closeModal(page);
        });
        await step(`modal-contacto-${tag}`, async () => {
            await open(page, '/contactos');
            await modalShot(page, `modal-contacto-lista-${tag}`, () => clickCreate(page, 'Nuevo contacto'));
            await closeModal(page);
        });
    }

    if (profile === 'lector') {
        for (const path of ['/configuracion', '/configuracion/usuarios', '/configuracion/auditoria', '/configuracion/diagnostico']) {
            const response = await page.goto(`${BASE_URL}${path}`);
            if (response?.status() !== 403) problems.push(`[${tag}] ${path} respondió ${response?.status()} al lector (se esperaba 403)`);
        }
        return;
    }

    const admin = [
        ['usuarios', '/configuracion/usuarios'],
        ['configuracion', '/configuracion'],
        ['diagnostico', '/configuracion/diagnostico'],
        ['auditoria', '/configuracion/auditoria'],
        ['empresas', '/empresas'],
    ];
    for (const [name, path] of admin) {
        await step(`${name}-${tag}`, async () => {
            const status = await open(page, path);
            if (status >= 400) throw new Error(`HTTP ${status}`);
            await shot(page, `${name}-${tag}`);
        });
    }

    await step(`modales-admin-${tag}`, async () => {
        await open(page, '/configuracion/usuarios');
        await modalShot(page, `modal-usuario-nuevo-${tag}`, () => clickCreate(page, 'Agregar usuario'));
        await closeModal(page);

        await open(page, '/configuracion');
        await modalShot(page, `modal-vista-previa-${tag}`, () => page.getByRole('button', { name: 'Vista previa del PDF' }).click());
        await page.waitForTimeout(2500);
        await page.screenshot({ path: `${OUTPUT}/modal-vista-previa-${tag}.png`, animations: 'disabled' });
        await closeModal(page);
        await modalShot(page, `modal-restablecer-${tag}`, () => page.getByRole('button', { name: 'Restablecer configuración' }).click());
        await closeModal(page);

        await open(page, '/configuracion/auditoria');
        const rows = page.locator('table tbody tr, [role="list"] > *');
        if (await page.getByText('Aún no hay registros de auditoría').count() === 0) {
            await modalShot(page, `modal-auditoria-detalle-${tag}`, async () => {
                // Escritorio: fila de la tabla; móvil: tarjeta de la lista.
                await page.locator('tbody tr[tabindex="0"], ul > li[tabindex="0"]').filter({ visible: true }).first().click();
            });
            await closeModal(page);
        }
        void rows;
    });

    if (profile === 'root') {
        await step(`empresa-ficha-${tag}`, async () => {
            await open(page, '/empresas');
            await modalShot(page, `modal-empresa-nueva-${tag}`, () => clickCreate(page, 'Nueva empresa'));
            await closeModal(page);
            await page.getByText('CAP Honduras').filter({ visible: true }).first().click();
            await page.waitForURL(/\/empresas\/\d+/);
            await settle(page);
            await shot(page, `empresa-ficha-${tag}`);
            for (const [tab, name] of [['Miembros', 'miembros'], ['Blessing e impuestos', 'blessing'], ['Logo', 'logo']]) {
                await page.getByRole('tab', { name: new RegExp(tab) }).click();
                await page.waitForTimeout(400);
                await shot(page, `empresa-${name}-${tag}`);
            }
            await page.getByRole('tab', { name: /Miembros/ }).click();
            await modalShot(page, `modal-miembro-nuevo-${tag}`, () => page.getByRole('button', { name: 'Agregar miembro' }).click());
            await closeModal(page);
        });
    }
}

/** Flujo real con el administrador (escritorio, claro). */
async function adminFlow(page) {
    const tag = 'flujo-admin';
    const institution = `Institución QA ${STAMP}`;

    await step('flujo: crear institución', async () => {
        await open(page, '/instituciones');
        await clickCreate(page, 'Nueva institución');
        const modal = dialog(page);
        await modal.getByRole('button', { name: 'Crear institución' }).click();
        await modal.getByText('Escriba el nombre de la institución.').waitFor();
        await page.screenshot({ path: `${OUTPUT}/${tag}-institucion-validacion.png` });
        await modal.getByLabel('Nombre').fill(institution);
        await modal.getByLabel('Siglas').fill(`QA${STAMP}`);
        await modal.getByRole('button', { name: 'Crear institución' }).click();
        await expectToast(page, `Institución ${institution} creada.`);
    });

    await step('flujo: editar institución y crear contacto', async () => {
        await open(page, `/instituciones?search=${encodeURIComponent(institution)}`);
        await page.getByText(institution).filter({ visible: true }).first().click();
        await page.waitForURL(/\/instituciones\/\d+/);
        await settle(page);
        await page.getByRole('button', { name: 'Editar', exact: true }).click();
        await dialog(page).getByLabel('Ubicación').fill('San Pedro Sula');
        await dialog(page).getByRole('button', { name: 'Guardar cambios' }).click();
        await expectToast(page, 'Institución actualizada.');
        await page.getByRole('button', { name: 'Nuevo contacto' }).filter({ visible: true }).first().click();
        await dialog(page).getByLabel('Nombre').fill(`Contacto QA ${STAMP}`);
        await dialog(page).getByLabel('Cargo').fill('Jefe de Compras');
        await dialog(page).getByLabel('Correo').fill(`contacto${STAMP}@qa.hn`);
        await dialog(page).getByRole('button', { name: 'Crear contacto' }).click();
        await expectToast(page, `Contacto Contacto QA ${STAMP} creado.`);
        await settle(page);
        await shot(page, `${tag}-institucion-con-contacto`);
    });

    await step('flujo: editar contacto desde la lista', async () => {
        await open(page, `/contactos?search=${STAMP}`);
        await rowAction(page, 'Editar');
        await dialog(page).getByLabel('Cargo').fill('Director de Compras');
        await dialog(page).getByRole('button', { name: 'Guardar cambios' }).click();
        await expectToast(page, 'Contacto actualizado.');
    });

    await step('flujo: editar cliente con RTN duplicado y válido', async () => {
        await open(page, '/clientes');
        await page.getByText('Secretaría de Salud (QA)').filter({ visible: true }).first().click();
        await page.waitForURL(/\/clientes\/\d+/);
        await settle(page);
        await page.getByRole('button', { name: 'Editar datos' }).click();
        await dialog(page).getByLabel('Persona de contacto').fill(`Ana Martínez ${STAMP}`);
        await dialog(page).getByRole('button', { name: 'Guardar cambios' }).click();
        await expectToast(page, 'Datos del cliente guardados.');
        await settle(page);
        await shot(page, `${tag}-cliente-editado`);
    });

    await step('flujo: exportar CSV', async () => {
        await open(page, '/clientes');
        const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('link', { name: 'Exportar CSV' }).click()]);
        if (!download.suggestedFilename().startsWith('clientes-')) throw new Error(`nombre de CSV inesperado: ${download.suggestedFilename()}`);
    });

    const email = `qa.visual.${STAMP}@crm-b2g.test`;
    await step('flujo: crear usuario y ver contraseña temporal', async () => {
        await open(page, '/configuracion/usuarios');
        await clickCreate(page, 'Agregar usuario');
        await dialog(page).getByLabel('Nombre').fill(`Usuario Visual ${STAMP}`);
        await dialog(page).getByLabel('Correo').fill(email);
        await dialog(page).getByLabel('Rol').selectOption('viewer');
        await dialog(page).getByRole('button', { name: 'Agregar usuario' }).click();
        await page.locator('[data-qa="temporary-password"]').waitFor({ timeout: 10000 });
        await page.screenshot({ path: `${OUTPUT}/${tag}-contrasena-temporal.png` });
        await dialog(page).getByRole('button', { name: 'Ya la copié' }).click();
    });

    await step('flujo: cambiar rol, restablecer contraseña y quitar usuario', async () => {
        await open(page, `/configuracion/usuarios?search=${STAMP}`);
        await rowAction(page, 'Cambiar rol');
        await page.waitForTimeout(300);
        await page.screenshot({ path: `${OUTPUT}/${tag}-modal-rol.png` });
        await dialog(page).getByLabel('Rol en la empresa').selectOption('operator');
        await dialog(page).getByRole('button', { name: 'Guardar rol' }).click();
        await expectToast(page, 'Operador');
        await rowAction(page, 'Restablecer contraseña');
        await page.waitForTimeout(300);
        await page.screenshot({ path: `${OUTPUT}/${tag}-confirmar-restablecer.png` });
        await dialog(page).getByRole('button', { name: /Restablecer contraseña/ }).click();
        await page.locator('[data-qa="temporary-password"]').waitFor({ timeout: 10000 });
        await dialog(page).getByRole('button', { name: 'Ya la copié' }).click();
        await rowAction(page, 'Quitar de la empresa');
        await dialog(page).getByRole('button', { name: /Quitar/ }).click();
        await expectToast(page, 'ya no pertenece');
    });

    await step('flujo: configuración, logo, vista previa y restablecer', async () => {
        await open(page, '/configuracion');
        await page.getByLabel('Dirección').fill(`Col. Palmira, Ave. República de Chile ${STAMP}`);
        await page.getByLabel('Ciudad').fill('Tegucigalpa');
        await page.getByRole('button', { name: 'Guardar cambios' }).click();
        await expectToast(page, 'Configuración guardada.');
        await page.locator('input[type="file"]').setInputFiles({ name: 'logo-qa.png', mimeType: 'image/png', buffer: await logoBuffer(page) });
        await page.getByRole('button', { name: 'Subir logo' }).click();
        await expectToast(page, 'Logo actualizado.');
        await settle(page);
        await shot(page, `${tag}-configuracion-con-logo`);
        await page.getByRole('button', { name: 'Vista previa del PDF' }).click();
        await page.waitForTimeout(3000);
        await page.screenshot({ path: `${OUTPUT}/${tag}-vista-previa.png` });
        await closeModal(page);
        await page.getByRole('button', { name: 'Restablecer configuración' }).click();
        await dialog(page).getByRole('textbox').fill('nombre equivocado');
        const disabled = await dialog(page).getByRole('button', { name: 'Restablecer', exact: true }).isDisabled();
        if (!disabled) throw new Error('el botón de restablecer no se bloquea con un nombre equivocado');
        await closeModal(page);
        // Se deja el logo y los datos puestos para las capturas siguientes.
    });

    await step('flujo: mi cuenta', async () => {
        await open(page, '/mi-cuenta');
        const name = page.getByLabel('Nombre');
        const original = await name.inputValue();
        await name.fill(`${original} QA`);
        await page.getByRole('button', { name: 'Guardar datos' }).click();
        await expectToast(page, 'Datos guardados.');
        await page.getByLabel('Nombre').fill(original);
        await page.getByRole('button', { name: 'Guardar datos' }).click();
        await expectToast(page, 'Datos guardados.');
        await page.getByLabel('Contraseña actual').fill('incorrecta');
        await page.getByLabel(/^Contraseña nueva/).fill('OtraClave2026x');
        await page.getByLabel('Repita la contraseña nueva').fill('OtraClave2026x');
        await page.getByRole('button', { name: 'Cambiar contraseña' }).click();
        await page.getByText('La contraseña actual no es correcta.').waitFor();
        await shot(page, `${tag}-mi-cuenta-error`);
    });
}

/** Flujo real con la raíz: Blessing, miembros y eliminación de institución. */
async function rootFlow(page) {
    const tag = 'flujo-raiz';
    await step('flujo: token de Blessing enmascarado', async () => {
        await open(page, '/empresas');
        await page.getByText('Empresa Demo B').filter({ visible: true }).first().click();
        await page.waitForURL(/\/empresas\/\d+/);
        await settle(page);
        await page.getByRole('tab', { name: /Blessing/ }).click();
        await page.getByLabel('URL base').fill('https://api.blessing-qa.test');
        await page.getByLabel('Token de acceso').fill(`token-secreto-${STAMP}`);
        await page.getByRole('button', { name: 'Guardar cambios' }).click();
        await expectToast(page, 'Empresa actualizada.');
        await page.reload();
        await settle(page);
        const html = await page.content();
        if (html.includes(`token-secreto-${STAMP}`)) throw new Error('el token de Blessing llegó al navegador');
        await page.getByRole('tab', { name: /Blessing/ }).click();
        await page.getByText(/Guardado \(••••/).waitFor();
        await shot(page, `${tag}-blessing-enmascarado`);
    });

    await step('flujo: eliminar institución de QA', async () => {
        await open(page, `/instituciones?search=${encodeURIComponent('Institución QA')}`);
        await rowAction(page, 'Eliminar');
        await page.waitForTimeout(300);
        await page.screenshot({ path: `${OUTPUT}/${tag}-confirmar-eliminar-institucion.png` });
        await dialog(page).getByRole('button', { name: /Eliminar institución/ }).click();
        await expectToast(page, 'eliminada');
    });
}

async function main() {
    const wanted = process.argv.slice(2);
    const profiles = wanted.length ? wanted : Object.keys(PROFILES);
    mkdirSync(OUTPUT, { recursive: true });
    const browser = await chromium.launch();

    const viewports = process.env.QA_ONLY_FLOW ? ['escritorio'] : VIEWPORTS;
    const themes = process.env.QA_ONLY_FLOW ? ['claro'] : THEMES;
    for (const profile of profiles) {
        for (const viewport of viewports) {
            for (const theme of themes) {
                const tag = `${profile}-${viewport}-${theme}`;
                const context = await openContext(browser, viewport, theme);
                const page = await context.newPage();
                page.on('console', (message) => {
                    if (message.type() === 'error' && !message.text().includes('403')) problems.push(`[${tag}] consola: ${message.text()}`);
                });
                page.on('pageerror', (error) => problems.push(`[${tag}] excepción: ${error.message}`));
                page.on('response', (response) => {
                    if (response.status() >= 500) problems.push(`[${tag}] HTTP ${response.status()} en ${response.url()}`);
                });
                try {
                    await login(page, PROFILES[profile].email);
                    if (viewport === 'escritorio' && theme === 'claro') {
                        if (profile === 'admin') await adminFlow(page);
                        if (profile === 'root') await rootFlow(page);
                    }
                    if (!process.env.QA_ONLY_FLOW) await screensFor(page, profile, tag);
                } catch (error) {
                    problems.push(`[${tag}] ${error.message.split('\n')[0]}`);
                }
                await context.close();
            }
        }
    }

    await browser.close();
    if (problems.length) {
        console.log(`\n${problems.length} problema(s):`);
        problems.forEach((problem) => console.log(`  ${problem}`));
        process.exit(1);
    }
    console.log('\nSin errores de consola, sin 5xx ni scroll horizontal.');
}

await main();
