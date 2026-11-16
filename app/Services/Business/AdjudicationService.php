<?php

namespace App\Services\Business;

use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\DocumentLink;
use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\Quote;
use App\Models\QuoteParticipant;
use App\Models\Scopes\CompanyScope;
use App\Models\Tender;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Notifications\CalendarSyncService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Operación ÚNICA del resultado comercial: ganar, perder y revertir, y los participantes.
 *
 * La web (cotizaciones y kanban) y el MCP pasan por aquí, así que una adjudicación produce
 * siempre los mismos efectos. Toda operación corre en una transacción con bloqueo de la
 * cotización y de la oportunidad, y es idempotente: repetirla no duplica nada.
 *
 * API pública (ver resources/js/Pages/Quotes/Partials/README.md):
 *
 *   win(Quote|Opportunity, ['awarded_amount', 'awarded_at', 'quote_id'?], ?User): AdjudicationResult
 *   lose(Quote|Opportunity, ['winner_name', 'winner_amount', 'winner_tax_id'?, 'winner_participant_id'?,
 *        'lost_reason'?, 'awarded_at'?], ?User): AdjudicationResult
 *   revert(Quote|Opportunity, string $reason, ?User): AdjudicationResult
 *   revertBlocker(Quote|Opportunity): ?string           — motivo por el que no se puede revertir
 *   saveParticipants(Quote|Opportunity, array $rows, ?User): Collection — guarda por diferencias
 *   participantsOf(Quote|Opportunity): Collection
 *   participantSummary(Collection): array               — posición de nuestra oferta y diferencia
 *
 * Una oportunidad sin cotización también se gana o se pierde (decisión 13). Si tiene una sola
 * cotización viva, se resuelve sobre ella; con varias, hay que indicar `quote_id`.
 */
class AdjudicationService
{
    public function __construct(
        protected AuditLogger $audit,
        protected CalendarSyncService $calendar,
    ) {}

    // ───────────────────────────── Ganada ─────────────────────────────

    /**
     * @param  array{awarded_amount?: mixed, awarded_at?: mixed, quote_id?: mixed}  $data
     */
    public function win(Quote|Opportunity $subject, array $data, ?User $actor = null): AdjudicationResult
    {
        $result = DB::transaction(function () use ($subject, $data, $actor) {
            [$quote, $opportunity] = $this->lockSubject($subject, $data['quote_id'] ?? null);

            if ($quote !== null) {
                if ($quote->isWon()) {
                    return $this->existingWin($quote, $opportunity);
                }
                if ($quote->isLost()) {
                    throw ValidationException::withMessages(['outcome' => "La cotización {$quote->number} ya está registrada como Perdida. Revierta el resultado antes de marcarla como Ganada."]);
                }
                if (! in_array($quote->status, Quote::ADJUDICABLE_STATUSES, true)) {
                    throw ValidationException::withMessages(['outcome' => "La cotización {$quote->number} está {$quote->status}: solo se adjudica una cotización Emitida o Presentada."]);
                }
            }

            if ($opportunity !== null) {
                if ($opportunity->stage === 'adjudicado') {
                    return $this->existingWin($quote, $opportunity);
                }
                if ($opportunity->stage === 'no_adjudicado') {
                    throw ValidationException::withMessages(['outcome' => 'La oportunidad ya está registrada como No Adjudicada. Revierta el resultado primero.']);
                }
                $this->assertOfferSubmitted($subject, $opportunity, 'Ganada');
            }

            $amount = $this->amount($data['awarded_amount'] ?? null, $quote?->total ?? $opportunity?->amount);
            $awardedAt = $data['awarded_at'] ?? now()->toDateString();
            $companyId = (int) ($quote?->company_id ?? $opportunity->company_id);
            $company = Company::query()->find($companyId);

            if ($quote !== null) {
                $quote->forceFill([
                    'status' => 'Presentada',
                    'outcome' => 'Ganada',
                    'awarded_amount' => $amount,
                    'awarded_at' => $awardedAt,
                    'winner_name' => $company?->name,
                    'lost_reason' => null,
                ])->save();
            }

            $institution = $this->institutionOf($quote, $opportunity);
            $client = $this->resolveClient($companyId, $institution, $quote, $opportunity, $amount);

            if ($quote !== null) {
                $quote->forceFill(['client_id' => $client->id])->save();
                $this->ensureOurOffer($quote);
            }

            $contract = $this->openContract($companyId, $client, $quote, $opportunity, $amount);

            if ($opportunity !== null) {
                $opportunity->forceFill([
                    'stage' => 'adjudicado',
                    'quote_id' => $quote?->id ?? $opportunity->quote_id,
                    'awarded_amount' => $amount,
                    'awarded_at' => $awardedAt,
                    'winner_name' => $company?->name,
                    'lost_reason' => null,
                ])->save();
            }

            $this->markTender($quote, $opportunity, ['offer_submitted' => true, 'result' => 'Ganada', 'status' => 'Adjudicada']);
            $this->markWinner($quote, $opportunity, fn (QuoteParticipant $participant) => $participant->is_ours);

            $replaced = $this->siblings($quote, $opportunity)->each(fn (Quote $sibling) => $sibling->forceFill(['status' => 'Sustituida'])->save());

            $this->audit->record('adjudicacion.ganada', $quote ?? $opportunity, [
                'cotizacion' => $quote?->number,
                'oportunidad_id' => $opportunity?->id,
                'monto_adjudicado' => $amount,
                'cliente_id' => $client->id,
                'contrato_id' => $contract->id,
                'sustituidas' => $replaced->pluck('number')->all(),
            ], $actor, $companyId);

            return new AdjudicationResult('Ganada', $quote?->fresh(), $opportunity?->fresh(), $client->fresh(), $contract->fresh());
        });

        $this->syncCalendar($result);

        return $result;
    }

    // ───────────────────────────── Perdida ─────────────────────────────

    /**
     * @param  array{winner_name?: mixed, winner_amount?: mixed, winner_tax_id?: mixed, winner_participant_id?: mixed, lost_reason?: mixed, awarded_at?: mixed, quote_id?: mixed}  $data
     */
    public function lose(Quote|Opportunity $subject, array $data, ?User $actor = null): AdjudicationResult
    {
        $result = DB::transaction(function () use ($subject, $data, $actor) {
            [$quote, $opportunity] = $this->lockSubject($subject, $data['quote_id'] ?? null, allowSeveral: true);

            if ($quote !== null) {
                if ($quote->isLost()) {
                    return new AdjudicationResult('Perdida', $quote, $opportunity, alreadyApplied: true);
                }
                if ($quote->isWon()) {
                    throw ValidationException::withMessages(['outcome' => "La cotización {$quote->number} ya está registrada como Ganada. Revierta el resultado antes de marcarla como Perdida."]);
                }
                if (! in_array($quote->status, Quote::ADJUDICABLE_STATUSES, true)) {
                    throw ValidationException::withMessages(['outcome' => "La cotización {$quote->number} está {$quote->status}: solo una cotización Emitida o Presentada puede quedar Perdida."]);
                }
            }

            if ($opportunity !== null) {
                if ($opportunity->stage === 'no_adjudicado') {
                    return new AdjudicationResult('Perdida', $quote, $opportunity, alreadyApplied: true);
                }
                if ($opportunity->stage === 'adjudicado') {
                    throw ValidationException::withMessages(['outcome' => 'La oportunidad ya está registrada como Adjudicada. Revierta el resultado primero.']);
                }
                $this->assertOfferSubmitted($subject, $opportunity, 'Perdida');
            }

            $winner = $this->winnerFrom($quote, $opportunity, $data);
            $awardedAt = $data['awarded_at'] ?? now()->toDateString();
            $reason = filled($data['lost_reason'] ?? null) ? (string) $data['lost_reason'] : null;
            $values = [
                'outcome' => 'Perdida',
                'winner_name' => $winner->name,
                'awarded_amount' => (float) $winner->amount,
                'awarded_at' => $awardedAt,
                'lost_reason' => $reason,
            ];

            $quote?->forceFill(['status' => 'Presentada'] + $values)->save();

            // Decisión 13: el resultado es por oportunidad; las demás cotizaciones vivas también quedan Perdida.
            $others = $this->siblings($quote, $opportunity)->each(fn (Quote $sibling) => $sibling->forceFill($values)->save());

            if ($opportunity !== null) {
                $opportunity->forceFill([
                    'stage' => 'no_adjudicado',
                    'winner_name' => $winner->name,
                    'awarded_amount' => (float) $winner->amount,
                    'awarded_at' => $awardedAt,
                    'lost_reason' => $reason,
                ])->save();
            }

            $this->markTender($quote, $opportunity, ['offer_submitted' => true, 'result' => 'No adjudicada', 'status' => 'No adjudicada']);
            $this->markWinner($quote, $opportunity, fn (QuoteParticipant $participant) => $participant->id === $winner->id);

            $this->audit->record('adjudicacion.perdida', $quote ?? $opportunity, [
                'cotizacion' => $quote?->number,
                'oportunidad_id' => $opportunity?->id,
                'ganador' => $winner->name,
                'monto_ganador' => (float) $winner->amount,
                'motivo' => $reason,
                'tambien_perdidas' => $others->pluck('number')->all(),
            ], $actor, (int) ($quote?->company_id ?? $opportunity->company_id));

            return new AdjudicationResult('Perdida', $quote?->fresh(), $opportunity?->fresh());
        });

        $this->syncCalendar($result);

        return $result;
    }

    // ───────────────────────────── Revertir ─────────────────────────────

    /**
     * Decisión 15: el contrato pasa a Cancelado (no se borra), el cliente se conserva con el monto
     * descontado de su acumulado y la oportunidad vuelve a Oferta Presentada. Bloqueado si el
     * contrato ya tiene documentos, entregas o seguimientos.
     */
    public function revert(Quote|Opportunity $subject, string $reason, ?User $actor = null): AdjudicationResult
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Indique el motivo de la reversión.']);
        }

        $result = DB::transaction(function () use ($subject, $reason, $actor) {
            [$quote, $opportunity] = $this->lockForRevert($subject);
            $previous = $quote?->outcome !== null && $quote->outcome !== 'Pendiente'
                ? $quote->outcome
                : ($opportunity?->stage === 'adjudicado' ? 'Ganada' : ($opportunity?->stage === 'no_adjudicado' ? 'Perdida' : 'Pendiente'));

            if ($previous === 'Pendiente') {
                throw ValidationException::withMessages(['outcome' => 'No hay un resultado que revertir: la oferta sigue Pendiente.']);
            }

            $companyId = (int) ($quote?->company_id ?? $opportunity->company_id);
            $contract = null;
            $client = null;

            if ($previous === 'Ganada') {
                $contract = $this->contractFor($companyId, $quote, $opportunity, lock: true);

                if ($contract !== null && ($blocker = $this->contractActivity($contract)) !== null) {
                    throw ValidationException::withMessages(['outcome' => $blocker]);
                }

                $amount = (float) ($quote?->awarded_amount ?? $opportunity?->awarded_amount ?? 0);
                $clientId = $contract?->client_id ?? $quote?->client_id;
                $client = $clientId ? Client::withTrashed()->withoutGlobalScope(CompanyScope::class)->whereKey($clientId)->lockForUpdate()->first() : null;

                if ($client !== null) {
                    $client->forceFill(['accumulated_amount' => max(0, round((float) $client->accumulated_amount - $amount, 2))])->save();
                }

                if ($contract !== null) {
                    $contract->forceFill([
                        'status' => 'Cancelado',
                        'notes' => trim(($contract->notes ? $contract->notes."\n" : '').'Cancelado al revertir la adjudicación el '.now()->format('d/m/Y').": {$reason}"),
                    ])->save();
                }
            }

            $reset = ['outcome' => 'Pendiente', 'awarded_amount' => null, 'awarded_at' => null, 'winner_name' => null, 'lost_reason' => null];

            $quote?->forceFill($reset + ['status' => 'Presentada', 'client_id' => null])->save();

            if ($previous === 'Perdida' && $opportunity !== null) {
                Quote::query()->where('opportunity_id', $opportunity->id)->where('outcome', 'Perdida')
                    ->when($quote, fn ($query) => $query->whereKeyNot($quote->id))
                    ->lockForUpdate()->get()
                    ->each(fn (Quote $sibling) => $sibling->forceFill($reset)->save());
            }

            if ($opportunity !== null) {
                $opportunity->forceFill([
                    'stage' => 'oferta_presentada',
                    'awarded_amount' => null,
                    'awarded_at' => null,
                    'winner_name' => null,
                    'lost_reason' => null,
                ])->save();
            }

            $this->markTender($quote, $opportunity, ['result' => null, 'status' => 'Presentada']);
            $this->markWinner($quote, $opportunity, fn () => false);

            $this->audit->record('adjudicacion.revertida', $quote ?? $opportunity, [
                'cotizacion' => $quote?->number,
                'oportunidad_id' => $opportunity?->id,
                'resultado_anterior' => $previous,
                'motivo' => $reason,
                'contrato_cancelado' => $contract?->number,
                'cliente_id' => $client?->id,
            ], $actor, $companyId);

            return new AdjudicationResult('Pendiente', $quote?->fresh(), $opportunity?->fresh(), $client?->fresh(), $contract?->fresh());
        });

        $this->syncCalendar($result);

        return $result;
    }

    /**
     * Por qué no se puede revertir (null si se puede).
     */
    public function revertBlocker(Quote|Opportunity $subject): ?string
    {
        [$quote, $opportunity] = $subject instanceof Quote
            ? [$subject, $subject->opportunity_id ? Opportunity::query()->find($subject->opportunity_id) : null]
            : [$this->wonQuoteOf($subject), $subject];

        $won = $quote?->isWon() || $opportunity?->stage === 'adjudicado';

        if (! $won) {
            return null;
        }

        $contract = $this->contractFor((int) ($quote?->company_id ?? $opportunity->company_id), $quote, $opportunity);

        return $contract ? $this->contractActivity($contract) : null;
    }

    // ───────────────────────────── Participantes ─────────────────────────────

    /**
     * Participantes del proceso (oportunidad y cotización), del más barato al más caro.
     */
    public function participantsOf(Quote|Opportunity $subject): Collection
    {
        [$quoteId, $opportunityId] = $this->processKeys($subject);

        return QuoteParticipant::query()->ofProcess($quoteId, $opportunityId)
            ->orderBy('position')->orderBy('amount')->orderBy('id')->get();
    }

    /**
     * Guarda por diferencias: las filas con `id` se actualizan, las nuevas se crean y las que ya no
     * vienen se quitan. Una fila sin `id` cuyo nombre coincide con una existente la actualiza (así un
     * cliente que no maneja ids, como el MCP, no duplica). Una sola «nuestra oferta» y un solo ganador.
     *
     * @param  array<int, array<string, mixed>>  $rows  [{ id?, name, tax_id?, amount, currency?, includes_tax?, is_ours?, is_winner?, notes? }]
     */
    public function saveParticipants(Quote|Opportunity $subject, array $rows, ?User $actor = null): Collection
    {
        $this->validateParticipants($subject, $rows);

        return DB::transaction(function () use ($subject, $rows, $actor) {
            $locked = $subject::query()->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();
            [$quoteId, $opportunityId] = $this->processKeys($locked);

            $existing = QuoteParticipant::query()->ofProcess($quoteId, $opportunityId)->lockForUpdate()->get()->keyBy('id');
            $unmatched = $existing->keys()->all();
            $counts = ['agregados' => 0, 'actualizados' => 0, 'quitados' => 0];

            foreach (array_values($rows) as $index => $row) {
                $participant = null;

                if (! empty($row['id'])) {
                    $participant = $existing->get((int) $row['id']);
                    if ($participant === null) {
                        throw ValidationException::withMessages(["participants.{$index}.id" => 'El participante no pertenece a este proceso.']);
                    }
                } else {
                    $key = $this->normalizeName((string) $row['name']);
                    $participant = $existing->first(fn (QuoteParticipant $candidate) => in_array($candidate->id, $unmatched, true) && $this->normalizeName($candidate->name) === $key);
                }

                $values = [
                    'name' => trim((string) $row['name']),
                    'tax_id' => filled($row['tax_id'] ?? null) ? trim((string) $row['tax_id']) : null,
                    'amount' => round((float) ($row['amount'] ?? 0), 2),
                    'currency' => $row['currency'] ?? 'HNL',
                    'includes_tax' => (bool) ($row['includes_tax'] ?? true),
                    'is_ours' => (bool) ($row['is_ours'] ?? false),
                    'is_winner' => (bool) ($row['is_winner'] ?? false),
                    'notes' => filled($row['notes'] ?? null) ? (string) $row['notes'] : null,
                    'opportunity_id' => $opportunityId,
                ];

                if ($participant !== null) {
                    $unmatched = array_values(array_diff($unmatched, [$participant->id]));
                    $participant->forceFill($values + ['quote_id' => $participant->quote_id ?? $quoteId]);
                    if ($participant->isDirty()) {
                        $participant->save();
                        $counts['actualizados']++;
                    }
                } else {
                    QuoteParticipant::query()->create($values + ['quote_id' => $quoteId, 'position' => $index + 1]);
                    $counts['agregados']++;
                }
            }

            if ($unmatched !== []) {
                $counts['quitados'] = QuoteParticipant::query()->whereKey($unmatched)->delete();
            }

            $this->rank($quoteId, $opportunityId);

            $this->audit->record('oferta.participantes', $locked, $counts + ['total' => count($rows)], $actor);

            return $this->participantsOf($locked);
        });
    }

    /**
     * Posición de nuestra oferta (1 = la más baja), la más baja y la diferencia contra ella.
     *
     * @return array{count: int, ours_position: ?int, ours_amount: ?float, lowest_amount: ?float, lowest_name: ?string, difference: ?float, difference_percent: ?float, winner_name: ?string, winner_amount: ?float}
     */
    public function participantSummary(Collection $participants): array
    {
        $sorted = $participants->sortBy([['amount', 'asc'], ['id', 'asc']])->values();
        $ours = $sorted->first(fn (QuoteParticipant $participant) => $participant->is_ours);
        $lowest = $sorted->first();
        $winner = $sorted->first(fn (QuoteParticipant $participant) => $participant->is_winner);
        $difference = $ours && $lowest ? round((float) $ours->amount - (float) $lowest->amount, 2) : null;

        return [
            'count' => $sorted->count(),
            'ours_position' => $ours ? $sorted->search(fn (QuoteParticipant $participant) => (float) $participant->amount >= (float) $ours->amount) + 1 : null,
            'ours_amount' => $ours ? (float) $ours->amount : null,
            'lowest_amount' => $lowest ? (float) $lowest->amount : null,
            'lowest_name' => $lowest?->name,
            'difference' => $difference,
            'difference_percent' => $difference !== null && (float) $lowest->amount > 0 ? round($difference / (float) $lowest->amount * 100, 2) : null,
            'winner_name' => $winner?->name,
            'winner_amount' => $winner ? (float) $winner->amount : null,
        ];
    }

    /**
     * Garantiza la fila «nuestra oferta» de una cotización (al presentarla o ganarla).
     */
    public function ensureOurOffer(Quote $quote): QuoteParticipant
    {
        $ours = QuoteParticipant::query()->ofProcess($quote->id, $quote->opportunity_id)->where('is_ours', true)->first();

        if ($ours !== null) {
            return $ours;
        }

        $participant = QuoteParticipant::query()->create([
            'quote_id' => $quote->id,
            'opportunity_id' => $quote->opportunity_id,
            'name' => Company::query()->whereKey($quote->company_id)->value('name') ?? 'Nuestra oferta',
            'tax_id' => Company::query()->whereKey($quote->company_id)->value('tax_id'),
            'amount' => (float) $quote->total,
            'currency' => 'HNL',
            'includes_tax' => true,
            'is_ours' => true,
            'position' => 0,
        ]);

        $this->rank($quote->id, $quote->opportunity_id);

        return $participant;
    }

    // ───────────────────────────── Internos ─────────────────────────────

    /**
     * Desde el kanban (sujeto = oportunidad) el resultado solo se registra con la oferta presentada.
     * Si el sujeto es una cotización manda la regla de la cotización (Emitida o Presentada).
     */
    protected function assertOfferSubmitted(Quote|Opportunity $subject, Opportunity $opportunity, string $outcome): void
    {
        if ($subject instanceof Opportunity && $opportunity->stage !== 'oferta_presentada') {
            $stage = Opportunity::STAGES[$opportunity->stage] ?? $opportunity->stage;

            throw ValidationException::withMessages(['stage' => "La oportunidad está en «{$stage}»: solo se marca como {$outcome} cuando está en Oferta Presentada."]);
        }
    }

    /**
     * Bloquea la cotización y su oportunidad (en ese orden, siempre) y resuelve el sujeto.
     *
     * @return array{0: ?Quote, 1: ?Opportunity}
     */
    protected function lockSubject(Quote|Opportunity $subject, mixed $quoteId = null, bool $allowSeveral = false): array
    {
        if ($subject instanceof Quote) {
            $quote = Quote::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
            $opportunity = $this->opportunityOf($quote, lock: true);

            return [$quote, $opportunity];
        }

        $live = Quote::query()->where('opportunity_id', $subject->id)
            ->whereNotIn('status', Quote::CLOSED_STATUSES)->where('outcome', 'Pendiente')
            ->when(filled($quoteId), fn ($query) => $query->whereKey((int) $quoteId))
            ->orderBy('id')->lockForUpdate()->get();

        if (filled($quoteId) && $live->isEmpty()) {
            throw ValidationException::withMessages(['quote_id' => 'La cotización indicada no es una cotización viva de esta oportunidad.']);
        }

        $opportunity = Opportunity::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail();

        if ($live->count() === 1) {
            // Perder desde el kanban con una sola cotización aún en Borrador: el resultado es de la
            // oportunidad y la cotización queda Perdida junto con ella.
            if ($allowSeveral && ! filled($quoteId) && ! $live->first()->isAdjudicable()) {
                return [null, $opportunity];
            }

            return [$live->first(), $opportunity];
        }

        if ($live->count() > 1 && ! $allowSeveral) {
            throw ValidationException::withMessages(['quote_id' => 'La oportunidad tiene varias cotizaciones vivas: indique cuál se ganó.']);
        }

        if ($opportunity->stage === 'adjudicado' && $opportunity->quote_id) {
            return [Quote::query()->find($opportunity->quote_id), $opportunity];
        }

        return [null, $opportunity];
    }

    /**
     * @return array{0: ?Quote, 1: ?Opportunity}
     */
    protected function lockForRevert(Quote|Opportunity $subject): array
    {
        if ($subject instanceof Quote) {
            $quote = Quote::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail();

            return [$quote, $this->opportunityOf($quote, lock: true)];
        }

        $won = $this->wonQuoteOf($subject);
        $quote = $won ? Quote::query()->whereKey($won->id)->lockForUpdate()->first() : null;

        return [$quote, Opportunity::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail()];
    }

    protected function wonQuoteOf(Opportunity $opportunity): ?Quote
    {
        return Quote::query()->where('opportunity_id', $opportunity->id)->whereIn('outcome', ['Ganada', 'Perdida'])
            ->orderByRaw("CASE WHEN outcome = 'Ganada' THEN 0 ELSE 1 END")
            ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $opportunity->quote_id])
            ->first();
    }

    /** Oportunidad de la cotización, o la de su licitación si la cotización no la enlaza. */
    protected function opportunityOf(Quote $quote, bool $lock = false): ?Opportunity
    {
        $id = $quote->opportunity_id
            ?? ($quote->tender_id ? Opportunity::query()->where('tender_id', $quote->tender_id)->value('id') : null);

        if ($id === null) {
            return null;
        }

        $query = Opportunity::query()->whereKey($id);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /** Las demás cotizaciones vivas y pendientes de la misma oportunidad. */
    protected function siblings(?Quote $quote, ?Opportunity $opportunity): Collection
    {
        if ($opportunity === null) {
            return collect();
        }

        return Quote::query()->where('opportunity_id', $opportunity->id)
            ->when($quote, fn ($query) => $query->whereKeyNot($quote->id))
            ->whereNotIn('status', Quote::CLOSED_STATUSES)
            ->where('outcome', 'Pendiente')
            ->lockForUpdate()->get();
    }

    protected function existingWin(?Quote $quote, ?Opportunity $opportunity): AdjudicationResult
    {
        $companyId = (int) ($quote?->company_id ?? $opportunity->company_id);
        $contract = $this->contractFor($companyId, $quote, $opportunity);
        $clientId = $contract?->client_id ?? $quote?->client_id;

        return new AdjudicationResult(
            'Ganada',
            $quote,
            $opportunity,
            $clientId ? Client::query()->withoutGlobalScope(CompanyScope::class)->find($clientId) : null,
            $contract,
            alreadyApplied: true,
        );
    }

    protected function amount(mixed $value, mixed $fallback): float
    {
        $amount = $value !== null && $value !== '' ? (float) $value : (float) ($fallback ?? 0);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['awarded_amount' => 'Indique el monto adjudicado.']);
        }

        return round($amount, 2);
    }

    protected function institutionOf(?Quote $quote, ?Opportunity $opportunity): ?Institution
    {
        $id = $quote?->institution_id ?? $opportunity?->institution_id;

        return $id ? Institution::query()->find($id) : null;
    }

    /**
     * Crea o reutiliza el cliente (clave de deduplicación por empresa e institución) y suma el monto
     * a su acumulado una sola vez. Si estaba eliminado, se restaura. Ante una alta simultánea que
     * gane la carrera del índice único, se reintenta la búsqueda.
     */
    protected function resolveClient(int $companyId, ?Institution $institution, ?Quote $quote, ?Opportunity $opportunity, float $amount): Client
    {
        $name = $institution?->name ?? $opportunity?->title ?? $quote?->purchase_type ?? 'Cliente';
        $dedupeKey = Client::dedupeKeyFor($companyId, $institution?->id, $name);
        $taxId = filled($institution?->tax_id) ? $institution->tax_id : null;

        $find = function () use ($companyId, $dedupeKey, $taxId): ?Client {
            $base = fn () => Client::withTrashed()->withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId);

            return $base()->where('dedupe_key', $dedupeKey)->lockForUpdate()->first()
                ?? ($taxId ? $base()->where('tax_id', $taxId)->lockForUpdate()->first() : null);
        };

        $client = $find();

        if ($client === null) {
            $contact = $quote?->contact ?? $opportunity?->contact;

            try {
                $client = DB::transaction(fn () => Client::query()->withoutGlobalScope(CompanyScope::class)->create([
                    'company_id' => $companyId,
                    'institution_id' => $institution?->id,
                    'source_quote_id' => $quote?->id,
                    'origin' => 'Adjudicacion',
                    'dedupe_key' => $dedupeKey,
                    'name' => $name,
                    'tax_id' => $taxId,
                    'type' => 'Publico',
                    'sector' => $institution?->type,
                    'contact_name' => $contact?->name,
                    'phone' => $contact?->phone,
                    'email' => $contact?->email,
                    'status' => 'Activo',
                    'accumulated_amount' => 0,
                    'notes' => $quote ? "Alta automática por adjudicación de la cotización {$quote->number}." : 'Alta automática por adjudicación de la oportunidad.',
                ]));
            } catch (UniqueConstraintViolationException $exception) {
                $client = $find() ?? throw $exception;
            }
        }

        if ($client->trashed()) {
            $client->restore();
        }

        $client->forceFill([
            'accumulated_amount' => round((float) $client->accumulated_amount + $amount, 2),
            'last_purchase_at' => now()->toDateString(),
            'status' => 'Activo',
        ])->save();

        return $client;
    }

    /**
     * Contrato en «Pendiente de contrato». Uno por cotización u oportunidad: si ya existe (también uno
     * cancelado por una reversión anterior) se reutiliza en lugar de crear otro.
     */
    protected function openContract(int $companyId, Client $client, ?Quote $quote, ?Opportunity $opportunity, float $amount): Contract
    {
        $contract = $this->contractFor($companyId, $quote, $opportunity, lock: true, includeCancelled: true);
        $institutionId = $quote?->institution_id ?? $opportunity?->institution_id;
        $values = [
            'client_id' => $client->id,
            'quote_id' => $quote?->id ?? $contract?->quote_id,
            'opportunity_id' => $opportunity?->id ?? $contract?->opportunity_id,
            'tender_id' => $quote?->tender_id ?? $opportunity?->tender_id,
            'institution_id' => $institutionId,
            'contact_id' => $quote?->contact_id ?? $opportunity?->contact_id,
            'amount' => $amount,
            'awarded_amount' => $amount,
            'status' => 'Pendiente de contrato',
        ];

        if ($contract !== null) {
            $contract->forceFill($values)->save();

            return $contract;
        }

        Company::query()->whereKey($companyId)->lockForUpdate()->first();
        $institution = $institutionId ? Institution::query()->whereKey($institutionId)->value('name') : null;

        $contract = new Contract;
        $contract->forceFill($values + [
            'company_id' => $companyId,
            'number' => $this->nextContractNumber($companyId),
            'name' => collect(['Contrato', $quote?->number ?? $opportunity?->title, $institution])->filter()->implode(' · '),
            'start_trigger' => 'Orden de inicio',
            'duration_business_days' => false,
            'billed_amount' => 0,
            'collected_amount' => 0,
            'retention_amount' => 0,
            'physical_progress' => 0,
            'financial_progress' => 0,
            'product_delivered' => false,
        ])->save();

        return $contract;
    }

    protected function contractFor(int $companyId, ?Quote $quote, ?Opportunity $opportunity, bool $lock = false, bool $includeCancelled = false): ?Contract
    {
        if ($quote === null && $opportunity === null) {
            return null;
        }

        $query = Contract::query()->withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId)
            ->where(fn ($builder) => $builder
                ->when($quote, fn ($inner) => $inner->orWhere('quote_id', $quote->id))
                ->when($opportunity, fn ($inner) => $inner->orWhere('opportunity_id', $opportunity->id)))
            ->when(! $includeCancelled, fn ($builder) => $builder->where('status', '!=', 'Cancelado'))
            ->orderByRaw("CASE WHEN status = 'Cancelado' THEN 1 ELSE 0 END")
            ->latest('id');

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /** Actividad que impide revertir (decisión 15), o null. */
    protected function contractActivity(Contract $contract): ?string
    {
        $documents = DocumentLink::query()->where('linkable_type', Contract::class)->where('linkable_id', $contract->id)->count();
        $deliveries = $contract->deliveries()->count();
        $followUps = $contract->followUps()->count();

        if ($documents + $deliveries + $followUps === 0) {
            return null;
        }

        $parts = array_filter([
            $documents ? "{$documents} documento".($documents > 1 ? 's' : '') : null,
            $deliveries ? "{$deliveries} entrega".($deliveries > 1 ? 's' : '') : null,
            $followUps ? "{$followUps} seguimiento".($followUps > 1 ? 's' : '') : null,
        ]);

        return "No se puede revertir: el contrato {$contract->number} ya tiene ".implode(', ', $parts).'.';
    }

    protected function nextContractNumber(int $companyId): string
    {
        $prefix = 'CTR-'.now()->year.'-';
        $numbers = fn () => Contract::query()->withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId);

        $sequence = (int) $numbers()->where('number', 'like', $prefix.'%')->pluck('number')
            ->map(fn ($number) => (int) substr((string) $number, strlen($prefix)))->max() + 1;

        do {
            $candidate = $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
            $sequence++;
        } while ($numbers()->where('number', $candidate)->exists());

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    protected function markTender(?Quote $quote, ?Opportunity $opportunity, array $values): void
    {
        $tenderId = $quote?->tender_id ?? $opportunity?->tender_id;

        if ($tenderId !== null && ($tender = Tender::query()->find($tenderId))) {
            $tender->forceFill($values)->save();
        }
    }

    /** Marca un único ganador entre los participantes del proceso. */
    protected function markWinner(?Quote $quote, ?Opportunity $opportunity, callable $isWinner): void
    {
        QuoteParticipant::query()->ofProcess($quote?->id, $opportunity?->id)->get()
            ->each(function (QuoteParticipant $participant) use ($isWinner) {
                $winner = (bool) $isWinner($participant);
                if ($participant->is_winner !== $winner) {
                    $participant->forceFill(['is_winner' => $winner])->save();
                }
            });
    }

    /**
     * Ganador de una oferta perdida: un participante elegido o uno escrito (se registra si no está).
     *
     * @param  array<string, mixed>  $data
     */
    protected function winnerFrom(?Quote $quote, ?Opportunity $opportunity, array $data): QuoteParticipant
    {
        [$quoteId, $opportunityId] = [$quote?->id, $opportunity?->id];
        $process = QuoteParticipant::query()->ofProcess($quoteId, $opportunityId);

        if (filled($data['winner_participant_id'] ?? null)) {
            $chosen = (clone $process)->whereKey((int) $data['winner_participant_id'])->first();
            if ($chosen === null) {
                throw ValidationException::withMessages(['winner_participant_id' => 'El participante elegido no pertenece a este proceso.']);
            }
            if ($chosen->is_ours) {
                throw ValidationException::withMessages(['winner_participant_id' => 'Nuestra oferta no puede ser la ganadora de una oferta perdida.']);
            }
            if (filled($data['winner_amount'] ?? null) && (float) $data['winner_amount'] > 0) {
                $chosen->forceFill(['amount' => round((float) $data['winner_amount'], 2)])->save();
            }

            return $chosen;
        }

        $name = trim((string) ($data['winner_name'] ?? ''));
        $amount = (float) ($data['winner_amount'] ?? 0);

        if ($name === '') {
            throw ValidationException::withMessages(['winner_name' => 'Indique quién ganó la oferta.']);
        }
        if ($amount <= 0) {
            throw ValidationException::withMessages(['winner_amount' => 'Indique el valor de la oferta ganadora.']);
        }

        $key = $this->normalizeName($name);
        $match = (clone $process)->get()->first(fn (QuoteParticipant $participant) => ! $participant->is_ours && $this->normalizeName($participant->name) === $key);

        if ($match !== null) {
            $match->forceFill(['amount' => round($amount, 2), 'tax_id' => $data['winner_tax_id'] ?? $match->tax_id])->save();
            $this->rank($quoteId, $opportunityId);

            return $match->fresh();
        }

        $winner = QuoteParticipant::query()->create([
            'quote_id' => $quoteId,
            'opportunity_id' => $opportunityId,
            'name' => $name,
            'tax_id' => filled($data['winner_tax_id'] ?? null) ? (string) $data['winner_tax_id'] : null,
            'amount' => round($amount, 2),
            'currency' => 'HNL',
            'includes_tax' => true,
            'is_ours' => false,
            'position' => 0,
        ]);

        $this->rank($quoteId, $opportunityId);

        return $winner->fresh();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function validateParticipants(Quote|Opportunity $subject, array $rows): void
    {
        $errors = [];
        $ours = array_keys(array_filter($rows, fn ($row) => ! empty($row['is_ours'])));
        $winners = array_keys(array_filter($rows, fn ($row) => ! empty($row['is_winner'])));

        if (count($ours) > 1) {
            $errors["participants.{$ours[1]}.is_ours"] = 'Solo una fila puede ser «nuestra oferta».';
        }
        if (count($winners) > 1) {
            $errors["participants.{$winners[1]}.is_winner"] = 'Solo una fila puede ser el ganador.';
        }

        $known = $subject instanceof Quote
            ? ! $subject->isPending()
            : in_array($subject->stage, Opportunity::CLOSED_STAGES, true);

        if ($winners !== [] && ! $known) {
            $errors["participants.{$winners[0]}.is_winner"] = 'El ganador se marca cuando se conoce el resultado (Ganada o Perdida).';
        }

        $names = [];
        foreach (array_values($rows) as $index => $row) {
            $key = $this->normalizeName((string) ($row['name'] ?? ''));
            if ($key !== '' && isset($names[$key])) {
                $errors["participants.{$index}.name"] = 'Este participante ya está en la lista.';
            }
            $names[$key] = true;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    protected function processKeys(Quote|Opportunity $subject): array
    {
        if ($subject instanceof Opportunity) {
            return [null, (int) $subject->id];
        }

        return [(int) $subject->id, $subject->opportunity_id ? (int) $subject->opportunity_id : null];
    }

    /** Posición por monto, de la más baja a la más alta. */
    protected function rank(?int $quoteId, ?int $opportunityId): void
    {
        QuoteParticipant::query()->ofProcess($quoteId, $opportunityId)
            ->orderBy('amount')->orderBy('id')->get()
            ->values()
            ->each(function (QuoteParticipant $participant, int $index) {
                if ($participant->position !== $index + 1) {
                    $participant->forceFill(['position' => $index + 1])->save();
                }
            });
    }

    protected function normalizeName(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    protected function syncCalendar(AdjudicationResult $result): void
    {
        if ($result->alreadyApplied) {
            return;
        }

        if ($result->quote !== null) {
            $this->calendar->syncForQuote($result->quote);
        } elseif ($result->opportunity !== null) {
            $this->calendar->sync($result->opportunity);
        }

        if ($result->contract !== null) {
            $this->calendar->sync($result->contract);
        }
    }
}
