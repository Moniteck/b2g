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

#[Name('create_quote')]
#[Title('Crear cotización')]
#[Description('Crea una cotización en Borrador con los mismos campos y reglas que el formulario web. Los importes (subtotal, ISV y total) los calcula el servidor con el calculador único y la regla de ISV de la empresa; con inventory_item_id el precio sale del inventario de Blessing. Numeración única por empresa.')]
#[IsDestructive(false)]
class CreateQuoteTool extends BaseTool
{
    use DescribesQuoteFields;

    public function schema(JsonSchema $schema): array
    {
        $fields = $this->quoteFieldSchema($schema);
        $fields['institution_id'] = $fields['institution_id']->required();
        $fields['items'] = $fields['items']->required();

        return [...$this->companySchema($schema), ...$fields];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $this->authorize('create', Quote::class);

        $data = $this->validateQuote($request->all() + ['validity_days' => 30]);
        $quote = app(QuoteService::class)->create($companyId, $user->id, $data);

        return $this->ok(['quote' => $this->quoteSummary($quote)], "Cotización {$quote->number} creada en Borrador.");
    }
}
