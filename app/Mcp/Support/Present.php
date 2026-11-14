<?php

namespace App\Mcp\Support;

use App\Models\Document;
use App\Models\DocumentExtraction;
use App\Models\Event;
use App\Models\InventoryItem;
use App\Models\Tender;
use App\Services\Pricing\InventoryPricing;

/**
 * Forma compacta y estable de los registros en la salida MCP (claves en inglés, como el modelo).
 * Fechas «AAAA-MM-DD» y fecha-hora «AAAA-MM-DD HH:MM:SS» en hora de Honduras. El texto de
 * terceros va siempre bajo `untrusted`.
 */
final class Present
{
    /**
     * @return array<string, mixed>
     */
    public static function tenderRow(Tender $tender): array
    {
        return [
            'id' => $tender->id,
            'company_id' => $tender->company_id,
            'reference' => $tender->reference,
            'status' => $tender->status,
            'purchase_type' => $tender->purchase_type,
            'institution' => $tender->institution?->name,
            'estimated_amount' => $tender->estimated_amount !== null ? (float) $tender->estimated_amount : null,
            'clarification_deadline' => self::dt($tender->clarification_deadline),
            'presentation_deadline' => self::dt($tender->presentation_deadline),
            'opening_at' => self::dt($tender->opening_at),
            ...Untrusted::of(['title' => $tender->title], 300),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function tender(Tender $tender): array
    {
        return [
            'id' => $tender->id,
            'company_id' => $tender->company_id,
            'reference' => $tender->reference,
            'status' => $tender->status,
            'purchase_type' => $tender->purchase_type,
            'institution_id' => $tender->institution_id,
            'institution' => $tender->institution?->name,
            'estimated_amount' => $tender->estimated_amount !== null ? (float) $tender->estimated_amount : null,
            'bid_document_fee' => $tender->bid_document_fee !== null ? (float) $tender->bid_document_fee : null,
            'offer_validity_days' => $tender->offer_validity_days,
            'honducompras_stage' => $tender->honducompras_stage,
            'published_at' => $tender->published_at?->format('Y-m-d'),
            'started_at' => self::dt($tender->started_at),
            'clarification_deadline' => self::dt($tender->clarification_deadline),
            'presentation_deadline' => self::dt($tender->presentation_deadline),
            'opening_at' => self::dt($tender->opening_at),
            'closing_date' => $tender->closing_date?->format('Y-m-d'),
            'offer_submitted' => (bool) $tender->offer_submitted,
            'result' => $tender->result,
            'source_url' => $tender->source_url,
            'owner' => $tender->owner?->name,
            'updated_at' => self::dt($tender->updated_at),
            ...Untrusted::of([
                'title' => $tender->title,
                'process_name' => $tender->process_name,
                'purchasing_unit' => $tender->purchasing_unit,
                'funding_source_type' => $tender->funding_source_type,
                'funding_source' => $tender->funding_source,
                'acquisition_type' => $tender->acquisition_type,
                'offer_reception_place' => $tender->offer_reception_place,
                'contact_name' => $tender->contact_name,
                'contact_phone' => $tender->contact_phone,
                'contact_email' => $tender->contact_email,
                'requirements' => $tender->requirements,
                'delivery_terms' => $tender->delivery_terms,
                'payment_terms' => $tender->payment_terms,
                'documents' => $tender->documents,
                'observations' => $tender->observations,
                'notes' => $tender->notes,
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function analysis(DocumentExtraction $extraction): array
    {
        $usage = $extraction->usage ?? [];

        return [
            'id' => $extraction->id,
            'tender_id' => $extraction->tender_id,
            'status' => $extraction->status,
            'status_label' => $extraction->statusLabel(),
            'pending' => $extraction->isPending(),
            'attempts' => $extraction->attempts,
            'confidence' => $extraction->confidence !== null ? (float) $extraction->confidence : null,
            'pages' => $usage['pages'] ?? null,
            'error' => $extraction->error,
            'created_at' => self::dt($extraction->created_at),
            'finished_at' => self::dt($extraction->finished_at),
            'reviewed_at' => self::dt($extraction->reviewed_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function event(Event $event): array
    {
        return [
            'id' => $event->id,
            'company_id' => $event->company_id,
            'type' => $event->type,
            'type_label' => $event->typeLabel(),
            'starts_at' => self::dt($event->starts_at),
            'ends_at' => self::dt($event->ends_at),
            'all_day' => (bool) $event->all_day,
            'manual' => $event->isManual(),
            'priority' => $event->priority,
            'tender_id' => $event->tender_id,
            'contract_id' => $event->contract_id,
            'source' => $event->isManual() ? null : ['type' => $event->reference_type, 'id' => $event->reference_id],
            'attended' => $event->attended_at !== null,
            'attended_at' => self::dt($event->attended_at),
            'cancelled' => $event->cancelled_at !== null,
            ...Untrusted::of(['title' => $event->title, 'description' => $event->description], 1000),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function document(Document $document): array
    {
        return [
            'id' => $document->id,
            'company_id' => $document->company_id,
            'kind' => $document->kind,
            'category' => $document->category,
            'category_label' => $document->categoryLabel(),
            'status' => $document->computedStatus(),
            'issued_at' => $document->issued_at?->format('Y-m-d'),
            'expires_at' => $document->expires_at?->format('Y-m-d'),
            'days_to_expire' => $document->daysToExpire(),
            'version' => (int) $document->version,
            'mime' => $document->mime_type,
            'size' => $document->size,
            'signed' => $document->signed_at !== null,
            'signed_at' => self::dt($document->signed_at),
            'updated_at' => self::dt($document->updated_at),
            ...Untrusted::of([
                'name' => $document->name,
                'file_name' => $document->original_filename,
                'reference_number' => $document->reference_number,
                'owner_name' => $document->owner_name,
                'description' => $document->description,
            ], 500),
        ];
    }

    /**
     * Precio, cantidad e ISV con el calculador único (`InventoryItem::pricing`).
     *
     * @return array<string, mixed>
     */
    public static function inventoryItem(InventoryItem $item, float $quantity, bool $withCost): array
    {
        return array_filter([
            'id' => $item->id,
            'sku' => $item->sku,
            'category' => $item->category,
            'brand' => $item->brand,
            'unit' => $item->unit,
            'stock' => $item->availableStock(),
            'is_active' => (bool) $item->is_active,
            'suggested_price' => app(InventoryPricing::class)->sourcePrice($item),
            'pricing' => $item->pricing($quantity)->toArray(),
            'cost' => $withCost ? (float) $item->cost : null,
            'synced_at' => self::dt($item->synced_at),
            ...Untrusted::of(['description' => $item->description], 500),
        ], fn ($value) => $value !== null);
    }

    public static function dt(mixed $value): ?string
    {
        return $value?->format('Y-m-d H:i:s');
    }
}
