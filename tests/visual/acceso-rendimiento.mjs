// Rendimiento de la pantalla de acceso: tareas largas, fotogramas perdidos y scroll de página.
//
//   node tests/visual/acceso-rendimiento.mjs [etiqueta]
//
// Por cada tamaño de escritorio y cada cara: 10 s quieto y 10 s moviendo el puntero. Mide con
// PerformanceObserver('longtask'), los intervalos entre requestAnimationFrame (fotograma perdido:
// más de 25 ms) y las métricas de CDP Performance (LayoutCount, RecalcStyleCount, ScriptDuration).
// También registra si la página llegó a tener scroll vertical en algún momento.
import { chromium } from 'playwright';

const BASE_URL = process.env.QA_URL ?? 'http://localhost:8000';
const LABEL = process.argv[2] ?? 'medicion';
const SECONDS = Number(process.env.QA_SECONDS ?? 10);
const SIZES = [
    { width: 1530, height: 920 },
    { width: 1280, height: 720 },
];

const browser = await chromium.launch({ args: ['--enable-gpu-rasterization'] });
const rows = [];

for (const size of SIZES) {
    for (const path of ['/login', '/solicitar-acceso']) {
        const context = await browser.newContext({ viewport: size, deviceScaleFactor: 1 });
        await context.addInitScript(() => {
            sessionStorage.setItem('crm.acceso.intro', '1');
            window.__perf = { long: [], gaps: [], scroll: 0 };
            new PerformanceObserver((list) => list.getEntries().forEach((entry) => window.__perf.long.push(Math.round(entry.duration)))).observe({ type: 'longtask', buffered: true });
            let last = 0;
            const tick = (time) => {
                if (last) window.__perf.gaps.push(time - last);
                last = time;
                if (document.documentElement.scrollHeight > window.innerHeight) window.__perf.scroll++;
                requestAnimationFrame(tick);
            };
            requestAnimationFrame(tick);
        });
        const page = await context.newPage();
        const cdp = await context.newCDPSession(page);
        await cdp.send('Performance.enable');
        await page.goto(`${BASE_URL}${path}`);
        await page.waitForSelector('.auth .auth-card');
        await page.waitForSelector('.auth-intro', { state: 'detached', timeout: 8000 });
        await page.waitForTimeout(2500);

        for (const phase of ['quieto', 'puntero']) {
            await page.evaluate(() => {
                window.__perf.long = [];
                window.__perf.gaps = [];
                window.__perf.scroll = 0;
            });
            const before = Object.fromEntries((await cdp.send('Performance.getMetrics')).metrics.map((metric) => [metric.name, metric.value]));
            if (phase === 'quieto') {
                await page.waitForTimeout(SECONDS * 1000);
            } else {
                const end = Date.now() + SECONDS * 1000;
                let step = 0;
                while (Date.now() < end) {
                    const x = size.width * (0.5 + 0.45 * Math.sin(step / 9));
                    const y = size.height * (0.5 + 0.4 * Math.cos(step / 13));
                    await page.mouse.move(x, y);
                    step++;
                    await page.waitForTimeout(16);
                }
            }
            const after = Object.fromEntries((await cdp.send('Performance.getMetrics')).metrics.map((metric) => [metric.name, metric.value]));
            const perf = await page.evaluate(() => window.__perf);
            const dropped = perf.gaps.filter((gap) => gap > 25).length;
            rows.push({
                tamaño: `${size.width}x${size.height}`,
                cara: path,
                fase: phase,
                fotogramas: perf.gaps.length,
                perdidos: dropped,
                peor_ms: Math.round(Math.max(0, ...perf.gaps)),
                tareas_largas: perf.long.length,
                mayor_tarea_ms: Math.max(0, ...perf.long),
                layouts: after.LayoutCount - before.LayoutCount,
                layout_ms: Math.round((after.LayoutDuration - before.LayoutDuration) * 1000),
                estilo_ms: Math.round((after.RecalcStyleDuration - before.RecalcStyleDuration) * 1000),
                estilos: after.RecalcStyleCount - before.RecalcStyleCount,
                script_ms: Math.round((after.ScriptDuration - before.ScriptDuration) * 1000),
                fotogramas_con_scroll: perf.scroll,
            });
        }
        await context.close();
    }
}

await browser.close();
console.log(`\n${LABEL}`);
console.table(rows);
