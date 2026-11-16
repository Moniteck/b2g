<?php

namespace App\Mcp\Tools\Concerns;

use App\Http\Requests\Tenders\TenderRequest;
use App\Models\Institution;
use App\Models\Tender;
use App\Rules\BelongsToActiveCompany;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;

/**
 * Campos de una licitación por MCP: los mismos nombres y reglas que el formulario web
 * (`TenderRequest`), para que la herramienta sea solo una capa delgada.
 */
trait DescribesTenderFields
{
    /**
     * @return array<string, mixed>
     */
    protected function tenderFieldSchema(JsonSchema $schema): array
    {
        $dateTime = 'Fecha y hora en hora de Honduras, «AAAA-MM-DD HH:MM».';

        return [
            'reference' => $schema->string()->description('Expediente o número del proceso (único por empresa), p. ej. «LPN-007-2026».'),
            'title' => $schema->string()->description('Objeto de la licitación.'),
            'process_name' => $schema->string()->description('Nombre del proceso.'),
            'institution_id' => $schema->integer()->description('Institución compradora (ver search_institutions).'),
            'status' => $schema->string()->enum(Tender::STATUSES)->description('Estado de la licitación.'),
            'purchase_type' => $schema->string()->description('Modalidad: '.implode(', ', Tender::MODALITIES).'.'),
            'estimated_amount' => $schema->number()->description('Monto estimado en lempiras.'),
            'purchasing_unit' => $schema->string()->description('Unidad de compra.'),
            'honducompras_stage' => $schema->string()->description('Etapa en HonduCompras.'),
            'funding_source_type' => $schema->string()->description('Tipo de fuente de financiamiento.'),
            'funding_source' => $schema->string()->description('Fuente de financiamiento.'),
            'acquisition_type' => $schema->string()->description('Tipo de adquisición.'),
            'offer_reception_place' => $schema->string()->description('Lugar de recepción de ofertas.'),
            'bid_document_fee' => $schema->number()->description('Valor de los pliegos.'),
            'offer_validity_days' => $schema->integer()->description('Vigencia de la oferta en días.'),
            'started_at' => $schema->string()->description("Fecha de inicio. {$dateTime}"),
            'clarification_deadline' => $schema->string()->description("Cierre de aclaraciones (último día para preguntar). {$dateTime}"),
            'presentation_deadline' => $schema->string()->description("Recepción de ofertas. {$dateTime}"),
            'opening_at' => $schema->string()->description("Apertura de ofertas. {$dateTime}"),
            'source_url' => $schema->string()->description('Enlace al proceso.'),
            'contact_name' => $schema->string()->description('Contacto para consultas.'),
            'contact_phone' => $schema->string()->description('Teléfono del contacto.'),
            'contact_email' => $schema->string()->description('Correo del contacto.'),
            'requirements' => $schema->string()->description('Requisitos.'),
            'delivery_terms' => $schema->string()->description('Plazo de entrega.'),
            'payment_terms' => $schema->string()->description('Forma de pago.'),
            'observations' => $schema->string()->description('Observaciones.'),
            'notes' => $schema->string()->description('Notas internas.'),
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function tenderRules(int $companyId, ?int $ignoreId = null): array
    {
        return [
            'institution_id' => ['nullable', 'integer', new BelongsToActiveCompany(Institution::class)],
            'reference' => ['required', 'string', 'max:100', Rule::unique('tenders', 'reference')->where('company_id', $companyId)->ignore($ignoreId)],
            ...TenderRequest::fieldRules(),
            'status' => ['required', Rule::in(Tender::STATUSES)],
            'estimated_amount' => ['required', 'numeric', 'min:0', 'max:999999999999'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function normalizeTender(array $input): array
    {
        if (isset($input['reference']) && is_string($input['reference'])) {
            $input['reference'] = strtoupper(trim($input['reference']));
        }

        foreach (['started_at', 'clarification_deadline', 'presentation_deadline', 'opening_at'] as $field) {
            if (isset($input[$field]) && is_string($input[$field])) {
                $input[$field] = str_replace('T', ' ', $input[$field]);
            }
        }

        if (array_key_exists('estimated_amount', $input) && ($input['estimated_amount'] === null || $input['estimated_amount'] === '')) {
            $input['estimated_amount'] = 0;
        }

        return $input;
    }
}
