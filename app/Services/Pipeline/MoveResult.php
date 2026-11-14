<?php

namespace App\Services\Pipeline;

use App\Models\Opportunity;
use App\Models\Quote;

/**
 * Resultado de mover una oportunidad (OpportunityStageService::move).
 * `alreadyApplied` es verdadero si ya estaba en esa etapa (doble clic): no se cambió nada.
 */
final class MoveResult
{
    public function __construct(
        public readonly Opportunity $opportunity,
        public readonly string $from,
        public readonly ?Quote $presentedQuote = null,
        public readonly bool $alreadyApplied = false,
    ) {}
}
