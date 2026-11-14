// Pantalla de acceso a varios tamaños: sin scroll de página en escritorio, etiquetas completas,
// campo con foco y Lici tapándose los ojos (reposo → mitad de la transición → tapa → espía).
//
//   node tests/visual/acceso-tamanos.mjs
//
// Capturas en storage/app/qa/capturas/acceso/tamanos/. Termina con código 1 si hay scroll de página
// en escritorio, etiquetas recortadas, scroll horizontal o errores de consola.
import { mkdirSync } from 'node:fs';
import { chromium } from 'playwright';

const BASE_URL = process.env.QA_URL ?? 'http://localhost:8000';
const OUTPUT = 'storage/app/qa/capturas/acceso/tamanos';
const SIZES = [
    { name: '1280x720', width: 1280, height: 720 },
    { name: '1530x920', width: 1530, height: 920 },
    { name: '1920x1080', width: 1920, height: 1080 },
    { name: '1024x768', width: 1024, height: 768 },
    { name: '390x844', width: 390, height: 844, mobile: true },
];
mkdirSync(OUTPUT, { recursive: true });
const problems = [];
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const browser = await chromium.launch();
for (const size of SIZES) {
    for (const theme of ['claro', 'oscuro']) {
        const context = await browser.newContext({ viewport: { width: size.width, height: size.height }, isMobile: !!size.mobile, hasTouch: !!size.mobile, deviceScaleFactor: 1 });
        await context.addInitScript((dark) => {
            localStorage.setItem('theme', dark ? 'dark' : 'light');
            sessionStorage.setItem('crm.acceso.intro', '1');
        }, theme === 'oscuro');
        const page = await context.newPage();
        const label = `${size.name}-${theme}`;
        page.on('console', (message) => message.type() === 'error' && problems.push(`[${label}] consola: ${message.text()}`));
        page.on('pageerror', (error) => problems.push(`[${label}] excepción: ${error.message}`));

        for (const path of ['/login', '/solicitar-acceso']) {
            await page.goto(`${BASE_URL}${path}`);
            await page.waitForSelector('.auth .auth-card');
            await page.waitForSelector('.auth-intro', { state: 'detached', timeout: 8000 });
            await wait(1800);
            const face = path === '/login' ? 'login' : 'registro';
            const metrics = await page.evaluate(() => ({
                scrollY: document.documentElement.scrollHeight - window.innerHeight,
                scrollX: document.documentElement.scrollWidth - window.innerWidth,
                cut: [...document.querySelectorAll('.auth-card__face:not([inert]) .auth-field__label')].filter((label) => label.scrollWidth > label.clientWidth + 1).map((label) => label.textContent.trim()),
            }));
            if (!size.mobile && metrics.scrollY > 0) problems.push(`[${label} ${face}] scroll vertical de página: ${metrics.scrollY}px`);
            if (metrics.scrollX > 0) problems.push(`[${label} ${face}] scroll horizontal: ${metrics.scrollX}px`);
            if (metrics.cut.length) problems.push(`[${label} ${face}] etiquetas recortadas: ${metrics.cut.join(', ')}`);
            await page.screenshot({ path: `${OUTPUT}/${label}-${face}.png` });

            if (path === '/solicitar-acceso' && (theme === 'claro' || size.mobile || size.name === '1530x920')) {
                // Campo enfocado: misma caja con borde de marca y halo.
                await page.focus('#request-email');
                await page.keyboard.type('ana@empresa.com');
                await page.focus('#request-password');
                await wait(80);
                await page.locator('.auth__perch').screenshot({ path: `${OUTPUT}/${label}-lici-tapa-mitad.png` });
                await wait(600);
                await page.locator('.auth__perch').screenshot({ path: `${OUTPUT}/${label}-lici-2-tapa-ojos.png` });
                await page.locator('#request-password').locator('xpath=ancestor::div[contains(@class,"auth-grid")][1]').screenshot({ path: `${OUTPUT}/${label}-campos-foco.png` });
                await page.click('#request-password ~ .auth-field__icon-button');
                await page.focus('#request-password');
                await wait(650);
                await page.locator('.auth__perch').screenshot({ path: `${OUTPUT}/${label}-lici-3-espia.png` });
            }
        }
        await context.close();
    }
}
await browser.close();

if (problems.length) {
    console.log(`${problems.length} problema(s):\n${problems.join('\n')}`);
    process.exit(1);
}
console.log(`Sin problemas. Capturas en ${OUTPUT}/`);
