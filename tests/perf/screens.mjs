// Coste de servidor por pantalla: consultas SQL, tiempo SQL, tiempo de servidor, memoria pico y
// tamaño (HTML completo y visita Inertia). Necesita el perfilador del entorno de medición
// (cabeceras X-Perf-*); sin él solo reporta tiempo y tamaño.
//
//   PERF_URL=http://127.0.0.1:8089 PERF_REPEAT=5 node tests/perf/screens.mjs
//   PERF_LOG=1 ...   pide además el volcado de consultas (X-Perf-Log) en la primera pasada
import { mkdirSync, writeFileSync } from 'node:fs';
import { gzipSync, brotliCompressSync } from 'node:zlib';
import { Session } from './http.mjs';
import { EMAIL, JSON_CALLS, OUTPUT, PASSWORD, SCREENS } from './lib.mjs';

const BASE_URL = process.env.PERF_URL ?? 'http://127.0.0.1:8089';
const REPEAT = Number(process.env.PERF_REPEAT ?? 5);
const LOG = process.env.PERF_LOG === '1';

const median = (values) => {
    const sorted = values.filter(Number.isFinite).sort((a, b) => a - b);
    return sorted.length ? sorted[Math.floor(sorted.length / 2)] : null;
};

async function measure(session, path, kind) {
    const runs = [];
    for (let i = 0; i < REPEAT; i++) {
        const headers = LOG && i === 0 ? { 'x-perf-log': '1' } : {};
        const res = kind === 'html' ? await session.request(path, { headers: { accept: 'text/html', ...headers } })
            : kind === 'inertia' ? await session.inertia(path, { headers })
            : await session.json(path, headers);
        runs.push(res);
    }
    const last = runs.at(-1);
    let props = null;
    if (kind === 'inertia' && last.status === 200) {
        try {
            const page = JSON.parse(last.body.toString());
            props = Object.entries(page.props ?? {}).map(([k, v]) => [k, Buffer.byteLength(JSON.stringify(v ?? null))]).sort((a, b) => b[1] - a[1]).slice(0, 4);
            props.deferred = page.deferredProps ?? null;
        } catch { /* no JSON */ }
    }
    return {
        path, kind, status: last.status,
        queries: median(runs.map((r) => r.perf.queries)),
        sqlMs: median(runs.map((r) => r.perf.sqlMs)),
        serverMs: median(runs.map((r) => r.perf.serverMs)),
        clientMs: Math.round(median(runs.map((r) => r.ms))),
        memMb: median(runs.map((r) => r.perf.memMb)),
        kb: +(last.bytes / 1024).toFixed(1),
        gzipKb: +(gzipSync(last.body).length / 1024).toFixed(1),
        brKb: +(brotliCompressSync(last.body).length / 1024).toFixed(1),
        topProps: props,
    };
}

const session = new Session(BASE_URL);
await session.login(EMAIL, PASSWORD);
await session.request('/'); // calienta OPcache/caché

const rows = [];
for (const path of SCREENS) rows.push(await measure(session, path, 'inertia'));
for (const path of ['/', '/pipeline', '/licitaciones', '/cotizaciones']) rows.push(await measure(session, path, 'html'));
for (const path of JSON_CALLS) rows.push(await measure(session, path, 'json'));

mkdirSync(OUTPUT, { recursive: true });
const port = new URL(BASE_URL).port;
writeFileSync(`${OUTPUT}/screens-${port}.json`, JSON.stringify(rows, null, 2));
console.log('ruta | tipo | estado | consultas | SQL ms | servidor ms | cliente ms | MB | KB | gzip KB | props más pesadas');
for (const r of rows) {
    console.log([r.path, r.kind, r.status, r.queries, r.sqlMs, r.serverMs, r.clientMs, r.memMb, r.kb, r.gzipKb, (r.topProps ?? []).map(([k, v]) => `${k}:${(v / 1024).toFixed(1)}K`).join(' ')].join(' | '));
}
