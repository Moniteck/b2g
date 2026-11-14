<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\Present;
use App\Mcp\Tools\Concerns\DescribesQuoteFields;
use App\Models\Quote;
use App\Models\User;
use App\Services\Business\AdjudicationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_quote')]
#[Title('Detalle de cotización')]
#[Description('Detalle de una cotización: estado documental y estados siguientes posibles, resultado comercial, importes, líneas con precio e ISV, participantes con el resumen de posición, y si se puede revertir su resultado. Los PDF adjuntos se listan con list_attachments (entity_type=quote).')]
#[IsReadOnly]
#[IsIdempotent]
class GetQuoteTool extends BaseTool
{
    use DescribesQuoteFields;

    protected ?array $record = ['quote_id', Quote::class];

    public function schema(JsonSchema $schema): array
    {
        return [
            'quote_id' => $schema->integer()->description('Id de la cotización.')->required(),
            ...$this->companySchema($schema),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $quote = $this->find(Quote::query()->with(['items', 'institution:id,name', 'contact:id,name']), $request->get('quote_id'), 'la cotización');
        $this->authorize('view', $quote);

        return $this->ok([
            'quote' => $this->quoteSummary($quote) + [
                'contact' => $quote->contact?->name,
                'validity_days' => $quote->validity_days,
                'purchase_type' => $quote->purchase_type,
                'winner_name' => $quote->winner_name,
                ...$this->untrusted(['conditions' => $quote->conditions, 'notes' => $quote->notes]),
            ],
            'items' => $quote->items->map(fn ($item) => [
                'id' => $item->id,
                'quantity' => (float) $item->quantity,
                'unit' => $item->unit,
                'unit_price' => (float) $item->unit_price,
                'tax_rate' => $item->tax_rate !== null ? (float) $item->tax_rate : null,
                'total' => (float) $item->total,
                'inventory_item_id' => $item->inventory_item_id ?? null,
                ...$this->untrusted(['description' => $item->description], 500),
            ])->all(),
            'participants' => Present::participants($quote, (string) $quote->outcome),
            'revert_blocker' => $quote->outcome !== 'Pendiente' ? app(AdjudicationService::class)->revertBlocker($quote) : null,
            'can' => [
                'update' => $user->can('update', $quote),
                'change_status' => $user->can('changeStatus', $quote),
                'adjudicate' => $user->can('adjudicate', $quote),
                'revert' => $user->can('revertOutcome', $quote),
                'delete' => $user->can('delete', $quote),
            ],
        ]);
    }
}
