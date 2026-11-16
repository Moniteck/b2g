// Prueba de carga y estrés del CRM B2G con k6 (solo lectura: navega, abre modales, busca).
//
//   k6 run -e BASE_URL=http://127.0.0.1:8089 -e SCENARIO=rampa tests/perf/k6/crm.js
//   k6 run -e SCENARIO=pico tests/perf/k6/crm.js        (pico repentino)
//   k6 run -e SCENARIO=humo tests/perf/k6/crm.js        (2 usuarios, 30 s: comprueba el guion)
//
// Cada usuario virtual inicia sesión una vez (su propia sesión en la tabla `sessions`) y repite un
// recorrido realista con pausas de 1-3 s: Dashboard, Licitaciones, Pipeline (+ detalle y participantes
// de la modal), Cotizaciones, búsqueda de inventario, campana (recarga parcial de `notifications`),
// Reportes (cambio de período) y eventos del calendario. Las visitas usan las cabeceras de Inertia.
//
// Las sesiones que crea quedan con user_agent «k6/...»: bórralas al terminar
// (DELETE FROM sessions WHERE user_agent LIKE '%k6%').
//
// Nota: POST /login tiene limitador por IP (throttle:20,1 / throttle:login); para pasar de 20 usuarios en un minuto desde una IP
// el entorno de medición debe relajar ese límite (no lo hagas en producción).
import http from 'k6/http';
import { check, group, sleep } from 'k6';
import { Counter, Trend } from 'k6/metrics';

const BASE = __ENV.BASE_URL || 'http://127.0.0.1:8089';
const EMAIL = __ENV.EMAIL || 'qa.admin@crm-b2g.test';
const PASSWORD = __ENV.PASSWORD || 'QaB2g#2026';
const SCENARIO = __ENV.SCENARIO || 'rampa';
const OPPORTUNITIES = (__ENV.OPPORTUNITIES || '5,6,7').split(',');
const SEARCHES = ['cable', 'laptop', 'toner', 'monitor', 'switch', 'papel'];

const serverMs = new Trend('servidor_ms', true);
const sqlQueries = new Trend('consultas_sql');
const loginFailures = new Counter('login_fallidos');

const ENDPOINTS = ['login', 'dashboard', 'licitaciones', 'pipeline', 'pipeline_detalle', 'participantes', 'cotizaciones', 'inventario_buscar', 'campana', 'reportes', 'calendario_eventos'];

const scenarios = {
    humo: { executor: 'constant-vus', vus: 2, duration: '30s' },
    rampa: {
        executor: 'ramping-vus',
        startVUs: 0,
        stages: [
            { duration: '30s', target: 10 }, { duration: '1m', target: 10 },
            { duration: '30s', target: 50 }, { duration: '1m', target: 50 },
            { duration: '30s', target: 100 }, { duration: '1m', target: 100 },
            { duration: '30s', target: 200 }, { duration: '1m', target: 200 },
            { duration: '30s', target: 0 },
        ],
        gracefulRampDown: '20s',
    },
    pico: {
        executor: 'ramping-vus',
        startVUs: 0,
        stages: [
            { duration: '20s', target: 10 }, { duration: '30s', target: 10 },
            { duration: '10s', target: 250 }, { duration: '1m', target: 250 },
            { duration: '10s', target: 10 }, { duration: '40s', target: 10 },
            { duration: '10s', target: 0 },
        ],
        gracefulRampDown: '20s',
    },
};

export const options = {
    scenarios: { [SCENARIO]: scenarios[SCENARIO] },
    thresholds: {
        http_req_failed: ['rate<0.01'],
        http_req_duration: ['p(95)<500'],
        ...Object.fromEntries(ENDPOINTS.map((name) => [`http_req_duration{name:${name}}`, ['p(95)<500']])),
    },
    summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
    discardResponseBodies: false,
    noCookiesReset: true, // la sesión de cada usuario virtual sobrevive entre iteraciones
    insecureSkipTLSVerify: true,
};

let version = null;
let loggedIn = false;

function xsrf() {
    const jar = http.cookieJar().cookiesForURL(BASE);
    return jar['XSRF-TOKEN'] ? decodeURIComponent(jar['XSRF-TOKEN'][0]) : '';
}

function track(res, name) {
    const ms = Number(res.headers['X-Perf-Ms']);
    if (Number.isFinite(ms)) serverMs.add(ms, { name });
    const q = Number(res.headers['X-Perf-Queries']);
    if (Number.isFinite(q)) sqlQueries.add(q, { name });
    return res;
}

function inertiaHeaders(extra = {}) {
    return { Accept: 'text/html, application/xhtml+xml', 'X-Requested-With': 'XMLHttpRequest', 'X-Inertia': 'true', 'X-Inertia-Version': version ?? '', 'X-XSRF-TOKEN': xsrf(), 'Accept-Encoding': 'br, gzip', ...extra };
}

function visit(path, name, only = null, component = null) {
    const headers = inertiaHeaders(only ? { 'X-Inertia-Partial-Data': only, 'X-Inertia-Partial-Component': component } : {});
    let res = track(http.get(`${BASE}${path}`, { headers, tags: { name } }), name);
    if (res.status === 409) { // versión de assets cambiada: el cliente recargaría
        version = null;
        login();
        res = track(http.get(`${BASE}${path}`, { headers: inertiaHeaders(), tags: { name } }), name);
    }
    // Sin sesión, Inertia redirige a /login (200 tras seguir la redirección): eso es un fallo.
    const ok = res.status === 200 && !String(res.url).includes('/login');
    if (!ok && __ENV.DEBUG) console.warn(`${name} ${res.status} ${res.url}`);
    if (!ok && String(res.url).includes('/login')) loggedIn = false;
    check(res, { [`${name} 200`]: () => ok });
    return res;
}

function json(path, name) {
    const res = track(http.get(`${BASE}${path}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Accept-Encoding': 'br, gzip' }, tags: { name } }), name);
    if (res.status !== 200 && __ENV.DEBUG) console.warn(`${name} ${res.status} ${String(res.body).slice(0, 160)}`);
    check(res, { [`${name} 200`]: (r) => r.status === 200 });
    return res;
}

function login() {
    const page = http.get(`${BASE}/login`, { tags: { name: 'login' } });
    const match = page.body && page.body.match(/<script data-page="app" type="application\/json">([^<]+)</);
    if (match) {
        try { version = JSON.parse(match[1]).version; } catch (e) { version = null; }
    }
    const res = http.post(`${BASE}/login`, JSON.stringify({ email: EMAIL, password: PASSWORD, remember: false }), {
        headers: { ...inertiaHeaders(), 'Content-Type': 'application/json' },
        redirects: 0,
        tags: { name: 'login' },
    });
    loggedIn = res.status === 302 && !String(res.headers.Location || '').includes('/login');
    if (!loggedIn) loginFailures.add(1);
    check(res, { 'login 302': () => loggedIn });
}

const think = () => sleep(1 + Math.random() * 2);

export default function () {
    if (!loggedIn) {
        login();
        if (!loggedIn) { sleep(5); return; }
    }
    const opportunity = OPPORTUNITIES[Math.floor(Math.random() * OPPORTUNITIES.length)];

    group('dashboard', () => { visit('/', 'dashboard'); });
    think();
    group('licitaciones', () => { visit('/licitaciones', 'licitaciones'); });
    think();
    group('pipeline', () => {
        visit('/pipeline', 'pipeline');
        sleep(0.5);
        json(`/pipeline/${opportunity}`, 'pipeline_detalle');
        sleep(0.5);
        json(`/oportunidades/${opportunity}/participantes`, 'participantes');
    });
    think();
    group('cotizaciones', () => { visit('/cotizaciones', 'cotizaciones'); });
    think();
    group('inventario', () => { json(`/inventario/buscar?q=${SEARCHES[Math.floor(Math.random() * SEARCHES.length)]}`, 'inventario_buscar'); });
    think();
    group('campana', () => { visit('/', 'campana', 'notifications', 'Dashboard/Index'); });
    think();
    group('reportes', () => {
        const month = String(1 + Math.floor(Math.random() * 10)).padStart(2, '0');
        visit(`/reportes?from=2026-${month}-01&to=2026-11-14`, 'reportes', 'report,errors', 'Reports/Index');
    });
    think();
    group('calendario', () => { json('/calendario/eventos?from=2026-10-26&to=2026-12-06', 'calendario_eventos'); });
    think();
}

export function handleSummary(data) {
    const out = __ENV.SUMMARY || `storage/app/qa/perf/k6-${SCENARIO}.json`;
    const rows = Object.entries(data.metrics)
        .filter(([key]) => key.startsWith('http_req_duration{name:'))
        .map(([key, m]) => `${key.slice(23, -1).padEnd(20)} p50 ${m.values.med.toFixed(0).padStart(6)} ms · p95 ${m.values['p(95)'].toFixed(0).padStart(6)} ms · p99 ${m.values['p(99)'].toFixed(0).padStart(6)} ms · máx ${m.values.max.toFixed(0)} ms`);
    const reqs = data.metrics.http_reqs.values;
    const failed = data.metrics.http_req_failed.values.rate;
    const text = [
        `Escenario ${SCENARIO} contra ${BASE}`,
        `Peticiones: ${reqs.count} (${reqs.rate.toFixed(1)}/s) · fallidas ${(failed * 100).toFixed(2)} % · iteraciones ${data.metrics.iterations.values.count}`,
        `Total p95 ${data.metrics.http_req_duration.values['p(95)'].toFixed(0)} ms · p99 ${data.metrics.http_req_duration.values['p(99)'].toFixed(0)} ms`,
        ...rows,
    ].join('\n');
    return { [out]: JSON.stringify(data, null, 2), stdout: `${text}\n` };
}
