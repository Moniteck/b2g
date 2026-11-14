// Utilidades del módulo de post-adjudicación (contratos).
import { date, daysUntil } from '../../lib/format.js';

export const CATEGORIES = ['Contrato', 'Adenda', 'Orden de compra', 'Acta de recepción', 'Factura', 'Otro'];

// Mismo mapa que `ContractStatus::TONES` en el servidor (el servidor lo manda en `tones`).
export const TONES = {
    'Pendiente de contrato': 'warning',
    'En ejecución': 'info',
    'Entrega parcial': 'warning',
    'Producto entregado': 'ok',
    Cerrado: 'neutral',
    Suspendido: 'warning',
    Cancelado: 'error',
};

export const DELIVERY_TONES = { Pendiente: 'warning', Entregada: 'ok', Rechazada: 'error', Cancelada: 'neutral' };

export function tone(status, tones = TONES) {
    return tones[status] ?? 'neutral';
}

/** Distintivo de días restantes hasta la fecha fin de un contrato vivo. */
export function remaining(endDate, open = true) {
    if (!endDate) return { status: 'neutral', label: 'Sin fecha fin' };
    if (!open) return null;
    const days = daysUntil(endDate);
    if (days < 0) return { status: 'error', label: `Venció hace ${Math.abs(days)} ${Math.abs(days) === 1 ? 'día' : 'días'}` };
    if (days === 0) return { status: 'error', label: 'Vence hoy' };
    if (days <= 30) return { status: 'warning', label: `${days} ${days === 1 ? 'día' : 'días'}` };
    return { status: 'info', label: `${days} días` };
}

/** Plazo legible: «45 días hábiles desde la orden de inicio». */
export function termLabel(contract) {
    if (!contract.duration_days) return null;
    const kind = contract.duration_business_days ? 'hábiles' : 'calendario';
    const unit = contract.duration_days === 1 ? 'día' : 'días';
    const from = contract.start_trigger ? ` desde ${contract.start_trigger.charAt(0).toLowerCase()}${contract.start_trigger.slice(1)}` : '';
    return `${contract.duration_days} ${unit} ${kind}${from}`;
}

export function percentOf(done, total) {
    return total ? Math.round((done / total) * 100) : 0;
}

/** Valores del formulario de contrato a partir de la ficha (o vacío). */
export function contractForm(contract = null) {
    return {
        number: contract?.number ?? '',
        name: contract?.name ?? '',
        status: contract?.status ?? 'Pendiente de contrato',
        institution_id: contract?.institution_id ?? null,
        client_id: contract?.client_id ?? null,
        contact_id: contract?.contact_id ?? null,
        manager_id: contract?.manager_id ?? null,
        awarded_amount: contract?.awarded_amount ?? null,
        amount: contract?.amount ?? null,
        signed_at: contract?.signed_at ?? '',
        duration_days: contract?.duration_days ?? '',
        duration_business_days: contract?.duration_business_days ?? false,
        start_trigger: contract?.start_trigger ?? '',
        start_date: contract?.start_date ?? '',
        end_date: contract?.end_date ?? '',
        is_end_date_manual: contract?.is_end_date_manual ?? false,
        product_delivered: contract?.product_delivered ?? false,
        notes: contract?.notes ?? '',
    };
}

/** Etiquetas del historial (acciones de la auditoría). */
export const HISTORY_LABELS = {
    'contrato.creado': { title: 'Contrato creado', icon: 'file-plus-outline', status: 'info' },
    'contrato.actualizado': { title: 'Datos actualizados', icon: 'pencil-outline' },
    'contrato.estado': { title: 'Cambio de estado', icon: 'swap-horizontal', status: 'warning' },
    'contrato.cerrado': { title: 'Contrato cerrado', icon: 'lock-check-outline', status: 'ok' },
    'contrato.entrega.creada': { title: 'Entrega registrada', icon: 'truck-outline' },
    'contrato.entrega.actualizada': { title: 'Entrega actualizada', icon: 'truck-outline' },
    'contrato.entrega.entregada': { title: 'Entrega completada', icon: 'truck-check-outline', status: 'ok' },
    'contrato.entrega.eliminada': { title: 'Entrega eliminada', icon: 'truck-remove-outline', status: 'error' },
    'contrato.seguimiento.creado': { title: 'Seguimiento registrado', icon: 'message-text-outline' },
    'contrato.seguimiento.eliminado': { title: 'Seguimiento eliminado', icon: 'message-remove-outline', status: 'error' },
    'documento.subido': { title: 'Documento subido', icon: 'file-upload-outline', status: 'info' },
};

const FIELD_LABELS = {
    number: 'número', name: 'nombre', awarded_amount: 'monto adjudicado', amount: 'monto del contrato', signed_at: 'fecha de firma',
    duration_days: 'plazo', duration_business_days: 'días hábiles', start_trigger: 'hito de inicio', start_date: 'inicio', end_date: 'fin',
    is_end_date_manual: 'fin manual', manager_id: 'oficial', contact_id: 'contacto', institution_id: 'institución', client_id: 'cliente',
    product_delivered: 'producto entregado', notes: 'notas', status: 'estado',
};

/** Resumen corto de los cambios de una entrada del historial. */
export function historyDetail(entry) {
    const changes = entry.changes ?? {};
    if (entry.action === 'contrato.estado' || entry.action === 'contrato.cerrado') {
        return [changes.estado_anterior && `${changes.estado_anterior} → ${changes.estado}`, changes.motivo && `Motivo: ${changes.motivo}`].filter(Boolean).join(' · ');
    }
    if (entry.action === 'contrato.actualizado') {
        return Object.keys(changes).map((key) => FIELD_LABELS[key] ?? key).join(', ');
    }
    if (entry.action.startsWith('contrato.entrega')) return [changes.entrega, changes.estado, changes.fecha_real && `entregada el ${date(changes.fecha_real, 'medium')}`].filter(Boolean).join(' · ');
    if (entry.action.startsWith('contrato.seguimiento')) return [changes.tipo, changes.proxima_accion, changes.nota].filter(Boolean).join(' · ');
    if (entry.action === 'documento.subido') return [changes.nombre, changes.categoria].filter(Boolean).join(' · ');
    return '';
}
