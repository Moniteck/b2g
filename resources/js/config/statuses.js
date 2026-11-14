// Estados del dominio llevados a los cinco estados semánticos del sistema de diseño
// (ok, error, warning, info, neutral). `StatusBadge` lo consulta con `value`; un estado nuevo se
// agrega aquí, nunca con clases de color en la pantalla.
const DOMAIN_STATUSES = {
    ok: ['activo', 'activa', 'vigente', 'pagado', 'pagada', 'completado', 'completada', 'cumplido', 'adjudicada', 'adjudicado', 'ganada', 'ganado', 'aceptada', 'firmado', 'firmada', 'entregado', 'entregada', 'cerrado', 'cerrada', 'sincronizado', 'conectado'],
    error: ['vencido', 'vencida', 'anulada', 'anulado', 'rechazada', 'rechazado', 'perdida', 'perdido', 'cancelada', 'cancelado', 'en mora', 'fallido', 'fallida', 'error', 'revocado', 'revocada', 'crítica', 'urgente'],
    warning: ['pendiente', 'por vencer', 'en preparación', 'en revisión', 'requiere atención', 'por renovar', 'alta', 'parcial'],
    info: ['en proceso', 'presentada', 'presentado', 'emitida', 'emitido', 'enviada', 'enviado', 'negociación', 'propuesta', 'calificación', 'contactado', 'en curso', 'procesando'],
    neutral: ['borrador', 'inactivo', 'inactiva', 'archivado', 'archivada', 'sustituida', 'prospecto', 'normal', 'baja', 'sin vincular'],
};

const STATUS_BY_VALUE = new Map(Object.entries(DOMAIN_STATUSES).flatMap(([status, values]) => values.map((value) => [value, status])));

/** Estado semántico de un valor del dominio («Vigente» → 'ok'). Lo desconocido es 'neutral'. */
export function statusOf(value) {
    return STATUS_BY_VALUE.get(String(value ?? '').trim().toLowerCase()) ?? 'neutral';
}
