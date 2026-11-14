<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\DescribesQuoteFields;
use App\Models\Quote;
use App\Models\User;
use App\Services\Business\QuoteService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('change_quote_status')]
#[Title('Cambiar estado de cotización')]
#[Description('Cambia el estado documental de una cotización según sus transiciones (Borrador → Emitida → Presentada; Sustituida; Anulada). Presentada registra nuestra oferta entre los participantes y mueve la oportunidad a Oferta Presentada. No registra Ganada ni Perdida: eso es register_outcome.')]
#[IsDestructive(false)]
#[IsIdempotent]
class ChangeQuoteStatusTool extends BaseTool
{
    use DescribesQuoteFields;

    protected ?array $record = ['quote_id', Quote::class];

    public function schema(JsonSchema $schema): array
    {
        return [
            'quote_id' => $schema->integer()->description('Id de la cotización.')->required(),
            ...$this->companySchema($schema),
            'status' => $schema->string()->enum(Quote::STATUSES)->description('Nuevo estado.')->required(),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $quote = $this->find(Quote::class, $request->get('quote_id'), 'la cotización');
        $this->authorize('changeStatus', $quote);

        $data = $this->validate(['status' => $request->get('status')], ['status' => ['required', Rule::in(Quote::STATUSES)]]);
        $updated = app(QuoteService::class)->changeStatus($quote, $data['status'], $user);

        return $this->ok(['quote' => $this->quoteSummary($updated->load('institution'))], "Cotización {$updated->number} en estado {$updated->status}.");
    }
}
