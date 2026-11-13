<?php

namespace App\Services\Notifications;

use App\Models\Contract;
use App\Models\Document;
use App\Models\Event;
use App\Models\Opportunity;
use App\Models\Quote;
use App\Models\Tender;
use App\Services\Tenancy\CompanyContext;
use Illuminate\Support\Carbon;

/**
 * Mantiene el calendario sincronizado con los plazos reales del negocio.
 *
 * Los eventos derivados se identifican por `reference_type` + `reference_id`,
 * de modo que cambiar una fecha replace el evento anterior en lugar de
 * duplicarlo.
 */
class CalendarSyncService
{
    public function __construct(protected CompanyContext $context) {}

    public function syncForTender(Tender $tender): void
    {
        $this->context->runFor($tender->company_id, function () use ($tender) {
            $this->replaceDerived('tender', $tender->id, $tender->company_id);

            $this->upsert('tender', $tender->id, $tender->company_id, [
                'title' => "Inicio de {$tender->title}",
                'type' => 'Licitacion',
                'starts_at' => $tender->published_at?->copy()->startOfDay(),
                'priority' => 'Normal',
                'institution_id' => $tender->institution_id,
                'tender_id' => $tender->id,
                'description' => $tender->reference,
            ]);

            $this->upsert('tender', $tender->id, $tender->company_id, [
                'title' => "Cierre de aclaraciones - {$tender->title}",
                'type' => 'Aclaraciones',
                'starts_at' => $tender->clarification_deadline,
                'priority' => 'Alta',
                'institution_id' => $tender->institution_id,
                'tender_id' => $tender->id,
                'description' => 'Ultima oportunidad para solicitar aclaraciones del proceso.',
            ]);

            $this->upsert('tender', $tender->id, $tender->company_id, [
                'title' => "Cierre de presentacion de ofertas - {$tender->title}",
                'type' => 'Presentacion',
                'starts_at' => $tender->presentation_deadline ?? $tender->closing_date?->copy()->endOfDay(),
                'priority' => 'Alta',
                'institution_id' => $tender->institution_id,
                'tender_id' => $tender->id,
                'description' => 'Fecha y hora limite para presentar la oferta.',
            ]);

            $this->upsert('tender', $tender->id, $tender->company_id, [
                'title' => "Apertura de ofertas - {$tender->title}",
                'type' => 'Apertura',
                'starts_at' => $tender->opening_at,
                'priority' => 'Normal',
                'institution_id' => $tender->institution_id,
                'tender_id' => $tender->id,
                'description' => 'Acto de apertura de las propuestas.',
            ]);
        });
    }

    public function syncForOpportunity(Opportunity $opportunity): void
    {
        $this->context->runFor($opportunity->company_id, function () use ($opportunity) {
            $this->replaceDerived('opportunity', $opportunity->id, $opportunity->company_id);

            $this->upsert('opportunity', $opportunity->id, $opportunity->company_id, [
                'title' => "Cierre esperado - {$opportunity->title}",
                'type' => 'Presentacion',
                'starts_at' => $opportunity->expected_close_date?->copy()->endOfDay(),
                'priority' => 'Normal',
                'opportunity_id' => $opportunity->id,
                'institution_id' => $opportunity->institution_id,
                'description' => 'Fecha estimada de cierre de la oportunidad.',
            ]);
        });
    }

    public function syncForQuote(Quote $quote): void
    {
        $quote->loadMissing(['tender', 'opportunity']);

        if ($quote->tender) {
            $this->syncForTender($quote->tender);
        }

        if ($quote->opportunity) {
            $this->syncForOpportunity($quote->opportunity);
        }
    }

    public function syncForContract(Contract $contract): void
    {
        $this->context->runFor($contract->company_id, function () use ($contract) {
            $this->replaceDerived('contract', $contract->id, $contract->company_id);

            $this->upsert('contract', $contract->id, $contract->company_id, [
                'title' => "Inicio de vigencia - {$contract->name}",
                'type' => 'Contrato',
                'starts_at' => $contract->start_date?->copy()->startOfDay(),
                'ends_at' => $contract->end_date?->copy()->endOfDay(),
                'priority' => 'Alta',
                'description' => "Disparador de inicio: {$contract->start_trigger}.",
            ]);

            $this->upsert('contract', $contract->id, $contract->company_id, [
                'title' => "Vencimiento de contrato - {$contract->name}",
                'type' => 'Contrato',
                'starts_at' => $contract->end_date?->copy()->startOfDay(),
                'priority' => 'Alta',
                'description' => 'Fecha limite de vigencia pactada.',
            ]);
        });
    }

    public function syncForDocument(Document $document): void
    {
        $this->context->runFor($document->company_id, function () use ($document) {
            $this->replaceDerived('document', $document->id, $document->company_id);

            $this->upsert('document', $document->id, $document->company_id, [
                'title' => "Vencimiento: {$document->name}",
                'type' => 'Documento',
                'starts_at' => $document->expires_at?->copy()->startOfDay(),
                'priority' => 'Alta',
                'institution_id' => $document->institution_id,
                'description' => $document->kindLabel(),
            ]);
        });
    }

    /**
     * Elimina los eventos derivados de una referencia antes de recrearlos.
     */
    protected function replaceDerived(string $referenceType, int $referenceId, ?int $companyId): void
    {
        Event::query()
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->where('origin', '!=', 'manual')
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function upsert(string $referenceType, int $referenceId, ?int $companyId, array $attributes): void
    {
        if (empty($attributes['starts_at'])) {
            return;
        }

        $startsAt = $attributes['starts_at'] instanceof Carbon
            ? $attributes['starts_at']
            : Carbon::parse($attributes['starts_at']);

        Event::query()->updateOrCreate(
            [
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'title' => $attributes['title'],
                'type' => $attributes['type'],
            ],
            [
                'company_id' => $companyId,
                'user_id' => null,
                'institution_id' => $attributes['institution_id'] ?? null,
                'opportunity_id' => $attributes['opportunity_id'] ?? null,
                'tender_id' => $attributes['tender_id'] ?? null,
                'description' => $attributes['description'] ?? null,
                'starts_at' => $startsAt,
                'ends_at' => $attributes['ends_at'] ?? null,
                'priority' => $attributes['priority'] ?? 'Normal',
                'origin' => $referenceType,
                'remindable' => true,
                'completed' => false,
            ]
        );
    }
}
