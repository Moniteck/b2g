<?php

namespace App\Services\Pipeline;

use App\Models\Opportunity;
use App\Models\Quote;
use App\Models\QuoteParticipant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Business\QuoteService;
use App\Services\Notifications\CalendarSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cambio de etapa del tablero (Fase 8). Único lugar con las reglas de movimiento:
 *
 *   move(Opportunity, 'oportunidad', ?User): MoveResult
 *
 * - Valida la transición con Opportunity::TRANSITIONS (avanzar o retroceder una etapa).
 * - Adjudicado y No Adjudicado NO se alcanzan aquí: son resultados y pasan por
 *   AdjudicationService::win()/lose() (rutas /oportunidades/{id}/ganada|perdida).
 * - Oferta Presentada exige «nuestra oferta» con monto entre los participantes y pasa a Presentada
 *   la cotización vinculada (decisión 13).
 *
 * Errores de negocio: ValidationException bajo `stage` (422 con mensaje en español).
 */
class OpportunityStageService
{
    public function __construct(
        protected AuditLogger $audit,
        protected CalendarSyncService $calendar,
        protected QuoteService $quotes,
    ) {}

    public function move(Opportunity $opportunity, string $stage, ?User $actor = null): MoveResult
    {
        $result = DB::transaction(function () use ($opportunity, $stage, $actor) {
            $locked = Opportunity::query()->whereKey($opportunity->id)->lockForUpdate()->firstOrFail();
            $from = $locked->stage;

            if ($from === $stage) {
                return new MoveResult($locked, $from, null, alreadyApplied: true);
            }

            if (($error = $locked->transitionError($stage)) !== null) {
                throw ValidationException::withMessages(['stage' => $error]);
            }

            if (in_array($stage, Opportunity::CLOSED_STAGES, true)) {
                throw ValidationException::withMessages(['stage' => $stage === 'adjudicado'
                    ? 'Registre el resultado con «Ganada»: se pide el monto adjudicado y se crean el cliente y el contrato.'
                    : 'Registre el resultado con «Perdida»: se pide quién ganó y el valor de su oferta.']);
            }

            $quote = null;

            if ($stage === 'oferta_presentada') {
                $this->ensureOurOffer($locked);
                $quote = $this->presentQuote($locked, $actor);
            }

            $locked->forceFill(['stage' => $stage] + ($quote ? ['quote_id' => $quote->id] : []))->save();

            $this->audit->record('oportunidad.etapa', $locked, [
                'de' => Opportunity::STAGES[$from] ?? $from,
                'a' => Opportunity::STAGES[$stage],
                'cotizacion_presentada' => $quote?->number,
            ], $actor);

            return new MoveResult($locked->fresh(), $from, $quote);
        });

        if (! $result->alreadyApplied) {
            $this->calendar->syncForOpportunity($result->opportunity);
        }

        return $result;
    }

    /**
     * Oferta Presentada exige al menos nuestra oferta con su monto (se registra en la modal de participantes).
     */
    protected function ensureOurOffer(Opportunity $opportunity): void
    {
        $ours = QuoteParticipant::query()
            ->ofProcess($opportunity->quote_id ? (int) $opportunity->quote_id : null, (int) $opportunity->id)
            ->where('is_ours', true)
            ->first();

        if ($ours === null) {
            throw ValidationException::withMessages(['stage' => 'Registre los participantes con nuestra oferta y su monto antes de pasar a Oferta Presentada.']);
        }

        if ((float) $ours->amount <= 0) {
            throw ValidationException::withMessages(['stage' => 'Indique el monto de nuestra oferta entre los participantes antes de pasar a Oferta Presentada.']);
        }
    }

    /**
     * Pasa a Presentada la cotización de la oportunidad (la vinculada o, si no hay, la única viva).
     * Una en Borrador se emite antes (Borrador → Emitida → Presentada). Con varias vivas y ninguna
     * vinculada no se toca ninguna: el usuario la presenta desde Cotizaciones.
     */
    protected function presentQuote(Opportunity $opportunity, ?User $actor): ?Quote
    {
        $live = Quote::query()->where('opportunity_id', $opportunity->id)
            ->whereIn('status', ['Borrador', 'Emitida', 'Presentada'])
            ->where('outcome', 'Pendiente')
            ->orderBy('id')
            ->get();

        $quote = $live->firstWhere('id', $opportunity->quote_id) ?? ($live->count() === 1 ? $live->first() : null);

        if ($quote === null) {
            return null;
        }

        if ($quote->status === 'Borrador') {
            $quote = $this->quotes->changeStatus($quote, 'Emitida', $actor);
        }

        if ($quote->status === 'Emitida') {
            $quote = $this->quotes->changeStatus($quote, 'Presentada', $actor);
        }

        return $quote;
    }
}
