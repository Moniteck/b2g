<?php

namespace App\Services\Tenders;

use App\Models\Tender;
use App\Services\System\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Estados automáticos (hallazgo B-19): una licitación abierta (Activa / En preparación) cuya
 * recepción de ofertas ya pasó sin oferta presentada pasa a «Vencida».
 *
 * Se guarda con Eloquent, registro por registro, para que corran los observadores (calendario,
 * caché de métricas). No toca la que tiene una cotización presentada aunque falte la marca de
 * oferta (la cotización manda) ni las de estados cerrados. Idempotente.
 *
 *   app(TenderLifecycle::class)->expireOverdue();          // todas las empresas (comando diario)
 *   app(TenderLifecycle::class)->expireOverdue($companyId);
 */
class TenderLifecycle
{
    public function __construct(protected AuditLogger $audit) {}

    /**
     * @return int licitaciones que pasaron a «Vencida»
     */
    public function expireOverdue(?int $companyId = null): int
    {
        $count = 0;

        Tender::query()->withoutGlobalScopes()
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->whereIn('status', Tender::OPEN_STATUSES)
            ->where('offer_submitted', false)
            ->whereNotNull('presentation_deadline')
            ->where('presentation_deadline', '<', now())
            ->whereNotExists(fn ($quotes) => $quotes->from('quotes')
                ->whereColumn('quotes.tender_id', 'tenders.id')
                ->whereIn('quotes.status', ['Presentada', 'Sustituida']))
            ->select(['id', 'company_id', 'reference', 'status'])
            ->chunkById(200, function ($tenders) use (&$count) {
                foreach ($tenders as $candidate) {
                    DB::transaction(function () use ($candidate, &$count) {
                        $tender = Tender::query()->withoutGlobalScopes()->whereKey($candidate->id)->lockForUpdate()->first();

                        if ($tender === null || ! in_array($tender->status, Tender::OPEN_STATUSES, true) || $tender->offer_submitted) {
                            return;
                        }

                        $previous = $tender->status;
                        $tender->status = TenderStage::STATUS_EXPIRED;
                        $tender->save();
                        $count++;

                        (clone $this->audit)->forChannel('sistema')->record('licitacion.vencida', $tender, [
                            'expediente' => $tender->reference,
                            'estado_anterior' => $previous,
                            'recepcion' => $tender->presentation_deadline?->format('Y-m-d H:i'),
                        ], null, (int) $tender->company_id);
                    });
                }
            });

        return $count;
    }
}
