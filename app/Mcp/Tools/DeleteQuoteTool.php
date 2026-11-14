<?php

namespace App\Mcp\Tools;

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

#[Name('delete_quote')]
#[Title('Eliminar cotización')]
#[Description('Elimina una cotización en Borrador y sin resultado (solo administrador). Una emitida se anula con change_quote_status. ACCIÓN SENSIBLE en dos pasos: la primera llamada devuelve un resumen y un confirmation_code; muéstralo y, solo si la persona confirma, repite la llamada con los mismos argumentos y el código.')]
#[IsDestructive]
class DeleteQuoteTool extends BaseTool
{
    protected ?array $record = ['quote_id', Quote::class];

    public function schema(JsonSchema $schema): array
    {
        return [
            'quote_id' => $schema->integer()->description('Id de la cotización.')->required(),
            ...$this->companySchema($schema),
            ...$this->confirmationSchema($schema),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $quote = $this->find(Quote::query()->with('institution:id,name'), $request->get('quote_id'), 'la cotización');
        $this->authorize('delete', $quote);

        return $this->confirmed($request, 'delete_quote', $quote, [
            'action' => 'Eliminar cotización',
            'quote' => $quote->number,
            'institution' => $quote->institution?->name,
            'total' => (float) $quote->total,
            'effect' => 'La cotización en Borrador se elimina.',
        ], function () use ($quote, $user) {
            app(QuoteService::class)->delete($quote, $user);

            return $this->ok(['quote_id' => $quote->id, 'deleted' => true], "Cotización {$quote->number} eliminada.");
        });
    }
}
