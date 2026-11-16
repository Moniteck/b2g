// Utilidades del módulo Licitaciones: etapas del flujo, plazos con cuenta regresiva, estados de
// lectura (OCR e IA), contradicciones de una captura y formularios.
import { date, daysUntil, time } from '../../lib/format.js';

/** Honduras no tiene horario de verano: hora de pared = UTC − 6. */
const HONDURAS_OFFSET_MS = 6 * 60 * 60 * 1000;
const WALL = /^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?)?/;

/** Instante (ms) de una fecha «AAAA-MM-DDTHH:mm» en hora de Honduras. */
export function instantOf(value) {
    const wall = WALL.exec(String(value ?? ''));
    if (!wall) return null;
    const [, y, m, d, hh = '0', mm = '0', ss = '0'] = wall;
    return Date.UTC(+y, +m - 1, +d, +hh, +mm, +ss) + HONDURAS_OFFSET_MS;
}

/** Tono semántico del estado guardado (lo que no es etapa: formularios, filtros). */
export function tenderTone(status) {
    return (
        {
            Activa: 'info',
            'En preparación': 'warning',
            Presentada: 'info',
            Adjudicada: 'ok',
            'No adjudicada': 'error',
            Perdida: 'error',
            Desierta: 'neutral',
            Cancelada: 'neutral',
            Vencida: 'neutral',
        }[status] ?? 'neutral'
    );
}

/** Badge de la etapa calculada por el servidor (`TenderStage::of`). */
export function stageBadge(stage) {
    if (!stage) return { status: 'neutral', label: '—' };
    return { status: stage.awaiting_result ? 'warning' : stage.tone, label: stage.status_label ?? stage.label };
}

/**
 * Cuenta regresiva de un plazo, con tono por urgencia.
 *   countdown('2026-11-17T10:00') → { status: 'error', label: 'En 5 h', urgent: true, past: false }
 * Menos de 24 h o hasta 3 días: error; hasta 7 días: aviso; más: neutro. Pasado: neutro.
 */
export function countdown(value, now = Date.now()) {
    const at = instantOf(value);
    if (at === null) return null;
    const diff = at - now;
    const hours = diff / 3_600_000;
    const days = daysUntil(value, new Date(now));
    if (diff < 0) return { status: 'neutral', label: days === 0 ? `Hoy, ${time(value)} (pasó)` : days === -1 ? 'Venció ayer' : `Venció hace ${Math.abs(days)} días`, urgent: false, past: true };
    if (hours < 1) return { status: 'error', label: `En ${Math.max(1, Math.round(diff / 60_000))} min`, urgent: true, past: false };
    if (hours < 24) return { status: 'error', label: days === 0 ? `Hoy, ${time(value)}` : `En ${Math.round(hours)} h`, urgent: true, past: false };
    if (days <= 3) return { status: 'error', label: days === 1 ? `Mañana, ${time(value)}` : `En ${days} días`, urgent: true, past: false };
    if (days <= 7) return { status: 'warning', label: `En ${days} días`, urgent: false, past: false };
    return { status: 'neutral', label: `En ${days} días`, urgent: false, past: false };
}

/** Compatibilidad con la API anterior (badge de una fecha). */
export function deadline(value, { closed = false } = {}) {
    const result = countdown(value);
    if (!result) return null;
    if (closed && !result.past) return { status: 'neutral', label: date(value, 'medium') };
    return { status: result.status, label: result.label };
}

/** Íconos de cada plazo. */
export const DEADLINE_ICONS = {
    clarification_deadline: 'comment-question-outline',
    presentation_deadline: 'inbox-arrow-down-outline',
    opening_at: 'email-open-outline',
};

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

/** Etapas de HonduCompras en las que el proceso ya terminó (igual que CaptureConsistency). */
export const FINISHED_STAGES = ['Adjudicado', 'Desierto', 'Fracasado', 'Cancelado', 'Contratado', 'Terminado'];

/**
 * Contradicciones de una captura, en vivo mientras se corrige (misma regla que
 * `App\Services\Tenders\CaptureConsistency`). Devuelve [{ field, message, kind }].
 */
export function captureWarnings(values, fields = {}, now = Date.now()) {
    const at = (key) => (values[key] ? instantOf(values[key]) : null);
    const [started, clarification, presentation] = [at('started_at'), at('clarification_deadline'), at('presentation_deadline')];
    const warnings = [];
    if (clarification && presentation && clarification > presentation) {
        warnings.push({ field: 'clarification_deadline', kind: 'contradiction', message: 'El cierre de aclaraciones es posterior a la recepción de ofertas. Revisa las dos fechas con la captura.' });
    }
    if (started && presentation && presentation < started) {
        warnings.push({ field: 'presentation_deadline', kind: 'contradiction', message: 'La recepción de ofertas es anterior a la fecha de inicio del proceso.' });
    }
    if (presentation && presentation < now) {
        warnings.push({ field: 'presentation_deadline', kind: 'info', message: `La recepción de ofertas ya pasó (${date(values.presentation_deadline)}). Si no presentaste oferta, la licitación quedará como «Vencida».` });
    } else if (clarification && clarification < now) {
        warnings.push({ field: 'clarification_deadline', kind: 'info', message: 'El plazo para enviar aclaraciones ya pasó.' });
    }
    const stage = String(values.honducompras_stage ?? '').trim();
    if (stage && FINISHED_STAGES.includes(stage) && ['Activa', 'En preparación'].includes(values.status ?? 'Activa')) {
        warnings.push({ field: 'honducompras_stage', kind: 'contradiction', message: `En HonduCompras la etapa es «${stage}», pero la licitación se importará como «${values.status ?? 'Activa'}». Elige el estado que corresponde.` });
    }
    const fee = fields.bid_document_fee;
    if (fee && fee.status !== 'read' && (values.bid_document_fee === null || values.bid_document_fee === '' || values.bid_document_fee === undefined)) {
        warnings.push({ field: 'bid_document_fee', kind: 'contradiction', message: 'El valor de los pliegos no se pudo leer. Escríbelo o déjalo vacío.' });
    }
    return warnings;
}

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

/**
 * Sondeo con espera creciente que se pausa con la pestaña oculta (hallazgo de rendimiento).
 *   const poller = createPoller(async () => (await getJson(url)).pending, { start: 1500, max: 15000 });
 *   poller.start(); … poller.stop();
 * `task` devuelve true para seguir sondeando y false para parar.
 */
export function createPoller(task, { start = 2000, max = 20000, factor = 1.5 } = {}) {
    let delay = start;
    let timer = null;
    let running = false;

    const schedule = () => {
        clearTimeout(timer);
        if (!running || document.hidden) return;
        timer = setTimeout(tick, delay);
    };
    const tick = async () => {
        timer = null;
        let keep = true;
        try {
            keep = await task();
        } catch {
            // Un fallo de red puntual no detiene el sondeo.
        }
        delay = Math.min(max, Math.round(delay * factor));
        if (keep === false) running = false;
        schedule();
    };
    const onVisibility = () => {
        if (!running) return;
        if (document.hidden) {
            clearTimeout(timer);
            timer = null;
        } else {
            // Al volver a la pestaña, se consulta enseguida y se reinicia la espera.
            delay = start;
            clearTimeout(timer);
            timer = setTimeout(tick, 0);
        }
    };

    return {
        start() {
            if (running) return;
            running = true;
            delay = start;
            document.addEventListener('visibilitychange', onVisibility);
            schedule();
        },
        stop() {
            running = false;
            clearTimeout(timer);
            document.removeEventListener('visibilitychange', onVisibility);
        },
        get running() {
            return running;
        },
    };
}
