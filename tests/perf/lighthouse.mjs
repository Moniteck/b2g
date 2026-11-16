// Lanzador de Lighthouse (escritorio y móvil) para el acceso y las pantallas principales.
// Inicia sesión con Playwright y pasa la cookie de sesión a Lighthouse con --extra-headers.
// Usa `npx -y lighthouse` (no está en package.json) y el Chrome instalado.
//
//   PERF_URL=http://127.0.0.1:8089 node tests/perf/lighthouse.mjs
//   PERF_LH_PAGES=/,/pipeline PERF_LH_FORMS=desktop node tests/perf/lighthouse.mjs
//
// Informes HTML/JSON en storage/app/qa/perf/lighthouse/ y resumen en lighthouse-resumen.json.
import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { chromium } from 'playwright';
import { login, OUTPUT } from './lib.mjs';

const BASE_URL = process.env.PERF_URL ?? 'http://127.0.0.1:8089';
const PAGES = (process.env.PERF_LH_PAGES ?? '/login,/,/licitaciones,/pipeline,/cotizaciones,/calendario,/directorio').split(',');
const FORMS = (process.env.PERF_LH_FORMS ?? 'desktop,mobile').split(',');
const CHROME = process.env.CHROME_PATH ?? 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const DIR = `${OUTPUT}/lighthouse`;
mkdirSync(DIR, { recursive: true });

// CLI de Lighthouse en la caché de npx (se instala con `npx -y lighthouse --version` si falta).
function lighthouseCli() {
    const cache = join(process.env.LOCALAPPDATA ?? join(process.env.HOME ?? '', '.npm'), process.env.LOCALAPPDATA ? 'npm-cache/_npx' : '_npx');
    const find = () => (existsSync(cache) ? readdirSync(cache).map((d) => join(cache, d, 'node_modules/lighthouse/cli/index.js')).find(existsSync) : null);
    if (!find()) execFileSync('npx -y lighthouse --version', { shell: true, stdio: 'ignore' });
    return find();
}
const CLI = lighthouseCli();

// Cookie de sesión de un usuario real (se reutiliza en todas las pasadas).
const browser = await chromium.launch();
const page = await browser.newPage();
await login(page, BASE_URL);
const cookies = await page.context().cookies();
await browser.close();
const cookieHeader = cookies.map((c) => `${c.name}=${c.value}`).join('; ');
const headersFile = `${DIR}/headers.json`;
writeFileSync(headersFile, JSON.stringify({ Cookie: cookieHeader }));

const summary = [];
for (const form of FORMS) {
    for (const path of PAGES) {
        const name = `${form}-${path === '/' ? 'dashboard' : path.replace(/\//g, '')}`;
        const out = `${DIR}/${name}`;
        const args = [CLI, BASE_URL + path, `--chrome-path=${CHROME}`, '--chrome-flags=--headless=new --no-first-run',
            '--output=json', '--output=html', `--output-path=${out}`, '--quiet', '--only-categories=performance,accessibility,best-practices,seo'];
        if (form === 'desktop') args.push('--preset=desktop');
        if (path !== '/login') args.push(`--extra-headers=${headersFile}`);
        try {
            execFileSync(process.execPath, args, { stdio: 'pipe', timeout: 240000 });
        } catch (error) {
            console.log(`Lighthouse falló en ${name}: ${String(error.stderr ?? error).slice(0, 300)}`);
        }
        const file = `${out}.report.json`;
        if (!existsSync(file)) continue;
        const r = JSON.parse(readFileSync(file, 'utf8'));
        const a = r.audits;
        const score = (k) => Math.round((r.categories[k]?.score ?? 0) * 100);
        const bytesBy = (type) => Math.round((a['resource-summary']?.details?.items ?? []).find((i) => i.resourceType === type)?.transferSize / 1024 || 0);
        const failing = Object.values(a)
            .filter((x) => x.score !== null && x.score < 0.9 && x.scoreDisplayMode !== 'informative' && x.scoreDisplayMode !== 'notApplicable' && x.scoreDisplayMode !== 'manual')
            .map((x) => ({ id: x.id, title: x.title, score: x.score, value: x.displayValue ?? '' }));
        summary.push({
            name, finalUrl: r.finalDisplayedUrl,
            performance: score('performance'), accessibility: score('accessibility'), bestPractices: score('best-practices'), seo: score('seo'),
            fcp: a['first-contentful-paint']?.displayValue, lcp: a['largest-contentful-paint']?.displayValue, tbt: a['total-blocking-time']?.displayValue,
            cls: a['cumulative-layout-shift']?.displayValue, si: a['speed-index']?.displayValue,
            jsKb: bytesBy('script'), cssKb: bytesBy('stylesheet'), fontKb: bytesBy('font'), totalKb: bytesBy('total'), requests: (a['resource-summary']?.details?.items ?? []).find((i) => i.resourceType === 'total')?.requestCount,
            failing,
        });
        const s = summary.at(-1);
        console.log(`${name}: P${s.performance} A${s.accessibility} BP${s.bestPractices} SEO${s.seo} · FCP ${s.fcp} · LCP ${s.lcp} · TBT ${s.tbt} · CLS ${s.cls} · JS ${s.jsKb} KB · CSS ${s.cssKb} KB · fuentes ${s.fontKb} KB · total ${s.totalKb} KB (${s.requests} peticiones) · ${s.finalUrl}`);
    }
}
writeFileSync(`${OUTPUT}/lighthouse-resumen.json`, JSON.stringify(summary, null, 2));

// Problemas más repetidos entre todas las pasadas.
const counts = {};
for (const s of summary) for (const f of s.failing) (counts[f.id] ??= { title: f.title, pages: 0, example: f.value }).pages++;
console.log('\nProblemas más frecuentes:');
Object.entries(counts).sort((x, y) => y[1].pages - x[1].pages).slice(0, 20).forEach(([id, v]) => console.log(`  ${v.pages}× ${id}: ${v.title} ${v.example}`));
