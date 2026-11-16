// Menú del sistema, en el formato del marco CAP: grupos `{ label, items }` e ítems
// `{ label, icon, href, children?, match? }` con el ícono MDI sin el prefijo `mdi-`.
//
// 14 entradas de primer nivel. Las pantallas hermanas viven como `children` (subnivel plegable) y la
// administración en una página índice (`/administracion`). Lo personal (Mi cuenta, Mi firma y sello,
// Mis recordatorios, Conexiones MCP) va en el menú del avatar (`accountMenu`).
//
// Visibilidad por rol (la decide `visibleMenu`; el servidor sigue siendo quien autoriza):
//   can   → clave de `auth.can` que debe ser verdadera
//   roles → roles de empresa (`auth.user.company_role`) que lo ven
//   root  → solo el usuario raíz (`auth.user.is_root`)
// `match` agrega prefijos de URL que también marcan el ítem como activo (p. ej. las pantallas de
// administración marcan «Administración»).

export const SYSTEM_NAME = 'CRM B2G';

export const navigation = [
    {
        label: 'Principal',
        items: [
            { label: 'Dashboard', icon: 'view-dashboard-outline', href: '/' },
            { label: 'Calendario', icon: 'calendar-month-outline', href: '/calendario' },
            {
                label: 'Reportes',
                icon: 'chart-box-outline',
                href: '/reportes',
                children: [
                    { label: 'Reportes', href: '/reportes' },
                    { label: 'KPI y metas', href: '/kpis' },
                ],
            },
            { label: 'Agente Legal', icon: 'scale-balance', href: '/agente-legal', can: 'use_ai' },
        ],
    },
    {
        label: 'Ventas',
        items: [
            { label: 'Pipeline', icon: 'view-column-outline', href: '/pipeline' },
            { label: 'Cotizaciones', icon: 'file-document-outline', href: '/cotizaciones' },
            { label: 'Clientes', icon: 'account-group-outline', href: '/clientes' },
            { label: 'Inventario', icon: 'package-variant-closed', href: '/inventario' },
        ],
    },
    {
        label: 'Gobierno',
        items: [
            {
                label: 'Licitaciones',
                icon: 'gavel',
                href: '/licitaciones',
                children: [
                    { label: 'Procesos', href: '/licitaciones' },
                    { label: 'IA de licitaciones', href: '/ia-licitaciones', can: 'use_ai' },
                    { label: 'Historial HonduCompras', href: '/historial' },
                ],
            },
            {
                label: 'Post-adjudicación',
                icon: 'file-sign',
                href: '/post-adjudicacion',
                children: [
                    { label: 'Contratos', href: '/post-adjudicacion' },
                    { label: 'Cobros y cartera', href: '/cobros' },
                    { label: 'Presupuesto revolvente', href: '/presupuesto-revolvente' },
                    { label: 'Flujo de caja', href: '/flujo-caja' },
                ],
            },
            {
                label: 'Directorio',
                icon: 'folder-account-outline',
                href: '/directorio',
                children: [
                    { label: 'Directorio institucional', href: '/directorio' },
                    { label: 'Instituciones', href: '/instituciones' },
                    { label: 'Contactos', href: '/contactos' },
                    { label: 'Matriz documental', href: '/matriz-cumplimiento' },
                ],
            },
        ],
    },
    {
        label: 'Más',
        items: [
            {
                label: 'Herramientas',
                icon: 'toolbox-outline',
                href: '/inteligencia-competitiva',
                children: [
                    { label: 'Inteligencia competitiva', href: '/inteligencia-competitiva' },
                    { label: 'CRM político', href: '/relaciones-institucionales' },
                    { label: 'Alertas POA', href: '/alertas-poa' },
                    { label: 'Consorcios', href: '/consorcios' },
                    { label: 'Calculadora de oferta', href: '/calculadora-oferta', can: 'create' },
                    { label: 'Generador de documentos', href: '/generador-documentos', can: 'use_ai' },
                    { label: 'Procedimientos (SOP)', href: '/sops' },
                    { label: 'Capacitación', href: '/capacitacion' },
                ],
            },
            {
                label: 'Administración',
                icon: 'cog-outline',
                href: '/administracion',
                match: ['/empresas', '/configuracion/usuarios', '/configuracion/auditoria', '/configuracion/diagnostico', '/notificaciones/plantillas'],
                exact: ['/configuracion'],
            },
        ],
    },
];

// Tarjetas de la página índice de Administración (`Pages/Admin/Index.vue`), filtradas por permiso.
export const adminCards = [
    { label: 'Empresas', icon: 'domain', href: '/empresas', description: 'Empresas del grupo, logos, datos fiscales y miembros.', can: 'manage_companies', rootOnly: true },
    { label: 'Usuarios', icon: 'account-cog-outline', href: '/configuracion/usuarios', description: 'Miembros de la empresa activa, roles y solicitudes de acceso.', can: 'manage_users' },
    { label: 'Instituciones', icon: 'bank-outline', href: '/instituciones', description: 'Catálogo de entidades compradoras y su potencial.' },
    { label: 'Contactos', icon: 'card-account-phone-outline', href: '/contactos', description: 'Personas de contacto en cada institución.' },
    { label: 'Configuración', icon: 'tune-variant', href: '/configuracion', description: 'Datos de la empresa, membrete de los PDF, impuestos y Blessing.', can: 'manage_settings' },
    { label: 'Conexiones MCP', icon: 'connection', href: '/configuracion/mcp', description: 'Conecta asistentes de IA a tus datos con permisos acotados.' },
    { label: 'Auditoría', icon: 'shield-search', href: '/configuracion/auditoria', description: 'Quién hizo qué y cuándo en la empresa activa.', can: 'view_audit' },
    { label: 'Diagnóstico', icon: 'stethoscope', href: '/configuracion/diagnostico', description: 'Estado del servidor, colas, binarios y almacenamiento.', can: 'view_diagnostics' },
    { label: 'Feriados y plazos', icon: 'calendar-remove-outline', href: '/post-adjudicacion?panel=feriados', description: 'Días inhábiles para el cálculo de plazos de contratos.' },
    { label: 'Notificaciones', icon: 'bell-cog-outline', href: '/notificaciones/plantillas', description: 'Plantillas y reglas de los avisos por correo y campana.', can: 'manage_settings' },
];

// Menú del avatar (y bloque personal de la hoja «Más» en móvil).
export const accountMenu = [
    { label: 'Mi cuenta', icon: 'account-circle-outline', href: '/mi-cuenta' },
    { label: 'Mi firma y sello', icon: 'draw-pen', href: '/firmas' },
    { label: 'Mis recordatorios', icon: 'bell-ring-outline', href: '/notificaciones' },
    { label: 'Mis conexiones MCP', icon: 'connection', href: '/configuracion/mcp' },
];

// Las cuatro secciones más usadas, en la barra de pestañas del móvil; el resto vive en la hoja «Más».
export const mobileTabs = [
    { label: 'Inicio', icon: 'view-dashboard-outline', href: '/' },
    { label: 'Licitaciones', icon: 'gavel', href: '/licitaciones' },
    { label: 'Cotizaciones', icon: 'file-document-outline', href: '/cotizaciones' },
    { label: 'Calendario', icon: 'calendar-month-outline', href: '/calendario' },
];

// Rutas del marco que atiende el servidor.
export const endpoints = {
    logout: '/logout',
    activeCompany: '/empresa-activa',
    supportStatus: '/soporte/estado',
    supportEnter: '/soporte/entrar',
    supportLeave: '/soporte/salir',
    search: '/buscar',
    notifications: '/notificaciones',
    notificationsReadAll: '/notificaciones/leer-todas',
    notificationRead: (id) => `/notificaciones/${id}/leer`,
    signature: '/firmas',
    companies: '/empresas',
};

export function isAllowed(item, auth) {
    const user = auth?.user ?? {};
    if (item.root && !user.is_root) return false;
    if (item.rootOnly && !user.is_root) return false;
    if (item.roles && user.company_role && !user.is_root && !item.roles.includes(user.company_role)) return false;
    // Mientras el servidor no comparta `auth.can`, no se oculta nada: él sigue autorizando cada ruta.
    if (item.can && auth?.can && !user.is_root) return auth.can[item.can] === true;
    return true;
}

export function pathOf(url) {
    const path = String(url ?? '/').split(/[?#]/)[0].replace(/\/+$/, '');
    return path === '' ? '/' : path;
}

function matchLength(href, path) {
    const target = pathOf(href);
    if (target === '/') return path === '/' ? 1 : 0;
    return path === target || path.startsWith(`${target}/`) ? target.length : 0;
}

/** Largo de la coincidencia más específica entre la URL y un ítem (su href, sus hijos y `match`). */
function itemMatch(item, path) {
    let best = matchLength(item.href, path);
    (item.children ?? []).forEach((child) => (best = Math.max(best, matchLength(child.href, path))));
    (item.match ?? []).forEach((prefix) => (best = Math.max(best, matchLength(prefix, path))));
    (item.exact ?? []).forEach((exact) => (best = Math.max(best, path === exact ? exact.length : 0)));
    return best;
}

/** El ítem de primer nivel que corresponde a la URL actual (el más específico), o null. */
export function activeItem(url, groups = navigation) {
    const path = pathOf(url);
    let best = null;
    let bestLength = 0;
    groups.forEach((group) =>
        group.items.forEach((item) => {
            const length = itemMatch(item, path);
            if (length > bestLength) {
                best = { item, group };
                bestLength = length;
            }
        }),
    );
    return best;
}

/** El `href` del menú que corresponde a la URL actual, o null (compatibilidad). */
export function activeHref(url, groups = navigation) {
    return activeItem(url, groups)?.item.href ?? null;
}

function activeChildHref(children, path) {
    let best = null;
    let bestLength = 0;
    children.forEach((child) => {
        const length = matchLength(child.href, path);
        if (length > bestLength) {
            best = child.href;
            bestLength = length;
        }
    });
    return best;
}

/** Menú que ve el usuario, con el ítem (y el hijo) de la página actual marcados como activos. */
export function visibleMenu(auth, url) {
    const path = pathOf(url);
    const current = activeItem(url)?.item ?? null;
    return navigation
        .map((group) => ({
            label: group.label,
            items: group.items
                .filter((item) => isAllowed(item, auth))
                .map((item) => {
                    const active = item === current;
                    const children = (item.children ?? []).filter((child) => isAllowed(child, auth));
                    const childHref = active ? activeChildHref(children, path) : null;
                    return {
                        label: item.label,
                        icon: item.icon,
                        href: item.href,
                        active,
                        children: children.length > 1 ? children.map((child) => ({ label: child.label, href: child.href, active: child.href === childHref })) : [],
                    };
                }),
        }))
        .filter((group) => group.items.length > 0);
}

/** Pestañas del móvil con la actual marcada. */
export function visibleTabs(url) {
    const current = activeItem(url)?.item.href;
    return mobileTabs.map((tab) => ({ ...tab, active: tab.href === current }));
}

/** Ítem y grupo del menú de la página actual: de ahí salen el título y las migas por defecto. */
export function currentEntry(url) {
    return activeItem(url);
}

/** Tarjetas de Administración que el usuario puede abrir. */
export function visibleAdminCards(auth) {
    return adminCards.filter((card) => isAllowed(card, auth));
}

/** Destinos navegables para la paleta de comandos (menú, hijos, administración y cuenta). */
export function navigationTargets(auth) {
    const targets = [];
    const seen = new Set();
    const push = (label, href, icon, group) => {
        if (seen.has(href)) return;
        seen.add(href);
        targets.push({ label, href, icon, group });
    };
    navigation.forEach((group) =>
        group.items.filter((item) => isAllowed(item, auth)).forEach((item) => {
            push(item.label, item.href, item.icon, group.label);
            (item.children ?? []).filter((child) => isAllowed(child, auth)).forEach((child) => push(child.label, child.href, item.icon, item.label));
        }),
    );
    visibleAdminCards(auth).forEach((card) => push(card.label, card.href, card.icon, 'Administración'));
    accountMenu.forEach((entry) => push(entry.label, entry.href, entry.icon, 'Mi cuenta'));
    return targets;
}
