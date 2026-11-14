// Vistas hermanas del área de finanzas (navegación de secciones del header).
const FINANCE = [
    { label: 'Cobros', href: '/cobros' },
    { label: 'Flujo de caja', href: '/flujo-caja' },
    { label: 'Presupuesto', href: '/presupuesto-revolvente' },
];

export function FINANCE_SECTIONS(current) {
    return FINANCE.map((section) => ({ ...section, active: section.href === current }));
}
