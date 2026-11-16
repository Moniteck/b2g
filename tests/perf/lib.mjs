// Utilidades compartidas de los scripts de medición (Playwright).
export const OUTPUT = process.env.PERF_OUTPUT ?? 'storage/app/qa/perf';
export const EMAIL = process.env.PERF_EMAIL ?? 'qa.admin@crm-b2g.test';
export const PASSWORD = process.env.PERF_PASSWORD ?? 'QaB2g#2026';

// Pantallas internas (GET sin parámetros) y algunas fichas con ids de los datos de QA (CAP Honduras).
export const SCREENS = [
    '/', '/calendario', '/reportes', '/kpis', '/pipeline', '/cotizaciones', '/clientes', '/inventario', '/licitaciones',
    '/ia-licitaciones', '/historial', '/directorio', '/post-adjudicacion', '/cobros', '/flujo-caja', '/presupuesto-revolvente',
    '/alertas-poa', '/instituciones', '/contactos', '/consorcios', '/inteligencia-competitiva', '/relaciones-institucionales',
    '/matriz-cumplimiento', '/notificaciones', '/firmas', '/generador-documentos', '/sops', '/capacitacion', '/calculadora-oferta',
    '/agente-legal', '/configuracion', '/configuracion/usuarios', '/configuracion/auditoria', '/configuracion/diagnostico', '/empresas', '/mi-cuenta',
    '/licitaciones/1', '/cotizaciones/1', '/post-adjudicacion/1', '/clientes/2', '/instituciones/1', '/matriz-cumplimiento/3',
];

// Peticiones JSON que disparan las modales (detalle de oportunidad, participantes, adjuntos...).
export const JSON_CALLS = [
    '/pipeline/7', '/pipeline/6', '/pipeline/5', '/oportunidades/7/participantes', '/oportunidades/5/participantes',
    '/adjuntos/opportunity/7', '/cotizaciones/1/participantes', '/calendario/eventos?from=2026-10-26&to=2026-12-06',
    '/inventario/buscar?q=cable', '/instituciones/buscar?q=sec', '/contactos/buscar?q=a',
];

/** Inicia sesión con el formulario real (reintenta: el servidor de desarrollo puede ir lento). */
export async function login(page, baseUrl, email = EMAIL) {
    for (let attempt = 1; attempt <= 3; attempt++) {
        try {
            await page.goto(`${baseUrl}/login`, { timeout: 60000 });
            await page.fill('#login-email', email);
            await page.fill('#login-password', PASSWORD);
            await Promise.all([page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 60000 }), page.click('button[type="submit"]')]);
            return;
        } catch (error) {
            if (attempt === 3) throw error;
            await page.waitForTimeout(2000);
        }
    }
}
