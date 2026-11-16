// Recorre todas las pantallas internas como un usuario (navegación Inertia desde el menú/router) y
// registra cada petición: método, URL, tipo, tamaño, tiempo, si es Inertia completa o parcial,
// cabeceras de caché y compresión. Detecta duplicados y sondeos en reposo.
//
//   PERF_URL=http://127.0.0.1:8089 PERF_IDLE=30 node tests/perf/crawl.mjs
import { mkdirSync, writeFileSync } from 'node:fs';
import { chromium } from 'playwright';
import { login, OUTPUT, SCREENS } from './lib.mjs';

const BASE_URL = process.env.PERF_URL ?? 'http://127.0.0.1:8089';
const IDLE = Number(process.env.PERF_IDLE ?? 30);
const tag = new URL(BASE_URL).port || 'default';
mkdirSync(OUTPUT, { recursive: true });

const browser = await chromium.launch();
const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await context.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(`${page.url()} ${String(e).slice(0, 300)}`));
page.on('console', (m) => m.type() === 'error' && errors.push(`${page.url()} ${m.text().slice(0, 300)}`));

let current = 'login';
const all = [];
const startedAt = new Map();
page.on('request', (r) => startedAt.set(r, Date.now()));
page.on('requestfinished', async (r) => {
    const res = await r.response();
    const sizes = await r.sizes().catch(() => ({}));
    const h = res?.headers() ?? {};
    all.push({
        screen: current, t: Date.now(), method: r.method(), url: r.url().replace(BASE_URL, ''), type: r.resourceType(), status: res?.status(),
        ms: Date.now() - (startedAt.get(r) ?? Date.now()), bodyKb: +((sizes.responseBodySize ?? 0) / 1024).toFixed(1),
        wireKb: +(((sizes.responseBodySize ?? 0) + (sizes.responseHeadersSize ?? 0)) / 1024).toFixed(1),
        inertia: r.headers()['x-inertia'] ? (r.headers()['x-inertia-partial-data'] ? `parcial:${r.headers()['x-inertia-partial-data']}` : 'completa') : null,
        cache: h['cache-control'] ?? null, encoding: h['content-encoding'] ?? null,
        server: h['x-perf-ms'] ?? null, queries: h['x-perf-queries'] ?? null,
    });
});

await login(page, BASE_URL);
await page.waitForLoadState('networkidle').catch(() => {});

// Navegación Inertia (como el menú): router.visit sin recargar el documento.
for (const path of SCREENS) {
    current = path;
    const viaLink = await page.evaluate((p) => {
        const link = [...document.querySelectorAll('a[href]')].find((a) => new URL(a.href).pathname === p && !a.target);
        if (link) link.click(); else window.location.assign(p);
        return Boolean(link);
    }, path).catch(() => false);
    current = viaLink ? path : `${path} (documento)`;
    await page.waitForURL((u) => u.pathname === path, { timeout: 15000 }).catch(() => {});
    await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
    await page.waitForTimeout(400);
}

// Reposo en el Dashboard para detectar sondeos.
current = `reposo / (${IDLE}s)`;
await page.goto(`${BASE_URL}/`);
await page.waitForLoadState('networkidle').catch(() => {});
const idleStart = Date.now();
await page.waitForTimeout(IDLE * 1000);
const idle = all.filter((r) => r.t >= idleStart);

// Reposo en una licitación (panel de análisis de pliego).
current = `reposo /licitaciones/1 (${IDLE}s)`;
await page.goto(`${BASE_URL}/licitaciones/1`);
await page.waitForLoadState('networkidle').catch(() => {});
const idle2Start = Date.now();
await page.waitForTimeout(IDLE * 1000);
const idle2 = all.filter((r) => r.t >= idle2Start);

const byScreen = {};
for (const r of all) {
    const s = (byScreen[r.screen] ??= { requests: 0, documentOrInertia: [], scripts: 0, scriptKb: 0, css: 0, cssKb: 0, fetchXhr: [], other: 0, duplicates: [] });
    s.requests++;
    if (r.type === 'document' || r.inertia) s.documentOrInertia.push(`${r.method} ${r.url} ${r.status} ${r.ms}ms ${r.bodyKb}KB ${r.inertia ?? 'documento'}`);
    else if (r.type === 'script') { s.scripts++; s.scriptKb += r.bodyKb; }
    else if (r.type === 'stylesheet') { s.css++; s.cssKb += r.bodyKb; }
    else if (r.type === 'fetch' || r.type === 'xhr') s.fetchXhr.push(`${r.method} ${r.url} ${r.status} ${r.ms}ms ${r.bodyKb}KB`);
    else s.other++;
}
for (const [screen, s] of Object.entries(byScreen)) {
    const keys = all.filter((r) => r.screen === screen && (r.type === 'fetch' || r.type === 'xhr' || r.inertia)).map((r) => `${r.method} ${r.url}`);
    s.duplicates = [...new Set(keys.filter((k, i) => keys.indexOf(k) !== i))];
    s.scriptKb = +s.scriptKb.toFixed(1);
    s.cssKb = +s.cssKb.toFixed(1);
}

const statics = all.filter((r) => ['script', 'stylesheet', 'font', 'image'].includes(r.type));
const cacheReport = [...new Map(statics.map((r) => [r.url.split('?')[0], r])).values()].map((r) => ({ url: r.url.slice(0, 80), type: r.type, kb: r.bodyKb, cache: r.cache, encoding: r.encoding }));

writeFileSync(`${OUTPUT}/crawl-${tag}.json`, JSON.stringify({ byScreen, idle, idle2, cacheReport, errors, all }, null, 2));
for (const [screen, s] of Object.entries(byScreen)) {
    console.log(`${screen}: ${s.requests} peticiones · JS ${s.scripts} (${s.scriptKb} KB) · CSS ${s.css} · ${s.documentOrInertia.join(' | ')}${s.fetchXhr.length ? ` · fetch: ${s.fetchXhr.join(' | ')}` : ''}${s.duplicates.length ? ` · DUPLICADAS: ${s.duplicates.join(', ')}` : ''}`);
}
console.log(`Reposo Dashboard ${IDLE}s: ${idle.length} peticiones`, idle.map((r) => `${r.method} ${r.url}`).slice(0, 10));
console.log(`Reposo licitación ${IDLE}s: ${idle2.length} peticiones`, idle2.map((r) => `${r.method} ${r.url}`).slice(0, 10));
console.log('Estáticos (caché/compresión):');
for (const r of cacheReport.slice(0, 15)) console.log(`  ${r.type} ${r.kb}KB cache=${r.cache} enc=${r.encoding} ${r.url}`);
console.log('errores:', errors.slice(0, 10));
await browser.close();
