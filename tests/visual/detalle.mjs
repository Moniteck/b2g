// Revisión de detalle: recorre una pantalla por tramos del alto de la ventana y captura las piezas
// que solo se ven al interactuar (modales, menús, confirmación, hoja «Más», notificaciones).
//
//   node tests/visual/detalle.mjs                      página de componentes, las cuatro combinaciones
//   node tests/visual/detalle.mjs movil oscuro         una sola combinación
//   node tests/visual/detalle.mjs escritorio claro dashboard   otra pantalla (solo tramos)
//
// Las imágenes quedan en storage/app/qa/capturas/detalle/.
import { mkdirSync } from 'node:fs';
import { chromium } from 'playwright';
import { SCREENS, horizontalOverflow, login, openContext, openScreen, settle, watchConsole } from './capturas.mjs';

const OUTPUT = 'storage/app/qa/capturas/detalle';
const [onlyViewport, onlyTheme, screenName = 'componentes'] = process.argv.slice(2);
const viewports = onlyViewport ? [onlyViewport] : ['escritorio', 'movil'];
const themes = onlyTheme ? [onlyTheme] : ['claro', 'oscuro'];

async function shot(page, label, name) {
    await page.waitForTimeout(450);
    await page.screenshot({ path: `${OUTPUT}/${label}-${name}.png` });
}

async function tiles(page, label) {
    const height = page.viewportSize().height;
    const total = await page.evaluate(() => document.documentElement.scrollHeight);
    const step = height - 140;
    for (let index = 0, top = 0; top < total; index += 1, top += step) {
        await page.evaluate((y) => window.scrollTo(0, y), top);
        await shot(page, label, `tramo-${String(index).padStart(2, '0')}`);
    }
    await page.evaluate(() => window.scrollTo(0, 0));
}

async function closeModal(page) {
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);
}

async function interactions(page, label, mobile, problems) {
    const step = async (name, action) => {
        try {
            await action();
        } catch (error) {
            problems.push(`[${label}] ${name}: ${error.message.split('\n')[0]}`);
        }
    };
    const button = (text) => page.getByRole('button', { name: text, exact: true }).first();

    await step('menú de fila', async () => {
        await page.getByRole('button', { name: 'Acciones de LPN-SESAL-012-2026' }).first().click();
        await shot(page, label, 'menu-fila');
        await page.keyboard.press('Escape');
    });

    await step('combo con búsqueda', async () => {
        const combo = page.getByRole('combobox').first();
        await combo.scrollIntoViewIfNeeded();
        await combo.click();
        await combo.fill('sec');
        await shot(page, label, 'combo');
        await page.keyboard.press('Escape');
    });

    await step('modal grande', async () => {
        await button('Grande con formulario').click();
        await shot(page, label, 'modal-grande');
        const overflow = await horizontalOverflow(page);
        if (overflow > 1) problems.push(`[${label}] modal grande con scroll horizontal de ${overflow}px`);
        await page.getByRole('dialog').getByRole('combobox').first().click();
        await shot(page, label, 'modal-grande-combo');
        await page.keyboard.press('Escape');
        await closeModal(page);
    });

    await step('modal pequeña', async () => {
        await button('Pequeña').click();
        await shot(page, label, 'modal-pequena');
        await closeModal(page);
    });

    await step('modales apiladas y confirmación', async () => {
        await button('Mediana con pestañas').click();
        await shot(page, label, 'modal-mediana');
        await page.getByRole('dialog').getByRole('button', { name: 'Marcar como perdida' }).click();
        await shot(page, label, 'modal-apilada');
        await page.getByRole('dialog').last().getByRole('button', { name: 'Marcar como perdida' }).click();
        await shot(page, label, 'confirmacion');
        const focused = await page.evaluate(() => document.activeElement?.textContent?.trim());
        if (focused !== 'Cancelar') problems.push(`[${label}] la confirmación no enfoca «Cancelar» (enfoca «${focused}»)`);
        await page.keyboard.press('Escape');
        await page.waitForTimeout(200);
        if ((await page.getByRole('dialog').count()) !== 2) problems.push(`[${label}] Esc en la confirmación cerró también una modal`);
        await closeModal(page);
        if ((await page.getByRole('dialog').count()) !== 1) problems.push(`[${label}] Esc no cerró solo la modal de arriba`);
        await closeModal(page);
        if ((await page.getByRole('dialog').count()) !== 0) problems.push(`[${label}] quedaron modales abiertas`);
    });

    await step('modal a pantalla completa con visor', async () => {
        await button('Pantalla completa').click();
        await page.waitForTimeout(900);
        await shot(page, label, 'modal-visor');
        await closeModal(page);
    });

    await step('toast', async () => {
        await button('Probar toast').click();
        await shot(page, label, 'toast');
    });

    await step('notificaciones', async () => {
        await page.locator(mobile ? '.cap-shell-topbar [aria-controls="cap-notifications"]' : '.cap-shell-header [aria-controls="cap-notifications"]').click();
        await shot(page, label, 'notificaciones');
        await page.keyboard.press('Escape');
    });

    if (mobile) {
        await step('hoja «Más»', async () => {
            await page.locator('.cap-shell-tabbar__tab--more').click();
            await shot(page, label, 'hoja-mas');
            await page.keyboard.press('Escape');
        });
    } else {
        await step('menú de usuario', async () => {
            await page.getByRole('button', { name: /Menú de usuario/ }).click();
            await shot(page, label, 'menu-usuario');
            await page.keyboard.press('Escape');
        });
        await step('sidebar asomado', async () => {
            await page.locator('.cap-shell-sidebar').hover();
            await page.waitForTimeout(500);
            await shot(page, label, 'sidebar');
            await page.mouse.move(900, 500);
        });
    }
}

mkdirSync(OUTPUT, { recursive: true });
const browser = await chromium.launch();
const problems = [];

for (const viewportName of viewports) {
    for (const theme of themes) {
        const label = `${screenName}-${viewportName}-${theme}`;
        const context = await openContext(browser, viewportName, theme);
        const page = await context.newPage();
        const seen = watchConsole(page, label);
        page.setDefaultTimeout(8000);
        await login(page);
        seen.length = 0;
        await openScreen(page, SCREENS[screenName], label, problems, seen);
        await settle(page);
        seen.length = 0;
        await tiles(page, label);
        if (screenName === 'componentes') await interactions(page, label, viewportName === 'movil', problems);
        problems.push(...seen);
        await context.close();
        console.log(`ok  ${label}`);
    }
}

await browser.close();
if (problems.length) {
    console.log(`\n${problems.length} problema(s):`);
    problems.forEach((problem) => console.log(`  ${problem}`));
    process.exit(1);
}
console.log('\nSin problemas.');
