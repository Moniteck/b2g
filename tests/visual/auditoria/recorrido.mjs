// Recorrido de todas las pantallas internas (GET) con un usuario, en varias vistas y temas.
//
//   node tests/visual/auditoria/recorrido.mjs --usuario=admin --vistas=d1440,m390 --temas=claro,oscuro [--axe] [--sin-capturas]
//
// Por pantalla registra: estado HTTP, errores de consola/excepciones, respuestas 4xx/5xx, scroll
// horizontal, elementos fuera de pantalla, objetivos táctiles < 44 px, textos cortados, textos en
// inglés, campos de fecha nativos y (con --axe) violaciones WCAG 2.1 AA.
// Resultado: storage/app/qa/auditoria/recorrido-<usuario>.json y capturas en capturas/<usuario>/.
import { writeFileSync } from 'node:fs';
import { chromium } from 'playwright';
import { BASE_URL, USERS, VIEWPORTS, axe, dir, login, measure, openContext, settle, watch } from './comun.mjs';

const arg = (name, fallback) => process.argv.find((a) => a.startsWith(`--${name}=`))?.split('=')[1] ?? fallback;
const userKey = arg('usuario', 'admin');
const viewports = arg('vistas', 'd1440,m390').split(',');
const themes = arg('temas', 'claro').split(',');
const withAxe = process.argv.includes('--axe');
const withShots = !process.argv.includes('--sin-capturas');
const only = arg('solo', '');

// Rutas internas GET (php artisan route:list --except-vendor --method=GET), sin exportes ni JSON.
export const ROUTES = [
    ['dashboard', '/'], ['calendario', '/calendario'], ['reportes', '/reportes'], ['agente-legal', '/agente-legal'],
    ['pipeline', '/pipeline'], ['pipeline-ficha', '/pipeline/14'], ['cotizaciones', '/cotizaciones'], ['cotizacion-ficha', '/cotizaciones/11'],
    ['clientes', '/clientes'], ['cliente-ficha', '/clientes/2'], ['inventario', '/inventario'], ['inventario-ficha', '/inventario/40'], ['inventario-sinc', '/inventario/sincronizaciones'],
    ['licitaciones', '/licitaciones'], ['licitacion-ficha', '/licitaciones/13'], ['ia-licitaciones', '/ia-licitaciones'], ['historial', '/historial'],
    ['directorio', '/directorio'], ['documentos-legacy', '/documentos'], ['post-adjudicacion', '/post-adjudicacion'], ['contrato-ficha', '/post-adjudicacion/14'],
    ['contratos-clientes', '/post-adjudicacion/clientes'], ['feriados', '/post-adjudicacion/feriados'], ['plazo', '/post-adjudicacion/plazo'],
    ['competidores', '/inteligencia-competitiva'], ['relaciones', '/relaciones-institucionales'], ['presupuesto', '/presupuesto-revolvente'], ['flujo-caja', '/flujo-caja'],
    ['alertas-poa', '/alertas-poa'], ['consorcios', '/consorcios'], ['matriz', '/matriz-cumplimiento'], ['matriz-ficha', '/matriz-cumplimiento/5'],
    ['calculadora', '/calculadora-oferta'], ['generador', '/generador-documentos'], ['firmas', '/firmas'], ['firmas-docs', '/firmas/documentos'],
    ['instituciones', '/instituciones'], ['institucion-ficha', '/instituciones/14'], ['contactos', '/contactos'], ['cobros', '/cobros'], ['kpis', '/kpis'],
    ['empresas', '/empresas'], ['empresa-ficha', '/empresas/1'], ['usuarios', '/configuracion/usuarios'], ['mcp', '/configuracion/mcp'],
    ['auditoria', '/configuracion/auditoria'], ['diagnostico', '/configuracion/diagnostico'], ['notificaciones', '/notificaciones'],
    ['plantillas', '/notificaciones/plantillas'], ['configuracion', '/configuracion'], ['sops', '/sops'], ['capacitacion', '/capacitacion'],
    ['mi-cuenta', '/mi-cuenta'], ['calendario-evento', '/calendario/105'], ['componentes-dev', '/dev/componentes'],
];

async function main() {
    const browser = await chromium.launch();
    const user = USERS[userKey];
    const report = { usuario: userKey, fecha: new Date().toISOString(), pantallas: [] };
    const routes = only ? ROUTES.filter(([name]) => only.split(',').includes(name)) : ROUTES;
    const file = `${dir()}/recorrido-${userKey}-${viewports.join('_')}-${themes.join('_')}${only ? '-parcial' : ''}.json`;

    for (const viewport of viewports) {
        for (const theme of themes) {
            const context = await openContext(browser, viewport, theme);
            const page = await context.newPage();
            let current = 'login';
            const events = [];
            watch(page, events, () => current);
            await login(page, user);
            const landing = new URL(page.url()).pathname;
            report.pantallas.push({ nombre: 'login-destino', vista: viewport, tema: theme, destino: landing });
            const shots = dir('capturas', userKey, `${viewport}-${theme}`);

            for (const [name, path] of routes) {
                current = `${name}@${viewport}-${theme}`;
                const before = events.length;
                const started = Date.now();
                let status = 0;
                try {
                    const response = await page.goto(`${BASE_URL}${path}`, { timeout: 45000 });
                    status = response?.status() ?? 0;
                    await settle(page);
                } catch (error) {
                    events.push({ label: current, tipo: 'navegacion', detalle: error.message.split('\n')[0] });
                }
                const ms = Date.now() - started;
                const finalPath = new URL(page.url()).pathname;
                let metrics = {};
                try {
                    metrics = await measure(page, { touch: Boolean(VIEWPORTS[viewport].touch) });
                } catch (error) {
                    metrics = { error: error.message.split('\n')[0] };
                }
                let violations;
                if (withAxe && status < 400) violations = await axe(page).catch((error) => [{ id: 'axe-fallo', ayuda: error.message.slice(0, 200) }]);
                if (withShots) await page.screenshot({ path: `${shots}/${name}.png`, fullPage: true, animations: 'disabled', timeout: 30000 }).catch(() => {});
                const title = await page.title().catch(() => '');
                report.pantallas.push({ nombre: name, ruta: path, final: finalPath, vista: viewport, tema: theme, estado: status, ms, titulo: title, ...metrics, axe: violations, eventos: events.slice(before) });
                writeFileSync(file, JSON.stringify(report, null, 2));
                console.log(`${String(status).padEnd(4)} ${String(ms).padStart(5)}ms ${viewport}-${theme} ${name} ovf=${metrics.overflowX} tact=${metrics.tactilesChicosTotal ?? '-'} ev=${events.length - before}`);
            }
            await context.close();
        }
    }
    await browser.close();
    writeFileSync(file, JSON.stringify(report, null, 2));
    console.log(`\nInforme: ${file}`);
}

if (process.argv[1]?.replace(/\\/g, '/').endsWith('auditoria/recorrido.mjs')) await main();
