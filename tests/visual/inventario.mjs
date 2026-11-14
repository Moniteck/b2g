// Revisión visual y de flujo del módulo Inventario Blessing y Calculadora de oferta.
//
//   php artisan blessing:demo          (una vez: siembra el inventario de demostración)
//   node tests/visual/inventario.mjs
//
// Recorre, a 1440×900 y 390×844, en claro y oscuro: inventario (cantidades y totales en vivo,
// selección, detalle de producto, sincronizar, resultado, historial, llevar a cotización) con el
// administrador; la misma pantalla con el lector (sin sincronizar, sin costo, sin calculadora); y la
// calculadora de oferta (cálculo, escenarios). Capturas en storage/app/qa/capturas/inventario/.
// Termina con código 1 si algo falla, hay errores de consola o scroll horizontal.
import { mkdirSync } from 'node:fs';
import { chromium } from 'playwright';
import { horizontalOverflow, openContext, settle, watchConsole } from './capturas.mjs';

const BASE_URL = process.env.QA_URL ?? 'http://localhost:8000';
const PASSWORD = process.env.QA_PASSWORD ?? 'QaB2g#2026';
const ADMIN = 'qa.admin@crm-b2g.test';
const VIEWER = 'qa.lector@crm-b2g.test';
const OUTPUT = 'storage/app/qa/capturas/inventario';
const SELECTION_KEY = 'crm.cotizacion.desde_inventario.v1';

const problems = [];

async function login(page, email) {
    await page.goto(`${BASE_URL}/login`);
    await page.fill('#login-email', email);
    await page.fill('#login-password', PASSWORD);
    await Promise.all([page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 20000 }), page.click('button[type="submit"]')]);
}

/** Reintenta un paso: la recarga de Vite de otros agentes puede dejar la página a medias. */
async function step(page, label, action, attempts = 3) {
    for (let attempt = 1; attempt <= attempts; attempt++) {
        try {
            await action();
            return true;
        } catch (error) {
            if (attempt === attempts) {
                problems.push(`[${label}] ${error.message.split('\n')[0]}`);
                return false;
            }
            await page.waitForTimeout(1500);
            await page.reload().catch(() => {});
            await settle(page);
        }
    }
    return false;
}

async function shot(page, name) {
    await page.waitForTimeout(400);
    await page.screenshot({ path: `${OUTPUT}/${name}.png`, fullPage: true, animations: 'disabled' });
}

async function checkOverflow(page, label) {
    const overflow = await horizontalOverflow(page);
    if (overflow > 1) problems.push(`[${label}] scroll horizontal de ${overflow}px`);
}

const visible = (page, selector) => page.locator(selector).locator('visible=true').first();

async function openInventory(page, query = '') {
    await page.goto(`${BASE_URL}/inventario${query}`);
    await page.waitForSelector('.cap-shell');
    await settle(page);
}

async function adminFlow(browser, viewport, theme) {
    const label = `admin-${viewport}-${theme}`;
    const context = await openContext(browser, viewport, theme);
    const page = await context.newPage();
    problems.push(...[]);
    const seen = watchConsole(page, label);
    const mobile = viewport === 'movil';

    await login(page, ADMIN);
    await page.evaluate((key) => sessionStorage.clear(), SELECTION_KEY);

    await step(page, `${label} lista`, async () => {
        await openInventory(page);
        await page.getByText('Productos activos').locator('visible=true').first().waitFor({ timeout: 10000 });
        await checkOverflow(page, `${label} lista`);
        await shot(page, `${viewport}-${theme}-lista`);
    });

    // Buscar y escribir cantidades: subtotal, ISV y total en vivo.
    await step(page, `${label} cantidades`, async () => {
        await openInventory(page, '?q=15W-40');
        const quantity = visible(page, 'input[aria-label="Cantidad de LUB-15W40-G"]');
        await quantity.fill('3');
        await page.getByText('2,377.05').locator('visible=true').first().waitFor({ timeout: 5000 }); // 689 × 3 + 15 % ISV
        const second = visible(page, 'input[aria-label="Cantidad de LUB-15W40-C"]');
        await second.fill('2');
        // 3 195 × 2 = 6 390 + 958.50 = 7 348.50; con el primero: 9 725.55
        await page.getByText('9,725.55').locator('visible=true').first().waitFor({ timeout: 5000 });
        await checkOverflow(page, `${label} cantidades`);
        await shot(page, `${viewport}-${theme}-cantidades`);
    });

    // La selección sobrevive a otra búsqueda.
    await step(page, `${label} seleccion persistente`, async () => {
        await openInventory(page, '?q=grasa');
        await page.getByText('9,725.55').locator('visible=true').first().waitFor({ timeout: 5000 });
    });

    // Detalle de producto.
    await step(page, `${label} detalle`, async () => {
        await openInventory(page, '?q=LUB-15W40-G');
        if (mobile) await visible(page, 'li button:has-text("LUB-15W40-G")').click();
        else await visible(page, 'td button:has-text("LUB-15W40-G")').click();
        const dialog = page.getByRole('dialog');
        await dialog.getByText('Historial de precio').waitFor({ timeout: 5000 });
        await dialog.getByText('Precio de 3').waitFor({ timeout: 5000 });
        await page.waitForTimeout(600);
        await shot(page, `${viewport}-${theme}-detalle`);
        await page.keyboard.press('Escape');
        await dialog.waitFor({ state: 'hidden', timeout: 5000 });
    });

    // Sincronizar ahora y ver el resultado.
    await step(page, `${label} sincronizar`, async () => {
        await openInventory(page);
        await visible(page, 'button:has-text("Sincronizar ahora")').click();
        const dialog = page.getByRole('dialog', { name: 'Resultado de sincronización' });
        await dialog.waitFor({ timeout: 20000 });
        await dialog.getByText('Inventario actualizado').waitFor({ timeout: 5000 });
        await shot(page, `${viewport}-${theme}-resultado`);
        await dialog.getByRole('button', { name: 'Cerrar' }).last().click();
    });

    // Historial y, desde él, el resultado de una corrida.
    await step(page, `${label} historial`, async () => {
        await openInventory(page);
        await visible(page, 'button:has-text("Historial")').click();
        const dialog = page.getByRole('dialog', { name: 'Historial de sincronización' });
        await dialog.waitFor({ timeout: 5000 });
        await dialog.getByText('Completada').locator('visible=true').first().waitFor({ timeout: 8000 });
        await shot(page, `${viewport}-${theme}-historial`);
        await dialog.locator('tr[tabindex="0"], li[tabindex="0"]').locator('visible=true').first().click();
        await page.getByRole('dialog', { name: 'Resultado de sincronización' }).waitFor({ timeout: 5000 });
        await page.keyboard.press('Escape');
        await page.keyboard.press('Escape');
    });

    // Llevar a una cotización (solo una vez: escritorio claro).
    if (viewport === 'escritorio' && theme === 'claro') {
        await step(page, `${label} llevar a cotizacion`, async () => {
            await openInventory(page);
            await visible(page, 'button:has-text("Llevar a una cotización")').click();
            await page.waitForURL(/\/cotizaciones\?desde_inventario=1/, { timeout: 10000 });
            const saved = await page.evaluate((key) => JSON.parse(sessionStorage.getItem(key) ?? 'null'), SELECTION_KEY);
            if (!saved || saved.items.length !== 2 || saved.totals.total !== 9725.55) throw new Error(`Selección guardada incorrecta: ${JSON.stringify(saved)}`);
        });
    } else {
        await step(page, `${label} limpiar`, async () => {
            await openInventory(page);
            await visible(page, 'button:has-text("Limpiar")').click();
            await page.waitForTimeout(300);
            if (await page.getByText('9,725.55').count()) throw new Error('La selección no se limpió.');
        });
    }

    // Calculadora de oferta.
    await step(page, `${label} calculadora`, async () => {
        await page.goto(`${BASE_URL}/calculadora-oferta`);
        await page.waitForSelector('.cap-shell');
        await settle(page);
        const line = page.locator('[data-testid="linea-0"]');
        await line.getByLabel('Descripción').fill('Aceite 15W-40 galón');
        await line.getByLabel('Cantidad').fill('10');
        await line.getByLabel(/Costo unitario/).fill('520');
        await line.getByLabel('Margen (%)').fill('20');
        await page.locator('[data-testid="calc-total"]').filter({ hasText: '7,475.00' }).waitFor({ timeout: 8000 });
        // Producto desde el inventario con el buscador remoto.
        await page.getByRole('button', { name: 'Agregar línea' }).click();
        const second = page.locator('[data-testid="linea-1"]');
        const finder = second.getByPlaceholder('Buscar por SKU o descripción');
        await finder.click();
        await finder.pressSequentially('GRA-EP2', { delay: 50 });
        await page.locator('[role="listbox"] [role="option"]').first().click({ timeout: 8000 });
        await second.getByLabel('Cantidad').fill('5');
        await page.locator('[data-testid="linea-1-total"]').filter({ hasText: 'L' }).waitFor({ timeout: 8000 });
        await page.getByText('Escenarios de margen').waitFor();
        await page.waitForTimeout(800);
        await checkOverflow(page, `${label} calculadora`);
        await shot(page, `${viewport}-${theme}-calculadora`);
    });

    problems.push(...seen);
    await context.close();
}

async function viewerFlow(browser, viewport, theme) {
    const label = `lector-${viewport}-${theme}`;
    const context = await openContext(browser, viewport, theme);
    const page = await context.newPage();
    const seen = watchConsole(page, label);

    await login(page, VIEWER);

    await step(page, `${label} lista`, async () => {
        await openInventory(page, '?q=LUB');
        if (await page.locator('button:has-text("Sincronizar ahora")').count()) throw new Error('El lector ve «Sincronizar ahora».');
        await visible(page, 'input[aria-label="Cantidad de LUB-15W40-G"]').fill('2');
        await page.getByText('1,584.70').locator('visible=true').first().waitFor({ timeout: 5000 }); // 689 × 2 + 15 %
        if (await page.locator('button:has-text("Llevar a una cotización")').count()) throw new Error('El lector ve «Llevar a una cotización».');
        await checkOverflow(page, `${label} lista`);
        await shot(page, `${viewport}-${theme}-lector`);
        await visible(page, 'button:has-text("Limpiar")').click();
    });

    await step(page, `${label} calculadora prohibida`, async () => {
        const response = await page.goto(`${BASE_URL}/calculadora-oferta`);
        if (response?.status() !== 403) throw new Error(`El lector abrió la calculadora (HTTP ${response?.status()}).`);
    });

    // El 403 de la calculadora es esperado.
    problems.push(...seen.filter((message) => !message.includes('403')));
    await context.close();
}

async function main() {
    mkdirSync(OUTPUT, { recursive: true });
    const browser = await chromium.launch();

    for (const viewport of ['escritorio', 'movil']) {
        for (const theme of ['claro', 'oscuro']) {
            await adminFlow(browser, viewport, theme);
            await viewerFlow(browser, viewport, theme);
            console.log(`ok  ${viewport} ${theme}`);
        }
    }

    await browser.close();
    if (problems.length) {
        console.log(`\n${problems.length} problema(s):`);
        problems.forEach((problem) => console.log(`  ${problem}`));
        process.exit(1);
    }
    console.log(`\nSin errores. Capturas en ${OUTPUT}/`);
}

await main();
