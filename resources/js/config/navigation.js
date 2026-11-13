// Menú del sistema, en el formato del marco CAP: grupos `{ label, items }` e ítems
// `{ label, icon, href }` con el ícono MDI sin el prefijo `mdi-`.
// Visibilidad por rol (la decide `visibleMenu`; el servidor sigue siendo quien autoriza):
//   can   → clave de `auth.can` que debe ser verdadera
//   roles → roles de empresa (`auth.user.company_role`) que lo ven
//   root  → solo el usuario raíz (`auth.user.is_root`)

export const SYSTEM_NAME = 'CRM B2G';

export const navigation = [
    {
        label: 'Principal',
        items: [
            { label: 'Dashboard', icon: 'view-dashboard-outline', href: '/' },
            { label: 'Calendario', icon: 'calendar-month-outline', href: '/calendario' },
            { label: 'Reportes', icon: 'chart-box-outline', href: '/reportes' },
            { label: 'Agente Legal', icon: 'scale-balance', href: '/agente-legal', can: 'use_ai' },
        ],
    },
    {
        label: 'Ventas',
        items: [
            { label: 'Pipeline', icon: 'view-column-outline', href: '/pipeline' },
            { label: 'Cotizaciones', icon: 'file-document-outline', href: '/cotizaciones' },
            { label: 'Clientes', icon: 'account-group-outline', href: '/clientes' },
            { label: 'Inventario Blessing', icon: 'package-variant-closed', href: '/inventario' },
        ],
    },
    {
        label: 'Procesos de gobierno',
        items: [
            { label: 'Licitaciones', icon: 'gavel', href: '/licitaciones' },
            { label: 'IA de licitaciones', icon: 'creation-outline', href: '/ia-licitaciones', can: 'use_ai' },
            { label: 'Historial HonduCompras', icon: 'history', href: '/historial' },
            { label: 'Directorio Institucional', icon: 'folder-account-outline', href: '/directorio' },
            { label: 'Post-adjudicación', icon: 'file-sign', href: '/post-adjudicacion' },
        ],
    },
    {
        label: 'Herramientas',
        items: [
            { label: 'Inteligencia competitiva', icon: 'binoculars', href: '/inteligencia-competitiva' },
            { label: 'CRM político', icon: 'handshake-outline', href: '/relaciones-institucionales' },
            { label: 'Presupuesto revolvente', icon: 'cash-sync', href: '/presupuesto-revolvente' },
            { label: 'Flujo de caja', icon: 'chart-timeline-variant', href: '/flujo-caja' },
            { label: 'Alertas POA', icon: 'bell-alert-outline', href: '/alertas-poa' },
            { label: 'Consorcios', icon: 'link-variant', href: '/consorcios' },
            { label: 'Matriz documental', icon: 'clipboard-check-outline', href: '/matriz-cumplimiento' },
            { label: 'Calculadora de oferta', icon: 'calculator-variant-outline', href: '/calculadora-oferta' },
            { label: 'Generador de documentos', icon: 'file-edit-outline', href: '/generador-documentos', can: 'use_ai' },
        ],
    },
    {
        label: 'Administración',
        items: [
            { label: 'Mi firma y sello', icon: 'draw-pen', href: '/firmas' },
            { label: 'Instituciones', icon: 'bank-outline', href: '/instituciones' },
            { label: 'Contactos', icon: 'card-account-phone-outline', href: '/contactos' },
            { label: 'Cobros', icon: 'cash-multiple', href: '/cobros' },
            { label: 'KPIs', icon: 'bullseye-arrow', href: '/kpis' },
            { label: 'Empresas', icon: 'domain', href: '/empresas', can: 'manage_companies' },
            { label: 'Usuarios', icon: 'account-cog-outline', href: '/configuracion/usuarios', can: 'manage_users' },
            { label: 'Conexiones MCP', icon: 'connection', href: '/configuracion/mcp', can: 'manage_settings' },
            { label: 'Auditoría', icon: 'shield-search', href: '/configuracion/auditoria', can: 'view_audit' },
            { label: 'Diagnóstico', icon: 'stethoscope', href: '/configuracion/diagnostico', can: 'view_diagnostics' },
            { label: 'Notificaciones', icon: 'bell-cog-outline', href: '/notificaciones', can: 'manage_settings' },
            { label: 'Configuración', icon: 'cog-outline', href: '/configuracion', can: 'manage_settings' },
            { label: 'SOP', icon: 'book-open-page-variant-outline', href: '/sops' },
            { label: 'Capacitación', icon: 'school-outline', href: '/capacitacion' },
        ],
    },
];

// Las cuatro secciones más usadas, en la barra de pestañas del móvil; el resto vive en la hoja «Más».
export const mobileTabs = [
    { label: 'Dashboard', icon: 'view-dashboard-outline', href: '/' },
    { label: 'Licitaciones', icon: 'gavel', href: '/licitaciones' },
    { label: 'Cotizaciones', icon: 'file-document-outline', href: '/cotizaciones' },
    { label: 'Calendario', icon: 'calendar-month-outline', href: '/calendario' },
];

// Rutas del marco que atiende el servidor.
export const endpoints = {
    logout: '/logout',
    activeCompany: '/empresa-activa',
    notifications: '/notificaciones',
    notificationsReadAll: '/notificaciones/leer-todas',
    notificationRead: (id) => `/notificaciones/${id}/leer`,
    signature: '/firmas',
};

function isAllowed(item, auth) {
    const user = auth?.user ?? {};
    if (item.root && !user.is_root) return false;
    if (item.roles && user.company_role && !user.is_root && !item.roles.includes(user.company_role)) return false;
    // Mientras el servidor no comparta `auth.can`, no se oculta nada: él sigue autorizando cada ruta.
    if (item.can && auth?.can && !user.is_root) return auth.can[item.can] === true;
    return true;
}

function pathOf(url) {
    const path = String(url ?? '/').split(/[?#]/)[0].replace(/\/+$/, '');
    return path === '' ? '/' : path;
}

function matchLength(href, path) {
    if (href === '/') return path === '/' ? 1 : 0;
    return path === href || path.startsWith(`${href}/`) ? href.length : 0;
}

/** El `href` del menú que corresponde a la URL actual (el más específico), o null. */
export function activeHref(url, groups = navigation) {
    const path = pathOf(url);
    let best = null;
    let bestLength = 0;
    groups.flatMap((group) => group.items).forEach((item) => {
        const length = matchLength(item.href, path);
        if (length > bestLength) {
            best = item.href;
            bestLength = length;
        }
    });
    return best;
}

/** Menú que ve el usuario, con el ítem de la página actual marcado como activo. */
export function visibleMenu(auth, url) {
    const current = activeHref(url);
    return navigation
        .map((group) => ({
            label: group.label,
            items: group.items.filter((item) => isAllowed(item, auth)).map(({ label, icon, href }) => ({ label, icon, href, active: href === current })),
        }))
        .filter((group) => group.items.length > 0);
}

/** Pestañas del móvil con la actual marcada. */
export function visibleTabs(url) {
    const current = activeHref(url);
    return mobileTabs.map((tab) => ({ ...tab, active: tab.href === current }));
}

/** Ítem y grupo del menú de la página actual: de ahí salen el título y las migas por defecto. */
export function currentEntry(url) {
    const current = activeHref(url);
    for (const group of navigation) {
        const item = group.items.find((candidate) => candidate.href === current);
        if (item) return { item, group };
    }
    return null;
}
