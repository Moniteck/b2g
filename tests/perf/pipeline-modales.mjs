// Mide las modales del Pipeline: qué peticiones dispara cada acción, cuánto tarda en verse el
// contenido y si hay peticiones duplicadas. Solo abre y cierra modales (no guarda nada).
//
//   PERF_URL=http://127.0.0.1:8089 node tests/perf/pipeline-modales.mjs
//   PERF_CARDS=7,5,6   ids de oportunidades a usar (No adjudicada, Oferta presentada, Adjudicada)
import { mkdirSync, writeFileSync } from 'node:fs';
import { chromium } from 'playwright';
import { login, OUTPUT } from './lib.mjs';

const BASE_URL = process.env.PERF_URL ?? 'http://127.0.0.1:8089';
const [LOST, PRESENTED, WON] = (process.env.PERF_CARDS ?? '7,5,6').split(',');
const tag = new URL(BASE_URL).port || 'default';
mkdirSync(OUTPUT, { recursive: true });

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
const errors = [];
page.on('pageerror', (e) => errors.push(String(e).slice(0, 400)));
page.on('console', (m) => m.type() === 'error' && errors.push(m.text().slice(0, 300)));

let log = [];
const pending = new Map();
page.on('request', (r) => {
    if (!r.url().startsWith(BASE_URL) || ['image', 'font', 'stylesheet', 'script'].includes(r.resourceType())) return;
    pending.set(r, performance.now());
});
page.on('requestfinished', async (r) => {
    if (!pending.has(r)) return;
    const res = await r.response();
    const sizes = await r.sizes().catch(() => ({}));
    log.push({ method: r.method(), url: r.url().replace(BASE_URL, ''), status: res?.status(), ms: Math.round(performance.now() - pending.get(r)), kb: +((sizes.responseBodySize ?? 0) / 1024).toFixed(1), server: res?.headers()['x-perf-ms'] ?? null, queries: res?.headers()['x-perf-queries'] ?? null, partial: r.headers()['x-inertia-partial-data'] ?? null });
});

const results = [];
const dialog = () => page.getByRole('dialog').last();
async function action(name, run, readyCheck) {
    log = [];
    const t0 = performance.now();
    await run();
    let ready = false;
    for (let i = 0; i < 120 && !ready; i++) {
        ready = await readyCheck().catch(() => false);
        if (!ready) await page.waitForTimeout(100);
    }
    const visibleMs = Math.round(performance.now() - t0);
    await page.waitForTimeout(1200); // peticiones tardías (duplicados)
    const urls = log.map((l) => `${l.method} ${l.url}`);
    const duplicates = [...new Set(urls.filter((u, i) => urls.indexOf(u) !== i))];
    results.push({ name, visibleMs, ready, requests: log, duplicates });
    console.log(`${name}: contenido visible en ${visibleMs} ms${ready ? '' : ' (NO llegó)'} · ${log.length} peticiones${duplicates.length ? ` · DUPLICADAS: ${duplicates.join(', ')}` : ''}`);
    for (const l of log) console.log(`    ${l.method} ${l.status} ${l.url} ${l.ms} ms ${l.kb} KB${l.server ? ` (servidor ${l.server} ms, ${l.queries} consultas)` : ''}${l.partial ? ` only=${l.partial}` : ''}`);
}

const card = (id) => page.locator(`[data-cap-kanban-card="${id}"]`);
async function menu(id, item) {
    await card(id).locator('button.cap-kanban-card__menu').click();
    await page.locator(`[role="menuitem"]:visible`, { hasText: item }).first().click();
}
async function closeDialog() {
    await page.keyboard.press('Escape');
    await page.getByRole('dialog').first().waitFor({ state: 'detached', timeout: 10000 }).catch(() => {});
    await page.waitForTimeout(400);
}
const tab = (name) => dialog().getByRole('tab', { name });
const panelHas = (text) => () => dialog().getByRole('tabpanel').getByText(text).first().isVisible();

await login(page, BASE_URL);
log = [];
await action('Entrar a /pipeline (visita completa)', () => page.goto(`${BASE_URL}/pipeline`), () => card(LOST).isVisible());

await action('Abrir detalle (menú → Ver detalle, No adjudicada)', () => menu(LOST, 'Ver detalle'), () => tab('Participantes').isVisible());
await action('Pestaña Participantes', () => tab('Participantes').click(), async () => (await dialog().locator('[data-participant], [aria-label="Cargando participantes"]').count()) > 0 && !(await dialog().locator('[aria-label="Cargando participantes"]').count()));
await action('Pestaña Quién ganó', () => tab('Quién ganó').click(), () => dialog().locator('[data-tab-winner]').isVisible());
await action('Pestaña Documentos', () => tab('Documentos').click(), panelHas(/documentos|Sin documentos|Aún no hay/i));
await action('Pestaña Historial', () => tab('Historial').click(), () => dialog().getByRole('tabpanel').isVisible());
await action('Volver a Participantes (re-montaje)', () => tab('Participantes').click(), async () => !(await dialog().locator('[aria-label="Cargando participantes"]').count()) && (await dialog().getByRole('tabpanel').isVisible()));
await closeDialog();

await action('Menú → Participantes (modal)', () => menu(PRESENTED, 'Participantes'), async () => (await dialog().locator('[data-participant]').count()) > 0);
await closeDialog();
await action('Menú → Registrar como Ganada (abrir y cancelar)', () => menu(PRESENTED, 'Registrar como Ganada'), () => dialog().getByRole('button', { name: 'Registrar como Ganada' }).isVisible());
await closeDialog();
await action('Menú → Registrar como Perdida (abrir y cancelar)', () => menu(PRESENTED, 'Registrar como Perdida'), () => dialog().getByRole('button', { name: 'Registrar como Perdida' }).isVisible());
await closeDialog();
await action('Abrir detalle Adjudicada → Resultado', async () => { await menu(WON, 'Ver detalle'); await tab('Resultado').click(); }, () => dialog().getByText('Oferta ganada').first().isVisible());
await closeDialog();
await action('Menú → Editar datos (oferta presentada)', () => menu(PRESENTED, 'Editar datos'), () => dialog().locator('input').first().isVisible());
await closeDialog();
await action('Enlace directo /pipeline/{id} (visita completa con modal)', () => page.goto(`${BASE_URL}/pipeline/${LOST}`), () => tab('Participantes').isVisible());
await closeDialog();

// PERF_WRITE=1: guarda participantes desde el detalle cambiando las observaciones del primero y
// luego las restaura (dos guardados). Deja dos filas `oferta.participantes` en audit_logs: bórralas.
if (process.env.PERF_WRITE === '1') {
    await menu(PRESENTED, 'Ver detalle');
    await tab('Participantes').click();
    const notes = dialog().locator('[data-participant="0"] input:not([type="checkbox"])').nth(3);
    await notes.waitFor();
    const original = await notes.inputValue();
    const saveButton = () => dialog().getByRole('button', { name: 'Guardar participantes' });
    const saved = () => page.waitForTimeout(1500).then(() => true);
    await notes.fill(`${original} perf`);
    await action('Guardar participantes desde el detalle (cambio)', () => saveButton().click(), saved);
    await notes.fill(original);
    await action('Guardar participantes desde el detalle (restaurar)', () => saveButton().click(), saved);
    await closeDialog();
}

writeFileSync(`${OUTPUT}/pipeline-modales-${tag}.json`, JSON.stringify({ results, errors }, null, 2));
console.log('errores:', errors);
await browser.close();
