// Navegación Inertia hacia /reportes desde el menú (y de vuelta), midiendo cuánto tarda en verse
// el contenido y si la página queda atascada (atenuada, sin datos). Solo lectura.
//
//   PERF_URL=http://127.0.0.1:8000 node tests/perf/reportes-navegacion.mjs
import { mkdirSync, writeFileSync } from 'node:fs';
import { chromium } from 'playwright';
import { login, OUTPUT } from './lib.mjs';

const BASE_URL = process.env.PERF_URL ?? 'http://127.0.0.1:8000';
const tag = new URL(BASE_URL).port || 'default';
const ROUNDS = Number(process.env.PERF_ROUNDS ?? 3);
mkdirSync(OUTPUT, { recursive: true });

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
const errors = [];
page.on('pageerror', (e) => errors.push(String(e.stack ?? e).slice(0, 800)));
page.on('console', (m) => m.type() === 'error' && errors.push(m.text().slice(0, 400)));
const requests = [];
page.on('response', (r) => {
    const h = r.request().headers();
    if (h['x-inertia'] || r.request().resourceType() === 'document' || r.request().resourceType() === 'fetch') {
        requests.push({ t: Date.now(), status: r.status(), url: r.url().replace(BASE_URL, ''), partial: h['x-inertia-partial-data'] ?? null });
    }
});

await login(page, BASE_URL);
await page.goto(`${BASE_URL}/`);
await page.waitForLoadState('networkidle').catch(() => {});

const results = [];
async function go(label, href, ready) {
    const t0 = Date.now();
    const index = await page.evaluate((path) => [...document.querySelectorAll('.cap-shell-sidebar a')].findIndex((a) => new URL(a.href).pathname === path), href);
    await page.locator('.cap-shell-sidebar a').nth(index).click();
    let state = null;
    for (let i = 0; i < 60; i++) {
        state = await page.evaluate((readyText) => ({
            path: location.pathname,
            leaving: Boolean(document.querySelector('[data-m-leaving]')),
            ready: document.body.innerText.includes(readyText),
            opacity: getComputedStyle(document.querySelector('.cap-shell__content') ?? document.body).opacity,
        }), ready);
        if (state.path === href && state.ready && !state.leaving) break;
        await page.waitForTimeout(250);
    }
    results.push({ label, ms: Date.now() - t0, ...state });
    await page.screenshot({ path: `${OUTPUT}/nav-${tag}-${results.length}.png` });
}

for (let i = 0; i < ROUNDS; i++) {
    await go(`→ Reportes (${i + 1})`, '/reportes', 'Indicadores del período');
    await go(`→ Dashboard (${i + 1})`, '/', 'Alertas activas');
}

writeFileSync(`${OUTPUT}/reportes-navegacion-${tag}.json`, JSON.stringify({ results, errors, requests }, null, 2));
console.log(JSON.stringify({ results, errors }, null, 1));
await browser.close();
