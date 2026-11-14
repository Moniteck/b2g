<?php

namespace App\Mcp\Tools\Concerns;

use App\Mcp\Support\ToolError;
use App\Models\Opportunity;
use App\Models\Quote;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;

/**
 * Sujeto del resultado comercial: una cotización (`quote_id`) o una oportunidad del tablero
 * (`opportunity_id`, también sin cotización). Es el mismo sujeto que acepta `AdjudicationService`.
 */
trait TargetsAdjudicable
{
    /**
     * @return array<string, mixed>
     */
    protected function subjectSchema(JsonSchema $schema): array
    {
        return [
            'quote_id' => $schema->integer()->description('Cotización (indique esta u opportunity_id).'),
            'opportunity_id' => $schema->integer()->description('Oportunidad del tablero (indique esta o quote_id).'),
        ];
    }

    protected function recordFor(Request $request): ?array
    {
        return match (true) {
            $request->get('quote_id') !== null => ['quote_id', Quote::class],
            $request->get('opportunity_id') !== null => ['opportunity_id', Opportunity::class],
            default => null,
        };
    }

    protected function subject(Request $request): Quote|Opportunity
    {
        if ($request->get('quote_id') !== null && $request->get('opportunity_id') !== null) {
            throw new ToolError('Indica quote_id u opportunity_id, no ambos.');
        }

        return match (true) {
            $request->get('quote_id') !== null => $this->find(Quote::class, $request->get('quote_id'), 'la cotización'),
            $request->get('opportunity_id') !== null => $this->find(Opportunity::class, $request->get('opportunity_id'), 'la oportunidad'),
            default => throw new ToolError('Indica quote_id u opportunity_id.'),
        };
    }

    protected function subjectLabel(Quote|Opportunity $subject): string
    {
        return $subject instanceof Quote ? "cotización {$subject->number}" : "oportunidad #{$subject->id}";
    }

    protected function outcomeOf(Quote|Opportunity $subject): string
    {
        return $subject instanceof Quote
            ? (string) $subject->outcome
            : match ($subject->stage) {
                'adjudicado' => 'Ganada',
                'no_adjudicado' => 'Perdida',
                default => 'Pendiente',
            };
    }
}
