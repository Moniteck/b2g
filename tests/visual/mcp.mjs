// Prueba real de extremo a extremo del servidor MCP y revisión visual de sus pantallas.
//
//   node tests/visual/mcp.mjs
//
// 1. Registra un cliente por DCR (POST /oauth/register).
// 2. En el navegador, como qa.admin: autorización con PKCE, pantalla de consentimiento (capturas en
//    escritorio y móvil, claro y oscuro), elige empresa y «Lectura y escritura» y aprueba.
// 3. Cambia el código por el token y llama por HTTP a /mcp/crm: initialize, tools/list,
//    list_companies, list_tenders, search_inventory, create_event, list_events.
// 4. «Conexiones MCP» (/configuracion/mcp) en las cuatro vistas; revoca la conexión desde la
//    pantalla y comprueba que el token deja de servir. Como qa.lector, la lista vacía.
// Resultados en storage/app/qa/capturas/mcp/ (capturas y resultados.json). Código 1 si algo falla.
import { createHash, randomBytes } from 'node:crypto';
import { mkdirSync, writeFileSync } from 'node:fs';
import { chromium } from 'playwright';
import { horizontalOverflow, openContext, settle, watchConsole } from './capturas.mjs';

const BASE_URL = process.env.QA_URL ?? 'http://localhost:8000';
const PASSWORD = process.env.QA_PASSWORD ?? 'QaB2g#2026';
const ADMIN = 'qa.admin@crm-b2g.test';
const VIEWER = 'qa.lector@crm-b2g.test';
const OUTPUT = 'storage/app/qa/capturas/mcp';
const REDIRECT = 'http://localhost:6274/oauth/callback';
const VIEWS = [['escritorio', 'claro'], ['escritorio', 'oscuro'], ['movil', 'claro'], ['movil', 'oscuro']];

mkdirSync(OUTPUT, { recursive: true });
const problems = [];
const results = {};
const b64url = (buffer) => buffer.toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

async function login(page, email) {
    for (let attempt = 1; attempt <= 3; attempt++) {
        try {
            await page.goto(`${BASE_URL}/login`);
            await page.fill('#login-email', email);
            await page.fill('#login-password', PASSWORD);
            await Promise.all([page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 20000 }), page.click('button[type="submit"]')]);
            return;
        } catch (error) {
            if (attempt === 3) {
                await page.screenshot({ path: `${OUTPUT}/fallo-acceso.png` }).catch(() => {});
                console.log('URL al fallar el acceso:', page.url());
                throw error;
            }
            await page.waitForTimeout(1500);
        }
    }
}

async function check(page, label) {
    await settle(page);
    const overflow = await horizontalOverflow(page);
    if (overflow > 1) problems.push(`[${label}] scroll horizontal de ${overflow}px`);
    await page.screenshot({ path: `${OUTPUT}/${label}.png`, fullPage: true });
}

async function rpc(token, method, params = {}, id = 1) {
    const response = await fetch(`${BASE_URL}/mcp/crm`, {
        method: 'POST',
        headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream' },
        body: JSON.stringify({ jsonrpc: '2.0', id, method, params }),
    });
    const text = await response.text();
    let body;
    try {
        body = JSON.parse(text);
    } catch {
        body = text;
    }
    return { status: response.status, body };
}

async function main() {
    // 1. Registro dinámico.
    const registration = await fetch(`${BASE_URL}/oauth/register`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ client_name: 'Claude (prueba e2e)', redirect_uris: [REDIRECT] }),
    });
    const client = await registration.json();
    results.registro = { status: registration.status, client_id: client.client_id };
    if (registration.status !== 201) throw new Error(`Registro DCR falló: ${JSON.stringify(client)}`);

    const browser = await chromium.launch();
    let token = null;

    // 2. Consentimiento en las cuatro vistas; se aprueba en la última.
    for (const [index, [viewport, theme]] of VIEWS.entries()) {
        const context = await openContext(browser, viewport, theme);
        const page = await context.newPage();
        const label = `consentimiento-${viewport}-${theme}`;
        problems.push(...[]);
        const seen = watchConsole(page, label);
        await login(page, ADMIN);

        const verifier = b64url(randomBytes(48));
        const challenge = b64url(createHash('sha256').update(verifier).digest());
        let captured = null;
        // El redirect a localhost:6274 (nadie escucha ahí) se lee del propio request: lleva el código.
        page.on('request', (request) => {
            if (request.url().startsWith(REDIRECT)) captured = request.url();
        });

        const query = new URLSearchParams({ client_id: client.client_id, redirect_uri: REDIRECT, response_type: 'code', scope: 'mcp:use', state: 'e2e', code_challenge: challenge, code_challenge_method: 'S256' });
        await page.goto(`${BASE_URL}/oauth/authorize?${query}`);
        await page.getByText('Autorizar a Claude (prueba e2e)').waitFor();
        await check(page, label);

        if (index === VIEWS.length - 1) {
            // Error de validación visible: sin empresas.
            for (const box of await page.locator('input[name="company_ids[]"]').all()) await box.uncheck();
            await page.locator('input[name="level"][value="write"]').check();
            await page.getByRole('button', { name: 'Autorizar acceso' }).click();
            await page.getByText('Elige al menos una empresa.').first().waitFor();
            await check(page, `consentimiento-error-${viewport}-${theme}`);

            await page.locator('input[name="company_ids[]"]').first().check();
            await page.locator('input[name="level"][value="write"]').check();
            await page.getByRole('button', { name: 'Autorizar acceso' }).click();
            await page.waitForTimeout(2500);
            if (!captured) {
                await page.screenshot({ path: `${OUTPUT}/fallo-aprobar.png`, fullPage: true });
                throw new Error(`No hubo redirección con el código de autorización. URL: ${page.url()}`);
            }
            const code = new URL(captured).searchParams.get('code');

            const tokenResponse = await fetch(`${BASE_URL}/oauth/token`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
                body: new URLSearchParams({ grant_type: 'authorization_code', client_id: client.client_id, redirect_uri: REDIRECT, code_verifier: verifier, code }),
            });
            const tokens = await tokenResponse.json();
            results.token = { status: tokenResponse.status, token_type: tokens.token_type, expires_in: tokens.expires_in, refresh: Boolean(tokens.refresh_token) };
            token = tokens.access_token;
        }

        problems.push(...seen);
        await context.close();
    }

    if (!token) throw new Error('No se obtuvo token.');

    // 3. Llamadas reales al servidor MCP.
    results.initialize = await rpc(token, 'initialize', { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'e2e', version: '1.0' } });
    const list = await rpc(token, 'tools/list', {}, 2);
    results.tools = { status: list.status, count: list.body?.result?.tools?.length, names: list.body?.result?.tools?.map((tool) => tool.name) };
    const call = async (name, args, id) => {
        const response = await rpc(token, 'tools/call', { name, arguments: args }, id);
        return { status: response.status, isError: response.body?.result?.isError ?? null, data: response.body?.result?.structuredContent ?? response.body?.result?.content ?? response.body };
    };
    results.list_companies = await call('list_companies', {}, 3);
    results.list_tenders = await call('list_tenders', { per_page: 3 }, 4);
    results.search_inventory = await call('search_inventory', { quantity: 2, per_page: 2 }, 5);
    const start = new Date(Date.now() + 3 * 86400000).toISOString().slice(0, 10);
    results.create_event = await call('create_event', { title: 'Visita de prueba MCP (e2e)', starts_at: `${start} 09:00`, reminders: [1, 0] }, 6);
    results.list_events = await call('list_events', { from: start, to: start }, 7);
    for (const key of ['list_companies', 'list_tenders', 'search_inventory', 'create_event', 'list_events']) {
        if (results[key].status !== 200 || results[key].isError) problems.push(`[mcp] ${key} falló: ${JSON.stringify(results[key]).slice(0, 300)}`);
    }

    writeFileSync(`${OUTPUT}/resultados.json`, JSON.stringify(results, null, 2));

    // 4. «Conexiones MCP» en las cuatro vistas; revocar en la última.
    for (const [index, [viewport, theme]] of VIEWS.entries()) {
        const context = await openContext(browser, viewport, theme);
        const page = await context.newPage();
        const label = `conexiones-${viewport}-${theme}`;
        const seen = watchConsole(page, label);
        await login(page, ADMIN);
        await page.goto(`${BASE_URL}/configuracion/mcp`);
        await page.getByText('Claude (prueba e2e)').filter({ visible: true }).first().waitFor();
        await check(page, label);

        if (index === VIEWS.length - 1) {
            await page.getByRole('button', { name: 'Revocar' }).filter({ visible: true }).first().click();
            await page.waitForTimeout(600);
            await check(page, `conexiones-confirmar-${viewport}-${theme}`);
            await page.getByRole('button', { name: /^Revocar/ }).last().click();
            await page.waitForTimeout(2500);
            await check(page, `conexiones-revocada-${viewport}-${theme}`);
        }
        problems.push(...seen);
        await context.close();
    }

    const after = await rpc(token, 'tools/list', {}, 9);
    results.despues_de_revocar = { status: after.status, body: after.body };
    if (after.status !== 401) problems.push(`[mcp] tras revocar se esperaba 401 y llegó ${after.status}`);

    // Lector: su lista (vacía o con sus conexiones), escritorio y móvil.
    for (const viewport of ['escritorio', 'movil']) {
        const context = await openContext(browser, viewport, 'claro');
        const page = await context.newPage();
        const seen = watchConsole(page, `lector-${viewport}`);
        await login(page, VIEWER);
        await page.goto(`${BASE_URL}/configuracion/mcp`);
        await check(page, `conexiones-lector-${viewport}`);
        problems.push(...seen);
        await context.close();
    }

    await browser.close();
    writeFileSync(`${OUTPUT}/resultados.json`, JSON.stringify(results, null, 2));
    console.log(JSON.stringify({ ...results, tools: { ...results.tools, names: undefined } }, null, 2).slice(0, 4000));

    if (problems.length) {
        console.log(`\n${problems.length} problema(s):`);
        problems.forEach((problem) => console.log(`  ${problem}`));
        process.exit(1);
    }
    console.log('\nFlujo MCP completo sin errores.');
}

await main();
