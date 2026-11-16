<?php

namespace App\Mcp\Tools\Concerns;

use App\Mcp\Support\Untrusted;
use App\Models\Contract;
use App\Models\ContractDelivery;
use App\Models\ContractFollowUp;
use App\Services\Contracts\ContractStatus;

/**
 * Forma MCP de los contratos de post-adjudicación (claves del modelo, texto libre en `untrusted`).
 */
trait PresentsContracts
{
    /**
     * @return array<string, mixed>
     */
    protected function contractRow(Contract $contract): array
    {
        return [
            'id' => $contract->id,
            'company_id' => $contract->company_id,
            'number' => $contract->number,
            'status' => $contract->status,
            'next_statuses' => ContractStatus::next($contract->status),
            'institution' => $contract->institution?->name,
            'client_id' => $contract->client_id,
            'quote_id' => $contract->quote_id,
            'opportunity_id' => $contract->opportunity_id,
            'tender_id' => $contract->tender_id,
            'awarded_amount' => $contract->awarded_amount !== null ? (float) $contract->awarded_amount : null,
            'amount' => (float) $contract->amount,
            'signed_at' => $contract->signed_at?->format('Y-m-d'),
            'duration_days' => $contract->duration_days,
            'duration_business_days' => (bool) $contract->duration_business_days,
            'start_trigger' => $contract->start_trigger,
            'start_date' => $contract->start_date?->format('Y-m-d'),
            'end_date' => $contract->end_date?->format('Y-m-d'),
            'is_end_date_manual' => (bool) $contract->is_end_date_manual,
            'product_delivered' => (bool) $contract->product_delivered,
            'manager' => $contract->manager?->name,
            ...Untrusted::of(['name' => $contract->name], 300),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function deliveryRow(ContractDelivery $delivery): array
    {
        return [
            'id' => $delivery->id,
            'quantity' => (float) $delivery->quantity,
            'unit' => $delivery->unit,
            'due_date' => $delivery->due_date?->format('Y-m-d'),
            'delivered_at' => $delivery->delivered_at?->format('Y-m-d'),
            'status' => $delivery->isDelivered() ? ContractDelivery::DELIVERED : $delivery->status,
            'evidence_document_id' => $delivery->evidence_document_id,
            ...Untrusted::of(['description' => $delivery->description, 'notes' => $delivery->notes], 500),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function followUpRow(ContractFollowUp $followUp): array
    {
        return [
            'id' => $followUp->id,
            'type' => $followUp->type,
            'occurred_at' => $followUp->occurred_at?->format('Y-m-d H:i:s'),
            'next_action_at' => $followUp->next_action_at?->format('Y-m-d'),
            'user' => $followUp->user?->name,
            ...Untrusted::of(['description' => $followUp->description, 'next_action' => $followUp->next_action], 1000),
        ];
    }
}
