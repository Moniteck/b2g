<?php

namespace App\Mcp\Tools\Concerns;

use App\Http\Requests\Quotes\QuoteRequest;
use App\Models\Quote;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;

/**
 * Campos de una cotización por MCP: los mismos nombres y reglas que el formulario web
 * (`QuoteRequest`), validados contra la empresa de la cotización (la del contexto).
 */
trait DescribesQuoteFields
{
    /**
     * @return array<string, mixed>
     */
    protected function quoteFieldSchema(JsonSchema $schema): array
    {
        return [
            'institution_id' => $schema->integer()->description('Institución destinataria (ver search_institutions).'),
            'contact_id' => $schema->integer()->description('Contacto de esa institución.'),
            'opportunity_id' => $schema->integer()->description('Oportunidad del tablero.'),
            'tender_id' => $schema->integer()->description('Licitación.'),
            'quoted_at' => $schema->string()->description('Fecha «AAAA-MM-DD» (por defecto hoy).'),
            'validity_days' => $schema->integer()->description('Vigencia de la oferta en días.'),
            'purchase_type' => $schema->string()->description('Modalidad o tipo de compra.'),
            'tax_included' => $schema->boolean()->description('Si los precios escritos ya incluyen ISV (por defecto, la regla de la empresa).'),
            'conditions' => $schema->string()->description('Condiciones de la oferta.'),
            'notes' => $schema->string()->description('Notas.'),
            'items' => $schema->array()->description('Líneas: [{description, quantity, unit, unit_price?, tax_rate?, tax_included?, discount_percent?, unit_cost?, margin?, inventory_item_id?, sku?}]. Con inventory_item_id y sin unit_price, el precio sale del inventario con el calculador único.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function validateQuote(array $input): array
    {
        $input = Arr::only($input, ['institution_id', 'contact_id', 'opportunity_id', 'tender_id', 'quoted_at', 'validity_days', 'purchase_type', 'tax_included', 'conditions', 'notes', 'items']);
        $form = QuoteRequest::create('/', 'POST', $input);

        return $this->validate($input, Arr::except($form->rules(), ['company_id']), $form->messages(), $form->attributes());
    }

    /**
     * @return array<string, mixed>
     */
    protected function quoteSummary(Quote $quote): array
    {
        return [
            'id' => $quote->id,
            'company_id' => $quote->company_id,
            'number' => $quote->number,
            'status' => $quote->status,
            'outcome' => $quote->outcome,
            'next_statuses' => $quote->nextStatuses(),
            'institution' => $quote->institution?->name,
            'quoted_at' => $quote->quoted_at?->format('Y-m-d'),
            'valid_until' => $quote->validUntil()?->format('Y-m-d'),
            'tax_included' => (bool) $quote->tax_included,
            'subtotal' => (float) $quote->subtotal,
            'tax' => (float) $quote->tax,
            'discount' => (float) $quote->discount,
            'total' => (float) $quote->total,
            'tender_id' => $quote->tender_id,
            'opportunity_id' => $quote->opportunity_id,
            'client_id' => $quote->client_id,
            'awarded_amount' => $quote->awarded_amount !== null ? (float) $quote->awarded_amount : null,
            'awarded_at' => $quote->awarded_at?->format('Y-m-d'),
        ];
    }
}
