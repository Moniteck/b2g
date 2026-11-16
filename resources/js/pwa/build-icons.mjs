// Genera los íconos de la app instalable a partir del SVG de Lici (resources/js/pwa/lici-svg.mjs).
// Rasteriza con Chromium (Playwright) y escribe en public/icons/ y public/favicon.ico.
//
//   node resources/js/pwa/build-icons.mjs
//
// Los PNG se versionan en el repositorio: solo hay que volver a correrlo si cambia el dibujo o la marca.
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { chromium } from 'playwright';
import { iconSvg, liciSvg, colors } from '@/pwa/lici-svg.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const out = resolve(root, 'public/icons');
mkdirSync(out, { recursive: true });

const browser = await chromium.launch();
const page = await browser.newPage({ deviceScaleFactor: 1 });

async function rasterize(svg, size, file, filter = 'none') {
    await page.setViewportSize({ width: size, height: size });
    await page.setContent(`<!DOCTYPE html><html><body style="margin:0;background:transparent">${svg.replace('<svg ', `<svg style="display:block;width:${size}px;height:${size}px;filter:${filter}" `)}</body></html>`);
    const png = await page.screenshot({ omitBackground: true, clip: { x: 0, y: 0, width: size, height: size } });
    if (file) writeFileSync(resolve(out, file), png);
    return png;
}

// Íconos del manifest y de iOS.
writeFileSync(resolve(out, 'lici.svg'), liciSvg());
writeFileSync(resolve(out, 'favicon.svg'), iconSvg({ size: 64 }));
for (const size of [192, 512]) {
    await rasterize(iconSvg({ size }), size, `icon-${size}.png`);
    await rasterize(iconSvg({ size, maskable: true }), size, `icon-maskable-${size}.png`);
}
await rasterize(iconSvg({ size: 180, maskable: true }), 180, 'apple-touch-icon.png'); // iOS redondea solo: fondo a sangre.
// Insignia de Android: silueta blanca sobre transparente (el sistema solo usa el canal alfa).
await rasterize(liciSvg(), 96, 'badge-96.png', 'brightness(0) invert(1)');

// Atajos: glifo MDI blanco sobre el color del marco.
const mdiCss = pathToFileURL(resolve(root, 'node_modules/@mdi/font/css/materialdesignicons.min.css')).href;
const shortcuts = { 'shortcut-quote': 'file-document-plus-outline', 'shortcut-capture': 'tray-arrow-down', 'shortcut-calendar': 'calendar-month-outline', 'shortcut-pipeline': 'view-column-outline' };
for (const [file, icon] of Object.entries(shortcuts)) {
    await page.setViewportSize({ width: 96, height: 96 });
    // Página file:// temporal: desde about:blank Chromium no carga la hoja de íconos local.
    const html = resolve(tmpdir(), `crm-b2g-${file}.html`);
    writeFileSync(html, `<!DOCTYPE html><html><head><link rel="stylesheet" href="${mdiCss}"></head><body style="margin:0;background:transparent">
        <div style="width:96px;height:96px;border-radius:22px;background:${colors.chrome};display:grid;place-items:center">
        <i class="mdi mdi-${icon}" style="font-size:56px;line-height:1;color:${colors.white}"></i></div></body></html>`);
    await page.goto(pathToFileURL(html).href);
    await page.evaluate(() => document.fonts.ready);
    writeFileSync(resolve(out, `${file}.png`), await page.screenshot({ omitBackground: true, clip: { x: 0, y: 0, width: 96, height: 96 } }));
}

// favicon.ico con PNG de 16, 32 y 48 dentro (formato ICO con imágenes PNG, admitido desde Windows Vista).
const sizes = [16, 32, 48];
const images = [];
for (const size of sizes) images.push(await rasterize(iconSvg({ size: 64 }), size));
const header = Buffer.alloc(6 + 16 * sizes.length);
header.writeUInt16LE(0, 0);
header.writeUInt16LE(1, 2);
header.writeUInt16LE(sizes.length, 4);
let offset = header.length;
sizes.forEach((size, index) => {
    const entry = 6 + index * 16;
    header.writeUInt8(size, entry);
    header.writeUInt8(size, entry + 1);
    header.writeUInt16LE(1, entry + 4);
    header.writeUInt16LE(32, entry + 6);
    header.writeUInt32LE(images[index].length, entry + 8);
    header.writeUInt32LE(offset, entry + 12);
    offset += images[index].length;
});
writeFileSync(resolve(root, 'public/favicon.ico'), Buffer.concat([header, ...images]));

// Lici «sin conexión» para public/offline.html (se incrusta a mano en el HTML; aquí solo se muestra).
if (process.argv.includes('--offline-svg')) console.log(liciSvg({ state: 'offline', width: 150, label: 'Lici preocupado: no hay conexión' }));

await browser.close();
console.log('Íconos generados en public/icons y public/favicon.ico:', readFileSync(resolve(root, 'public/favicon.ico')).length, 'bytes de favicon.');
