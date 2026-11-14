// Reglas y textos del pipeline que comparten el tablero, la tarjeta y el detalle.
// Las transiciones llegan del servidor (prop `transitions`, Opportunity::TRANSITIONS): aquí solo se
// explican al usuario. El servidor vuelve a validarlas siempre.
import { daysUntil, money } from '../../lib/format.js';
import { sendJson } from '../Quotes/api.js';

export const CLOSED_STAGES = ['adjudicado', 'no_adjudicado'];
export const CREATABLE_STAGES = ['prospecto', 'oportunidad'];

/** Desde Oferta Presentada se ve el botón «Participantes». */
export const PARTICIPANT_STAGES = ['oferta_presentada', 'adjudicado', 'no_adjudicado'];

export const isClosed = (stage) => CLOSED_STAGES.includes(stage);

export const stageTitle = (stages, id) => stages.find((stage) => stage.id === id)?.title ?? id;

/** Motivo por el que no se puede mover (o null). Mismo texto que el servidor. */
export function transitionError(transitions, stages, from, to) {
    if (from === to) return null;
    if (isClosed(from)) return `La oportunidad ya tiene resultado (${stageTitle(stages, from)}). Para moverla, un administrador debe revertir el resultado.`;
    if ((transitions[from] ?? []).includes(to)) return null;
    if (isClosed(to)) return `Solo una oportunidad en Oferta Presentada puede pasar a ${stageTitle(stages, to)}.`;
    return `No se puede pasar de ${stageTitle(stages, from)} a ${stageTitle(stages, to)}: el tablero avanza o retrocede de una etapa en una.`;
}

/** Insignia de la fecha clave: vencida (error), por vencer en 3 días (advertencia) o la fecha (neutro). */
export function keyDateBadge(card) {
    if (card.stage === 'adjudicado') return { status: 'ok', icon: 'trophy-outline', text: 'Ganada' };
    if (card.stage === 'no_adjudicado') return { status: 'error', icon: 'close-circle-outline', text: 'Perdida' };
    if (!card.keyDate) return null;
    const days = daysUntil(card.keyDate.date);
    if (days === null) return null;
    if (days < 0) return { status: 'error', icon: 'clock-alert-outline', text: `Vencida ${Math.abs(days)} d` };
    if (days === 0) return { status: 'warning', icon: 'clock-alert-outline', text: 'Vence hoy' };
    if (days <= 3) return { status: 'warning', icon: 'clock-outline', text: `Vence en ${days} d` };
    return { status: 'neutral', icon: 'calendar-outline', text: `${days} d` };
}

/** Diferencia de nuestra oferta contra otra: «+ L 3,000.00 (6.7 %)» o «— L 500.00». */
export function differenceText(difference, percentValue = null) {
    if (difference === null || difference === undefined) return '—';
    if (difference === 0) return 'Mismo monto';
    const sign = difference > 0 ? '+' : '−';
    const pct = percentValue === null || percentValue === undefined ? '' : ` (${Math.abs(percentValue).toLocaleString('es-HN', { maximumFractionDigits: 1 })} %)`;
    return `${sign} ${money(Math.abs(difference))}${pct}`;
}

/** Textos del historial (acciones de AuditLogger). */
const HISTORY = {
    'oportunidad.creada': { title: 'Oportunidad creada', icon: 'plus-circle-outline' },
    'oportunidad.actualizada': { title: 'Datos actualizados', icon: 'pencil-outline' },
    'oportunidad.etapa': { title: 'Cambio de etapa', icon: 'swap-horizontal', status: 'info' },
    'oferta.participantes': { title: 'Participantes actualizados', icon: 'account-group-outline' },
    'adjudicacion.ganada': { title: 'Registrada como ganada', icon: 'trophy-outline', status: 'ok' },
    'adjudicacion.perdida': { title: 'Registrada como no adjudicada', icon: 'close-circle-outline', status: 'error' },
    'adjudicacion.revertida': { title: 'Resultado revertido', icon: 'undo-variant', status: 'warning' },
    'cotizacion.estado': { title: 'Cambio de estado de la cotización', icon: 'file-document-outline' },
    'cotizacion.creada': { title: 'Cotización creada', icon: 'file-document-plus-outline' },
};

const FIELD_LABELS = {
    title: 'título',
    institution_id: 'institución',
    contact_id: 'contacto',
    tender_id: 'licitación',
    owner_id: 'responsable',
    amount: 'monto',
    probability: 'probabilidad',
    next_action: 'próxima acción',
    expected_close_date: 'cierre esperado',
    notes: 'notas',
    opportunity_type: 'tipo',
};

export function historyEntry(entry) {
    const known = HISTORY[entry.action];
    const changes = entry.changes ?? {};
    let description = '';
    if (entry.action === 'oportunidad.etapa') description = `De ${changes.de ?? '—'} a ${changes.a ?? '—'}${changes.cotizacion_presentada ? ` · cotización ${changes.cotizacion_presentada} presentada` : ''}`;
    else if (entry.action === 'oportunidad.actualizada') description = (changes.campos ?? []).map((field) => FIELD_LABELS[field] ?? field).join(', ');
    else if (entry.action === 'oferta.participantes') description = `${changes.agregados ?? 0} agregados, ${changes.actualizados ?? 0} actualizados, ${changes.quitados ?? 0} quitados`;
    else if (entry.action === 'adjudicacion.ganada') description = changes.monto_adjudicado ? `Monto adjudicado ${money(changes.monto_adjudicado)}` : '';
    else if (entry.action === 'adjudicacion.perdida') description = [changes.ganador ? `Ganó ${changes.ganador}` : '', changes.monto_ganador ? money(changes.monto_ganador) : '', changes.motivo ?? ''].filter(Boolean).join(' · ');
    else if (entry.action === 'adjudicacion.revertida') description = [changes.resultado_anterior ? `Antes: ${changes.resultado_anterior}` : '', changes.motivo ?? ''].filter(Boolean).join(' · ');
    else if (entry.action === 'cotizacion.estado') description = `${changes.numero ?? ''}: de ${changes.de ?? '—'} a ${changes.a ?? '—'}`;
    else if (changes.nombre || changes.documento) description = changes.nombre ?? changes.documento;

    const fallback = String(entry.action ?? '').replace(/[._]/g, ' ');
    return {
        title: known?.title ?? fallback.charAt(0).toUpperCase() + fallback.slice(1),
        icon: known?.icon ?? (entry.action?.startsWith('documento') ? 'file-outline' : 'circle-small'),
        status: known?.status,
        description,
    };
}

/** Mover una oportunidad: PATCH /pipeline/{id}/etapa → { message, card }. Rechaza con HttpError. */
export const moveOpportunity = (id, stage) => sendJson('PATCH', `/pipeline/${id}/etapa`, { stage });
