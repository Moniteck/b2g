// Reproduce la carga de /reportes (filtros, períodos, módulo, exportes) y registra consola,
// errores de página y red. Solo lectura.
//
//   PERF_URL=http://127.0.0.1:8000 node tests/perf/reportes.mjs
//
// Resultado en storage/app/qa/perf/reportes-<host>.json (+ capturas).
import { mkdirSync, writeFileSync } from 'node:fs';
import { chromium } from 'playwright';
import { login, OUTPUT } from './lib.mjs';

const BASE_URL = process.env.PERF_URL ?? 'http://127.0.0.1:8000';
const tag = new URL(BASE_URL).port || 'default';
mkdirSync(OUTPUT, { recursive: true });

const browser = await chromium.launch();
const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, acceptDownloads: true });
const page = await context.newPage();

const log = { console: [], pageErrors: [], requests: [], steps: [] };
page.on('console', (m) => ['error', 'warning'].includes(m.type()) && log.console.push({ type: m.type(), text: m.text().slice(0, 600) }));
page.on('pageerror', (e) => log.pageErrors.push(String(e.stack ?? e).slice(0, 1200)));
const started = new Map();
page.on('request', (r) => started.set(r, Date.now()));
page.on('requestfinished', async (r) => {
    if (!r.url().startsWith(BASE_URL) || r.resourceType() === 'image') return;
    const res = await r.response();
    const size = (await r.sizes().catch(() => null))?.responseBodySize ?? null;
    log.requests.push({
        method: r.method(), url: r.url().replace(BASE_URL, ''), status: res?.status(), ms: Date.now() - started.get(r), bytes: size,
        partial: r.headers()['x-inertia-partial-data'] ?? null, perf: res?.headers()['x-perf-ms'] ?? null, queries: res?.headers()['x-perf-queries'] ?? null,
    });
});
page.on('requestfailed', (r) => log.requests.push({ method: r.method(), url: r.url().replace(BASE_URL, ''), failed: r.failure()?.errorText }));

async function step(name, fn) {
    const t0 = Date.now();
    let error = null;
    try { await fn(); } catch (e) { error = String(e.message ?? e).slice(0, 500); }
    const state = await page.evaluate(() => ({
        url: location.pathname + location.search,
        stats: [...document.querySelectorAll('main [class*="stat"], main dl, main .cap-stat')].length,
        rows: document.querySelectorAll('main table tbody tr').length,
        loadError: document.body.innerText.includes('No se pudo actualizar el reporte'),
        dimmed: document.querySelectorAll('main .opacity-50').length,
        validation: [...document.querySelectorAll('[role="alert"], .text-cap-error, [id$="-error"]')].map((e) => e.textContent.trim()).filter(Boolean).slice(0, 5),
    })).catch(() => null);
    log.steps.push({ name, ms: Date.now() - t0, error, state });
    await page.screenshot({ path: `${OUTPUT}/reportes-${tag}-${log.steps.length}.png`, fullPage: false }).catch(() => {});
}

const settle = () => page.waitForLoadState('networkidle', { timeout: 30000 }).catch(() => {});
// Espera la respuesta Inertia de /reportes que dispara la acción (o 15 s).
const inertia = async (action) => {
    const wait = page.waitForResponse((r) => r.url().includes('/reportes') && r.request().headers()['x-inertia'], { timeout: 15000 }).catch(() => null);
    await action();
    const res = await wait;
    await page.waitForTimeout(300);
    return res;
};
const select = (label) => page.locator('section[aria-label="Filtros del reporte"] select').nth(label === 'periodo' ? 0 : 1);
const date = (n) => page.locator('section[aria-label="Filtros del reporte"] input[type="date"]').nth(n);

await login(page, BASE_URL);
await step('abrir /reportes', async () => { await page.goto(`${BASE_URL}/reportes`); await settle(); });
await step('período: este mes', () => inertia(() => select('periodo').selectOption('month')));
await step('período: año anterior', () => inertia(() => select('periodo').selectOption('last-year')));
await step('período: este año', () => inertia(() => select('periodo').selectOption('year')));
await step('módulo: cotizaciones', () => inertia(() => select('modulo').selectOption('cotizaciones')));
await step('módulo: todos', () => inertia(() => select('modulo').selectOption('all')));
await step('fecha desde personalizada 2025-01-01', () => inertia(() => date(0).fill('2025-01-01')));
await step('rango inválido (desde > hasta)', () => inertia(() => date(0).fill('2030-01-01')));
await step('recargar con URL con filtros', async () => { await page.goto(`${BASE_URL}/reportes?from=2026-01-01&to=2026-06-30&module=pipeline`); await settle(); });
await step('volver a /reportes por el menú (navegación Inertia)', async () => {
    await page.goto(`${BASE_URL}/`); await settle();
    await inertia(() => page.locator('a[href$="/reportes"]').first().click());
});

for (const kind of ['pdf', 'xlsx', 'csv']) {
    const t0 = Date.now();
    const res = await page.request.get(`${BASE_URL}/reportes/${kind}?from=2026-01-01&to=2026-11-14`).catch((e) => ({ status: () => 0, headers: () => ({}), body: async () => Buffer.from(String(e)) }));
    const body = await res.body();
    log.steps.push({ name: `exporte ${kind}`, ms: Date.now() - t0, status: res.status(), type: res.headers()['content-type'], bytes: body.length, head: body.subarray(0, 80).toString('latin1') });
}

writeFileSync(`${OUTPUT}/reportes-${tag}.json`, JSON.stringify(log, null, 2));
console.log(JSON.stringify({ pageErrors: log.pageErrors, console: log.console.slice(0, 15), steps: log.steps.map((s) => ({ name: s.name, ms: s.ms, error: s.error, status: s.status, bytes: s.bytes, state: s.state })) }, null, 1));
await browser.close();
