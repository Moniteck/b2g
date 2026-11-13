<?php

namespace App\Services\Business;

use App\Models\Client;
use App\Models\Company;
use App\Models\Quote;
use App\Models\QuoteParticipant;
use App\Services\AuditLogger;
use App\Services\Notifications\CalendarSyncService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Operacion unica de resultado comercial.
 *
 * tanto la interfaz web como el servidor MCP pasan por aqui, de modo que la
 * adjudicacion produce siempre los mismos efectos: actualiza la cotizacion y
 * la oportunidad, crea o reutiliza el cliente y abre el expediente de
 * post-adjudicacion.
 */
class AdjudicationService
{
    public function __construct(
        protected AuditLogger $audit,
        protected CalendarSyncService $calendar,
    ) {}

    /**
     * Marca una cotizacion como ganada y ejecuta todos los efectos derivados.
     */
    public function markWon(
        Quote $quote,
        array $data,
        ?int $actorId = null,
    ): Quote {
        $companyId = (int) $quote->company_id;

        if ($companyId <= 0) {
            throw new RuntimeException('La cotizacion debe pertenecer a una empresa.');
        }

        $awardAmount = $data['awarded_amount'] ?? $quote->total;

        $quote = DB::transaction(function () use ($quote, $data, $awardAmount, $companyId) {
            $quote->forceFill([
                'status' => 'Presentada',
                'outcome' => 'Ganada',
                'awarded_amount' => $awardAmount,
                'awarded_at' => $data['awarded_at'] ?? now()->toDateString(),
                'winner_name' => $data['winner_name'] ?? null,
            ])->save();

            $opportunity = $quote->opportunity;
            if ($opportunity) {
                $opportunity->forceFill([
                    'stage' => 'adjudicado',
                    'awarded_amount' => $awardAmount,
                    'awarded_at' => $data['awarded_at'] ?? now()->toDateString(),
                    'winner_name' => $data['winner_name'] ?? null,
                ])->save();
            }

            $tender = $quote->tender;
            if ($tender) {
                $tender->forceFill([
                    'offer_submitted' => true,
                    'result' => 'Ganada',
                    'status' => 'Adjudicada',
                ])->save();
            }

            $client = $this->resolveClient($quote, $companyId);
            $quote->forceFill(['client_id' => $client->id])->save();

            $this->audit->record('adjudicacion.ganada', $quote, [
                'outcome' => 'Ganada',
                'awarded_amount' => $awardAmount,
                'client_id' => $client->id,
            ]);

            return $quote->fresh(['opportunity', 'participants']);
        });

        $this->calendar->syncForQuote($quote);

        return $quote;
    }

    /**
     * Marca una cotizacion como no adjudicada, registrando ganador y monto.
     */
    public function markLost(Quote $quote, array $data): Quote
    {
        $quote = DB::transaction(function () use ($quote, $data) {
            $quote->forceFill([
                'status' => 'Presentada',
                'outcome' => 'Perdida',
                'winner_name' => $data['winner_name'] ?? null,
                'awarded_amount' => $data['awarded_amount'] ?? null,
                'awarded_at' => $data['awarded_at'] ?? now()->toDateString(),
                'lost_reason' => $data['lost_reason'] ?? null,
            ])->save();

            $opportunity = $quote->opportunity;
            if ($opportunity) {
                $opportunity->forceFill([
                    'stage' => 'no_adjudicado',
                    'winner_name' => $data['winner_name'] ?? null,
                    'awarded_amount' => $data['awarded_amount'] ?? null,
                    'awarded_at' => $data['awarded_at'] ?? now()->toDateString(),
                    'lost_reason' => $data['lost_reason'] ?? null,
                ])->save();
            }

            $tender = $quote->tender;
            if ($tender) {
                $tender->forceFill(['offer_submitted' => true, 'result' => 'No adjudicada'])->save();
            }

            $this->audit->record('adjudicacion.perdida', $quote, [
                'outcome' => 'Perdida',
                'winner_name' => $data['winner_name'] ?? null,
                'awarded_amount' => $data['awarded_amount'] ?? null,
            ]);

            return $quote->fresh(['opportunity']);
        });

        $this->calendar->syncForQuote($quote);

        return $quote;
    }

    /**
     * Registra los participantes de una oferta presentada.
     *
     * @param  array<int, array<string, mixed>>  $participants
     */
    public function syncParticipants(Quote $quote, array $participants): Quote
    {
        DB::transaction(function () use ($quote, $participants) {
            $quote->participants()->delete();

            foreach ($participants as $index => $participant) {
                QuoteParticipant::create([
                    'quote_id' => $quote->id,
                    'name' => $participant['name'],
                    'tax_id' => $participant['tax_id'] ?? null,
                    'amount' => $participant['amount'] ?? 0,
                    'currency' => $participant['currency'] ?? 'HNL',
                    'includes_tax' => $participant['includes_tax'] ?? true,
                    'is_ours' => $participant['is_ours'] ?? false,
                    'position' => $participant['position'] ?? ($index + 1),
                    'notes' => $participant['notes'] ?? null,
                ]);
            }

            $this->audit->record('oferta.participantes', $quote, [
                'participantes' => count($participants),
            ]);
        });

        return $quote->fresh(['participants']);
    }

    /**
     * Crea o reutiliza el cliente a partir de la adjudicacion.
     */
    protected function resolveClient(Quote $quote, int $companyId): Client
    {
        $institution = $quote->institution;
        $name = $institution?->name ?? $quote->purchase_type;
        $dedupeKey = Client::dedupeKeyFor($companyId, $institution?->id, $name);

        $client = Client::query()->withoutGlobalScopes()->where('dedupe_key', $dedupeKey)->first();

        if ($client) {
            $client->forceFill([
                'accumulated_amount' => (float) $client->accumulated_amount + (float) ($quote->awarded_amount ?? $quote->total),
                'last_purchase_at' => now()->toDateString(),
                'status' => 'Activo',
            ])->save();

            return $client;
        }

        $company = Company::query()->find($companyId);

        return Client::create([
            'company_id' => $companyId,
            'institution_id' => $institution?->id,
            'source_quote_id' => $quote->id,
            'origin' => 'Adjudicacion',
            'dedupe_key' => $dedupeKey,
            'name' => $name,
            'tax_id' => $institution?->tax_id,
            'type' => 'Publico',
            'sector' => $institution?->type,
            'contact_name' => $quote->contact?->name,
            'phone' => $quote->contact?->phone,
            'email' => $quote->contact?->email,
            'status' => 'Activo',
            'accumulated_amount' => (float) ($quote->awarded_amount ?? $quote->total),
            'last_purchase_at' => now()->toDateString(),
            'notes' => "Alta automatica por adjudicacion de la cotizacion {$quote->number}.",
        ]);
    }
}
