<?php

namespace App\Services\Notifications;

use App\Models\Contract;
use App\Models\ContractDelivery;
use App\Models\ContractFollowUp;
use App\Models\Document;
use App\Models\Event;
use App\Models\Opportunity;
use App\Models\Quote;
use App\Models\Tender;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Mantiene el calendario al día con los plazos reales del negocio.
 *
 * Cada plazo derivado tiene una clave estable `origen:id:tipo` (`source_key`). Guardar el origen
 * actualiza el evento EN SU SITIO (nunca se borra y recrea) y reprograma sus avisos pendientes
 * conservando los enviados. Si el origen se elimina, se cierra, se cancela o pierde la fecha, el
 * evento queda cancelado y sus avisos pendientes se cancelan. Todo es idempotente: los
 * observadores y los controladores pueden llamarlo las veces que quieran.
 *
 * No depende de la empresa activa: lee y escribe sin el scope de empresa y con el `company_id`
 * del registro de origen, de modo que funciona igual en web, consola y colas.
 */
class CalendarSyncService
{
    /** Estados de licitación que cierran el proceso: ya no hay plazos que vigilar. */
    public const CLOSED_TENDER_STATUSES = ['Adjudicada', 'No adjudicada', 'Perdida', 'Cancelada', 'Desierta', 'Cerrada', 'Vencida'];

    /** Estados de licitación en que la oferta ya se presentó. */
    public const SUBMITTED_TENDER_STATUSES = ['Presentada'];

    public const CLOSED_CONTRACT_STATUSES = ['Cerrado', 'Cancelado'];

    public const DONE_DELIVERY_STATUSES = ['Entregada', 'Entregado', 'Recibida', 'Cancelada', 'Cancelado'];

    public function __construct(protected ReminderService $reminders) {}

    /**
     * Sincroniza el registro que corresponda según su clase (lo usan los observadores).
     */
    public function sync(Model $model): void
    {
        match (true) {
            $model instanceof Tender => $this->syncForTender($model),
            $model instanceof Opportunity => $this->syncForOpportunity($model),
            $model instanceof Contract => $this->syncForContract($model),
            $model instanceof ContractDelivery => $this->syncForDelivery($model),
            $model instanceof ContractFollowUp => $this->syncForFollowUp($model),
            $model instanceof Document => $this->syncForDocument($model),
            $model instanceof Quote => $this->syncForQuote($model),
            default => null,
        };
    }

    /**
     * El registro se eliminó: sus eventos quedan cancelados (con su historial de avisos).
     */
    public function removed(Model $model): void
    {
        $type = self::referenceType($model);

        if ($type === null) {
            return;
        }

        $this->cancelSource($type, (int) $model->getKey(), 'El registro de origen se eliminó.');

        if ($model instanceof Contract) {
            $this->cancelContractChildren($model, 'El contrato se eliminó.');
        }
    }

    public function syncForTender(Tender $tender): void
    {
        $closed = in_array($tender->status, self::CLOSED_TENDER_STATUSES, true);
        $submitted = $tender->offer_submitted || in_array($tender->status, self::SUBMITTED_TENDER_STATUSES, true);
        $label = $tender->reference ?: $tender->title;
        $base = [
            'company_id' => $tender->company_id,
            'institution_id' => $tender->institution_id,
            'tender_id' => $tender->id,
            'owner_id' => $tender->owner_id,
            'created_by' => $tender->getAttribute('created_by') ?? $tender->owner_id,
            'label' => $label,
        ];
        $closedReason = $closed ? "La licitación quedó en estado {$tender->status}." : null;

        [$startsAt, $allDay] = $this->tenderStart($tender);

        $this->upsert('tender', $tender->id, 'Licitacion', $base + [
            'title' => "Inicio de licitación: {$label}",
            'description' => $tender->title,
            'starts_at' => $startsAt,
            'ends_at' => $tender->presentation_deadline ?? $tender->closing_date?->copy()->endOfDay(),
            'all_day' => $allDay,
            'priority' => 'Normal',
        ], $closedReason);

        $this->upsert('tender', $tender->id, 'Aclaraciones', $base + [
            'title' => "Cierre de aclaraciones: {$label}",
            'description' => 'Último día para enviar preguntas sobre el pliego.',
            'starts_at' => $tender->clarification_deadline,
            'priority' => 'Alta',
            'completed' => $submitted,
        ], $closedReason);

        $presentation = $tender->presentation_deadline;
        $presentationAllDay = false;
        if ($presentation === null && $tender->closing_date !== null) {
            $presentation = $tender->closing_date->copy()->startOfDay();
            $presentationAllDay = true;
        }

        $this->upsert('tender', $tender->id, 'Presentacion', $base + [
            'title' => "Presentación de ofertas: {$label}",
            'description' => 'Fecha y hora límite para presentar la oferta.',
            'starts_at' => $presentation,
            'all_day' => $presentationAllDay,
            'priority' => 'Alta',
            'completed' => $submitted,
        ], $closedReason);

        $this->upsert('tender', $tender->id, 'Apertura', $base + [
            'title' => "Apertura de ofertas: {$label}",
            'description' => 'Acto público de apertura de las ofertas.',
            'starts_at' => $tender->opening_at,
            'priority' => 'Normal',
        ], $closedReason);
    }

    public function syncForOpportunity(Opportunity $opportunity): void
    {
        $closed = in_array($opportunity->stage, Opportunity::CLOSED_STAGES, true);

        $this->upsert('opportunity', $opportunity->id, 'Oportunidad', [
            'company_id' => $opportunity->company_id,
            'institution_id' => $opportunity->institution_id,
            'opportunity_id' => $opportunity->id,
            'tender_id' => $opportunity->tender_id,
            'owner_id' => $opportunity->owner_id,
            'created_by' => $opportunity->getAttribute('created_by') ?? $opportunity->owner_id,
            'label' => $opportunity->title,
            'title' => "Cierre esperado: {$opportunity->title}",
            'description' => $opportunity->next_action,
            'starts_at' => $opportunity->expected_close_date?->copy()->startOfDay(),
            'all_day' => true,
            'priority' => 'Normal',
        ], $closed ? 'La oportunidad ya tiene resultado.' : null);

        // Con resultado (ganada o perdida) los plazos de la oferta dejan de avisar; si se revierte
        // el resultado, vuelven a quedar vigentes y se reprograman.
        if ($opportunity->tender_id !== null && $opportunity->wasChanged('stage')) {
            $tender = Tender::query()->withoutGlobalScopes()->find($opportunity->tender_id);

            if ($tender !== null) {
                if ($closed) {
                    foreach (['Aclaraciones', 'Presentacion', 'Apertura'] as $type) {
                        $this->cancelKey(self::key('tender', $tender->id, $type), 'La oportunidad ya tiene resultado.');
                    }
                } else {
                    $this->syncForTender($tender);
                }
            }
        }
    }

    public function syncForQuote(Quote $quote): void
    {
        if ($quote->tender_id !== null && ($tender = Tender::query()->withoutGlobalScopes()->find($quote->tender_id))) {
            $this->syncForTender($tender);
        }

        if ($quote->opportunity_id !== null && ($opportunity = Opportunity::query()->withoutGlobalScopes()->find($quote->opportunity_id))) {
            $this->syncForOpportunity($opportunity);
        }
    }

    public function syncForContract(Contract $contract): void
    {
        $closed = in_array($contract->status, self::CLOSED_CONTRACT_STATUSES, true);
        $reason = $closed ? "El contrato quedó en estado {$contract->status}." : null;
        $label = $contract->number ?: $contract->name;
        $start = $contract->start_date ?? $contract->starts_at;
        $end = $contract->end_date ?? $contract->ends_at;
        $base = [
            'company_id' => $contract->company_id,
            'institution_id' => $contract->institution_id,
            'opportunity_id' => $contract->opportunity_id,
            'tender_id' => $contract->tender_id,
            'contract_id' => $contract->id,
            'owner_id' => $contract->manager_id,
            'created_by' => $contract->getAttribute('created_by') ?? $contract->manager_id,
            'label' => $label,
            'all_day' => true,
        ];

        $this->upsert('contract', $contract->id, 'InicioContrato', $base + [
            'title' => "Inicio de contrato: {$label}",
            'description' => $contract->name,
            'starts_at' => $start?->copy()->startOfDay(),
            'ends_at' => $end?->copy()->endOfDay(),
            'priority' => 'Normal',
        ], $reason);

        $this->upsert('contract', $contract->id, 'FinContrato', $base + [
            'title' => "Fin de contrato: {$label}",
            'description' => $contract->name,
            'starts_at' => $end?->copy()->startOfDay(),
            'priority' => 'Alta',
            'completed' => (bool) $contract->product_delivered && $contract->closed_at !== null,
        ], $reason);

        // Las entregas y seguimientos heredan del contrato el responsable y el cierre.
        foreach (ContractDelivery::query()->where('contract_id', $contract->id)->get() as $delivery) {
            $this->syncForDelivery($delivery, $contract);
        }

        foreach (ContractFollowUp::query()->where('contract_id', $contract->id)->get() as $followUp) {
            $this->syncForFollowUp($followUp, $contract);
        }
    }

    public function syncForDelivery(ContractDelivery $delivery, ?Contract $contract = null): void
    {
        $contract ??= Contract::query()->withoutGlobalScopes()->find($delivery->contract_id);

        if ($contract === null) {
            $this->cancelSource('contract_delivery', $delivery->id, 'El contrato ya no existe.');

            return;
        }

        $closed = in_array($contract->status, self::CLOSED_CONTRACT_STATUSES, true);
        $label = $contract->number ?: $contract->name;

        $this->upsert('contract_delivery', $delivery->id, 'Entrega', [
            'company_id' => $contract->company_id,
            'institution_id' => $contract->institution_id,
            'tender_id' => $contract->tender_id,
            'contract_id' => $contract->id,
            'owner_id' => $contract->manager_id,
            'created_by' => $contract->manager_id,
            'label' => $label,
            'title' => "Entrega: {$delivery->description} ({$label})",
            'description' => trim(($delivery->quantity !== null ? ((float) $delivery->quantity).' '.($delivery->unit ?? '') : '').' '.($delivery->notes ?? '')) ?: null,
            'starts_at' => $delivery->due_date?->copy()->startOfDay(),
            'all_day' => true,
            'priority' => 'Alta',
            'completed' => $delivery->delivered_at !== null || in_array($delivery->status, self::DONE_DELIVERY_STATUSES, true),
        ], $closed ? "El contrato quedó en estado {$contract->status}." : null);
    }

    public function syncForFollowUp(ContractFollowUp $followUp, ?Contract $contract = null): void
    {
        $contract ??= Contract::query()->withoutGlobalScopes()->find($followUp->contract_id);

        if ($contract === null) {
            $this->cancelSource('contract_follow_up', $followUp->id, 'El contrato ya no existe.');

            return;
        }

        $closed = in_array($contract->status, self::CLOSED_CONTRACT_STATUSES, true);
        $label = $contract->number ?: $contract->name;

        $this->upsert('contract_follow_up', $followUp->id, 'Seguimiento', [
            'company_id' => $contract->company_id,
            'institution_id' => $contract->institution_id,
            'contract_id' => $contract->id,
            'owner_id' => $contract->manager_id ?? $followUp->user_id, // el aviso va al oficial de seguimiento
            'created_by' => $followUp->user_id,
            'label' => $label,
            'title' => 'Próxima acción: '.($followUp->next_action ?: 'seguimiento')." ({$label})",
            'description' => $followUp->description,
            'starts_at' => $followUp->next_action_at?->copy()->startOfDay(),
            'all_day' => true,
            'priority' => 'Normal',
        ], $closed ? "El contrato quedó en estado {$contract->status}." : null);
    }

    public function syncForDocument(Document $document): void
    {
        // Un documento archivado o reemplazado ya no vence (las versiones viven en document_versions).
        $retired = in_array($document->status, ['Archivado', 'Reemplazado'], true);

        $this->upsert('document', $document->id, 'Documento', [
            'company_id' => $document->company_id,
            'institution_id' => $document->institution_id,
            'owner_id' => $document->renewal_owner_id,
            'created_by' => $document->renewal_owner_id,
            'label' => $document->name,
            'title' => "Vence: {$document->name}",
            'description' => $document->category ?: $document->kindLabel(),
            'starts_at' => $document->expires_at?->copy()->startOfDay(),
            'all_day' => true,
            'priority' => 'Alta',
        ], $retired ? 'El documento fue sustituido por otra versión.' : null);
    }

    /**
     * Cancela todos los eventos de un origen y sus avisos pendientes.
     */
    public function cancelSource(string $referenceType, int $referenceId, string $reason): void
    {
        Event::query()->withoutGlobalScopes()
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->where('origin', '!=', 'manual')
            ->get()
            ->each(fn (Event $event) => $this->cancelEvent($event, $reason));
    }

    public static function key(string $referenceType, int $referenceId, string $type): string
    {
        return "{$referenceType}:{$referenceId}:{$type}";
    }

    public static function referenceType(Model $model): ?string
    {
        return match (true) {
            $model instanceof Tender => 'tender',
            $model instanceof Opportunity => 'opportunity',
            $model instanceof Contract => 'contract',
            $model instanceof ContractDelivery => 'contract_delivery',
            $model instanceof ContractFollowUp => 'contract_follow_up',
            $model instanceof Document => 'document',
            default => null,
        };
    }

    /**
     * Crea o actualiza en su sitio el evento de un plazo. Sin fecha o con el origen cerrado, el
     * evento (si existía) queda cancelado; si el origen se reabre, el evento se reactiva.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function upsert(string $referenceType, int $referenceId, string $type, array $attributes, ?string $closedReason = null): void
    {
        $key = self::key($referenceType, $referenceId, $type);
        $event = Event::query()->withoutGlobalScopes()->where('source_key', $key)->first();
        $startsAt = $attributes['starts_at'] ?? null;

        if ($startsAt === null || $closedReason !== null) {
            if ($event !== null) {
                $this->cancelEvent($event, $closedReason ?? 'El registro de origen ya no tiene esta fecha.');
            }

            return;
        }

        $startsAt = $startsAt instanceof Carbon ? $startsAt->copy() : Carbon::parse($startsAt);
        $completed = (bool) ($attributes['completed'] ?? false);

        $values = [
            'company_id' => $attributes['company_id'],
            'institution_id' => $attributes['institution_id'] ?? null,
            'opportunity_id' => $attributes['opportunity_id'] ?? null,
            'tender_id' => $attributes['tender_id'] ?? null,
            'contract_id' => $attributes['contract_id'] ?? null,
            'owner_id' => $attributes['owner_id'] ?? null,
            'created_by' => $attributes['created_by'] ?? null,
            'title' => mb_substr((string) $attributes['title'], 0, 255),
            'label' => $attributes['label'] !== null ? mb_substr((string) $attributes['label'], 0, 255) : null,
            'description' => $attributes['description'] ?? null,
            'type' => $type,
            'starts_at' => $startsAt,
            'ends_at' => $attributes['ends_at'] ?? null,
            'all_day' => (bool) ($attributes['all_day'] ?? false),
            'priority' => $attributes['priority'] ?? 'Normal',
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'origin' => $referenceType,
            'remindable' => true,
            'cancelled_at' => null,
            'cancel_reason' => null,
        ];

        if ($event === null) {
            $event = new Event;
            $event->source_key = $key;
            $event->forceFill($values + ['completed_at' => $completed ? now() : null])->save();
        } else {
            $event->forceFill($values);
            if ($completed && $event->completed_at === null) {
                $event->completed_at = now();
            } elseif (! $completed) {
                $event->completed_at = null;
            }
            if ($event->isDirty()) {
                $event->save();
            }
        }

        $this->reminders->syncEvent($event);
    }

    protected function cancelKey(string $key, string $reason): void
    {
        $event = Event::query()->withoutGlobalScopes()->where('source_key', $key)->first();

        if ($event !== null) {
            $this->cancelEvent($event, $reason);
        }
    }

    protected function cancelEvent(Event $event, string $reason): void
    {
        if ($event->cancelled_at === null) {
            $event->forceFill(['cancelled_at' => now(), 'cancel_reason' => mb_substr($reason, 0, 255)])->save();
        }

        $this->reminders->cancelPending($event, $reason);
    }

    protected function cancelContractChildren(Contract $contract, string $reason): void
    {
        Event::query()->withoutGlobalScopes()
            ->where('contract_id', $contract->id)
            ->whereIn('reference_type', ['contract_delivery', 'contract_follow_up'])
            ->get()
            ->each(fn (Event $event) => $this->cancelEvent($event, $reason));
    }

    /**
     * Inicio de la licitación: fecha y hora de inicio si el módulo de licitaciones ya la guarda;
     * si no, la fecha de publicación (día completo).
     *
     * @return array{0: Carbon|null, 1: bool}
     */
    protected function tenderStart(Tender $tender): array
    {
        foreach (['starts_at', 'start_at', 'started_at'] as $column) {
            $value = $tender->getAttribute($column);
            if ($value !== null && $value !== '') {
                return [$value instanceof Carbon ? $value->copy() : Carbon::parse($value), false];
            }
        }

        return [$tender->published_at?->copy()->startOfDay(), true];
    }
}
