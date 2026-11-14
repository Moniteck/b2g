<?php

namespace App\Services\Business;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Opportunity;
use App\Models\Quote;

/**
 * Resultado de ganar, perder o revertir (AdjudicationService).
 *
 * `alreadyApplied` es verdadero cuando la operación ya estaba hecha (doble clic, reintento):
 * en ese caso no se cambió nada y se devuelven los registros existentes.
 */
final class AdjudicationResult
{
    public function __construct(
        public readonly string $outcome,
        public readonly ?Quote $quote = null,
        public readonly ?Opportunity $opportunity = null,
        public readonly ?Client $client = null,
        public readonly ?Contract $contract = null,
        public readonly bool $alreadyApplied = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'already_applied' => $this->alreadyApplied,
            'quote' => $this->quote ? ['id' => $this->quote->id, 'number' => $this->quote->number, 'status' => $this->quote->status, 'outcome' => $this->quote->outcome] : null,
            'opportunity' => $this->opportunity ? ['id' => $this->opportunity->id, 'title' => $this->opportunity->title, 'stage' => $this->opportunity->stage] : null,
            'client' => $this->client ? ['id' => $this->client->id, 'name' => $this->client->name, 'url' => '/clientes/'.$this->client->id] : null,
            'contract' => $this->contract ? ['id' => $this->contract->id, 'number' => $this->contract->number, 'status' => $this->contract->status, 'url' => '/post-adjudicacion?search='.rawurlencode((string) $this->contract->number)] : null,
        ];
    }
}
