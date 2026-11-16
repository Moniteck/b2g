// Texto de una anticipación en días: 0 → «El mismo día», 1 → «1 día antes», 7 → «7 días antes».
export function offsetLabel(days) {
    const value = Number(days);
    if (value === 0) return 'El mismo día';
    return value === 1 ? '1 día antes' : `${value} días antes`;
}

/** «7, 3, 1, 0» → [7, 3, 1, 0] (sin repetidos, de mayor a menor). Devuelve null si algo no es válido. */
export function parseDays(text) {
    const parts = String(text ?? '')
        .split(/[\s,;]+/)
        .filter(Boolean);
    if (parts.some((part) => !/^\d{1,3}$/.test(part))) return null;
    return [...new Set(parts.map(Number))].sort((a, b) => b - a);
}
