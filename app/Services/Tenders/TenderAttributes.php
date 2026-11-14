<?php

namespace App\Services\Tenders;

use Illuminate\Support\Arr;

/**
 * Convierte los datos validados de un formulario (manual, captura o pliego) en atributos
 * de la licitación. Reglas comunes:
 *
 * - Fechas en hora de Honduras, tal como las escribió la persona (sin pasar a UTC).
 * - `closing_date` (cierre) acompaña a la fecha de presentación de ofertas si no viene.
 * - `published_at` toma el día de la fecha de inicio si no viene.
 */
final class TenderAttributes
{
    public const FIELDS = [
        'institution_id', 'reference', 'process_name', 'title', 'purchase_type', 'status',
        'estimated_amount', 'published_at', 'started_at', 'clarification_deadline',
        'presentation_deadline', 'opening_at', 'closing_date', 'source_url', 'contact_name',
        'contact_phone', 'contact_email', 'requirements', 'delivery_terms', 'payment_terms',
        'documents', 'observations', 'offer_submitted', 'result', 'notes', 'purchasing_unit',
        'funding_source_type', 'funding_source', 'honducompras_stage', 'acquisition_type',
        'offer_reception_place', 'bid_document_fee', 'offer_validity_days',
    ];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fromInput(array $data): array
    {
        $attributes = Arr::only($data, self::FIELDS);

        foreach (['started_at', 'clarification_deadline', 'presentation_deadline', 'opening_at'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $attributes[$field] = self::dateTime($attributes[$field]);
            }
        }

        if (empty($attributes['closing_date']) && ! empty($attributes['presentation_deadline'])) {
            $attributes['closing_date'] = substr($attributes['presentation_deadline'], 0, 10);
        }

        if (empty($attributes['published_at']) && ! empty($attributes['started_at'])) {
            $attributes['published_at'] = substr($attributes['started_at'], 0, 10);
        }

        if (array_key_exists('reference', $attributes) && is_string($attributes['reference'])) {
            $attributes['reference'] = strtoupper(trim($attributes['reference']));
        }

        return $attributes;
    }

    /** «2026-09-30T10:15» o «2026-09-30 10:15:00» → «2026-09-30 10:15:00». */
    public static function dateTime(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return SpanishDates::iso((string) $value)?->format('Y-m-d H:i:s');
    }
}
