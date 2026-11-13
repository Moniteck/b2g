// Utilidades del módulo Licitaciones: plazos, estados de lectura (OCR e IA) y tonos de estado.
import { date, daysUntil, time } from '../../lib/format.js';

/** Tono semántico del estado interno de la licitación. */
export function tenderTone(status) {
    return (
        {
            Activa: 'ok',
            'En preparación': 'warning',
            Presentada: 'info',
            Adjudicada: 'ok',
            'No adjudicada': 'error',
            Perdida: 'error',
            Cancelada: 'neutral',
        }[status] ?? 'neutral'
    );
}

/**
 * Plazo de una fecha: tono y texto corto para el badge.
 *   deadline('2026-09-30T10:00') → { status: 'warning', label: 'En 5 días' }
 */
export function deadline(value, { closed = false } = {}) {
    const days = daysUntil(value);
    if (days === null) return null;
    if (days < 0) return { status: 'neutral', label: days === -1 ? 'Venció ayer' : `Venció hace ${Math.abs(days)} días` };
    if (closed) return { status: 'neutral', label: date(value, 'medium') };
    if (days === 0) return { status: 'error', label: `Hoy, ${time(value)}` };
    if (days === 1) return { status: 'error', label: 'Mañana' };
    if (days <= 3) return { status: 'error', label: `En ${days} días` };
    if (days <= 7) return { status: 'warning', label: `En ${days} días` };
    return { status: 'neutral', label: `En ${days} días` };
}

/** Estados de la lectura de la captura (OCR). */
export const READ_STATUS = {
    read: { status: 'ok', label: 'Leído', icon: 'check-circle-outline' },
    doubtful: { status: 'warning', label: 'Dudoso', icon: 'alert-outline' },
    missing: { status: 'neutral', label: 'No encontrado', icon: 'help-circle-outline' },
};

/** Estados de la evidencia de la IA (pliego). */
export const EVIDENCE_STATUS = {
    verified: { status: 'ok', label: 'Verificado', icon: 'check-decagram-outline' },
    doubtful: { status: 'warning', label: 'Dudoso', icon: 'alert-outline' },
    unverified: { status: 'error', label: 'Sin evidencia', icon: 'close-octagon-outline' },
    missing: { status: 'neutral', label: 'No encontrado', icon: 'help-circle-outline' },
};

/** Estados del análisis del pliego (cola). */
export const ANALYSIS_STATUS = {
    queued: { status: 'neutral', label: 'En cola' },
    processing: { status: 'info', label: 'Procesando' },
    review: { status: 'warning', label: 'Listo para revisar' },
    confirmed: { status: 'ok', label: 'Confirmado' },
    failed: { status: 'error', label: 'Fallido' },
};

/** Fechas críticas en orden cronológico. */
export const CRITICAL_DATES = [
    { key: 'started_at', label: 'Inicio del proceso', icon: 'flag-outline' },
    { key: 'clarification_deadline', label: 'Cierre de aclaraciones', icon: 'comment-question-outline' },
    { key: 'presentation_deadline', label: 'Recepción de ofertas', icon: 'inbox-arrow-down-outline' },
    { key: 'opening_at', label: 'Apertura de ofertas', icon: 'email-open-outline' },
];

/** Formulario vacío de licitación (alta manual). */
export function emptyTender() {
    return {
        reference: '',
        title: '',
        process_name: '',
        institution_id: null,
        purchasing_unit: '',
        purchase_type: '',
        status: 'Activa',
        estimated_amount: null,
        bid_document_fee: null,
        offer_validity_days: null,
        funding_source_type: '',
        funding_source: '',
        honducompras_stage: '',
        acquisition_type: '',
        started_at: '',
        clarification_deadline: '',
        presentation_deadline: '',
        opening_at: '',
        offer_reception_place: '',
        source_url: '',
        contact_name: '',
        contact_phone: '',
        contact_email: '',
        delivery_terms: '',
        payment_terms: '',
        requirements: '',
        observations: '',
        notes: '',
    };
}

/** Copia los campos editables de una licitación al formulario. */
export function formFrom(tender) {
    const base = emptyTender();
    Object.keys(base).forEach((key) => {
        if (tender?.[key] !== undefined && tender[key] !== null) base[key] = tender[key];
    });
    return base;
}
