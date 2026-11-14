<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\DescribesQuoteFields;
use App\Models\Quote;
use App\Models\User;
use App\Services\Business\QuoteService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('update_quote')]
#[Title('Actualizar cotización')]
#[Description('Edita una cotización en Borrador o Emitida y sin resultado (una Presentada se sustituye por una revisión). Envía el registro completo como en el formulario web: los campos omitidos toman su valor actual, y si envías `items` reemplazan todas las líneas (si no, se conservan las actuales). Los importes se recalculan.')]
#[IsDestructive(false)]
#[IsIdempotent]
class UpdateQuoteTool extends BaseTool
{
    use DescribesQuoteFields;

    protected ?array $record = ['quote_id', Quote::class];

    public function schema(JsonSchema $schema): array
    {
        return [
            'quote_id' => $schema->integer()->description('Id de la cotización.')->required(),
            ...$this->companySchema($schema),
            ...$this->quoteFieldSchema($schema),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $quote = $this->find(Quote::query()->with('items'), $request->get('quote_id'), 'la cotización');
        $this->authorize('update', $quote);

        $current = [
            ...$quote->only(['institution_id', 'contact_id', 'opportunity_id', 'tender_id', 'validity_days', 'purchase_type', 'conditions', 'notes']),
            'quoted_at' => $quote->quoted_at?->format('Y-m-d'),
            'tax_included' => (bool) $quote->tax_included,
            'items' => $quote->items->map(fn ($item) => array_filter([
                'description' => $item->description,
                'quantity' => (float) $item->quantity,
                'unit' => $item->unit ?: 'Unidad',
                'unit_price' => (float) $item->unit_price,
                'tax_rate' => $item->tax_rate !== null ? (float) $item->tax_rate : null,
                'tax_included' => $item->tax_included ?? null,
                'discount_percent' => $item->discount_percent ?? null,
                'inventory_item_id' => $item->inventory_item_id ?? null,
                'sku' => $item->sku ?? null,
                'pricing_source' => $item->pricing_source ?? null,
            ], fn ($value) => $value !== null))->all(),
        ];

        $data = $this->validateQuote(array_filter($request->all(), fn ($value) => $value !== null) + $current);
        $updated = app(QuoteService::class)->update($quote, $data);

        return $this->ok(['quote' => $this->quoteSummary($updated->fresh('institution'))], "Cotización {$updated->number} actualizada.");
    }
}
