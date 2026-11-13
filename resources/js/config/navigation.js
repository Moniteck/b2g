export const navigation = [
    { title: 'PRINCIPAL', items: [['dashboard', 'Dashboard', '📊'], ['reportes', 'Reportes e Indicadores', '📈'], ['agente-legal', 'Agente Legal B2G', '⚖️', 'IA']] },
    { title: 'VENTAS B2G', items: [['pipeline', 'Pipeline de Oportunidades', '🎯'], ['cotizaciones', 'Cotizaciones', '📄'], ['clientes', 'Base de Clientes', '👥'], ['inventario', 'Inventario Blessing', '🔧']] },
    { title: 'PROCESOS GOBIERNO', items: [['ia-licitaciones', 'IA Recomendaciones', '🤖', 'NUEVO'], ['historial', 'HonduCompras', '🏛️'], ['licitaciones', 'Licitaciones', '📋'], ['directorio', 'Directorio Institucional', '🗂️']] },
    { title: 'HERRAMIENTAS B2G', items: [['post-adjudicacion', 'Post-Adjudicación', '📝'], ['inteligencia-competitiva', 'Inteligencia Competitiva', '🔍'], ['relaciones-institucionales', 'CRM Político', '🤝'], ['presupuesto-revolvente', 'Presupuesto Revolvente', '💹'], ['flujo-caja', 'Flujo de Caja B2G', '📊'], ['alertas-poa', 'Alertas POA', '🎯'], ['consorcios', 'Consorcios', '🔗'], ['matriz-cumplimiento', 'Matriz Documental', '📋', 'CLAVE'], ['calculadora-oferta', 'Calculadora de Oferta', '🧮'], ['generador-documentos', 'Generador de Documentos', '📝', 'IA']] },
    { title: 'ADMINISTRACIÓN', items: [['empresas', 'Empresas del Grupo', '🏢'], ['firmas', 'Firma y Sello', '✍️'], ['documentos', 'Directorio Documental', '📁'], ['cobros', 'Cobros y Cartera', '💰'], ['contactos', 'Contactos', '📞'], ['kpis', 'KPIs y Metas', '🎖️'], ['calendario', 'Calendario B2G', '📅'], ['notificaciones', 'Notificaciones', '🔔'], ['configuracion', 'Configuración', '⚙️'], ['sops', 'SOPs Operativos', '📚'], ['capacitacion', 'Capacitación', '🎓']] },
];

export const progressByModule = { dashboard: 80, 'agente-legal': 55 };

export function findModule(slug) {
    return navigation.flatMap((group) => group.items).find(([id]) => id === slug) || [slug, slug.replaceAll('-', ' ')];
}
