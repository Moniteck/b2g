// Enlace del Dashboard «Pipeline por etapa»: /pipeline?etapa=<clave>.
// En 390 (móvil) el segmentado queda en esa etapa y su columna es la visible; en 1440 la columna
// queda a la vista y se resalta un momento. Capturas en storage/app/qa/capturas/pipeline/.
//
//   node tests/visual/pipeline-etapa.mjs
import { mkdirSync } from 'node:fs';
import { chromium } from 'playwright';
import { login } from '../perf/lib.mjs';

const BASE_URL = process.env.QA_URL ?? 'http://127.0.0.1:8000';
const OUTPUT = 'storage/app/qa/capturas/pipeline';
const STAGE = process.env.QA_STAGE ?? 'adjudicado';
mkdirSync(OUTPUT, { recursive: true });

const problems = [];
const browser = await chromium.launch();

for (const [width, height] of [[1440, 900], [390, 844]]) {
    const page = await browser.newPage({ viewport: { width, height } });
    page.on('pageerror', (error) => problems.push(`${width}: ${String(error).slice(0, 200)}`));
    await login(page, BASE_URL);
    await page.goto(`${BASE_URL}/pipeline?etapa=${STAGE}`);
    const column = page.locator(`[data-cap-kanban-column="${STAGE}"]`);
    await column.waitFor({ timeout: 60000 });
    await page.waitForTimeout(1200);

    const highlighted = await column.getAttribute('data-stage-focus');
    if (highlighted !== 'true') problems.push(`${width}: la columna no se resaltó`);

    if (width < 1024) {
        const pressed = await page.locator(`[data-cap-kanban-picker] button[value="${STAGE}"]`).getAttribute('aria-pressed');
        if (pressed !== 'true') problems.push(`${width}: el segmentado no quedó en ${STAGE}`);
        if ((await column.getAttribute('data-active')) !== 'true') problems.push(`${width}: la columna ${STAGE} no es la visible`);
    } else {
        const box = await column.boundingBox();
        if (!box || box.x < 0 || box.x + box.width > width + 1) problems.push(`${width}: la columna no quedó a la vista (${JSON.stringify(box)})`);
    }

    await page.screenshot({ path: `${OUTPUT}/etapa-${STAGE}-${width}.png` });
    await page.waitForTimeout(2500);
    if ((await column.getAttribute('data-stage-focus')) === 'true') problems.push(`${width}: el resaltado no se quitó`);
    await page.close();
}

await browser.close();
console.log(problems.length ? `Problemas:\n${problems.join('\n')}` : 'etapa: todo bien en 1440 y 390');
process.exit(problems.length ? 1 : 0);
