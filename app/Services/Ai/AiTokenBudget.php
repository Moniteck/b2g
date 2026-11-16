<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Presupuesto diario de tokens de IA por empresa (hallazgo A1).
 *
 * Cada llamada a OpenAI suma los tokens que reporta la respuesta (`usage.total_tokens`) al
 * contador del día de la empresa; antes de llamar se comprueba que quede presupuesto. El tope
 * sale de `config('services.openai.daily_token_budget')` (OPENAI_DAILY_TOKEN_BUDGET, 2 millones
 * por defecto; 0 lo desactiva). El día es el de Honduras.
 *
 *   $budget->ensureAvailable($companyId, $tokensEstimados);   // RuntimeException si no alcanza
 *   $budget->record($companyId, $usage['total_tokens']);
 */
class AiTokenBudget
{
    public function limit(): int
    {
        return max(0, (int) config('services.openai.daily_token_budget', 2000000));
    }

    public function used(?int $companyId): int
    {
        return (int) Cache::get($this->key($companyId), 0);
    }

    public function remaining(?int $companyId): int
    {
        return $this->limit() === 0 ? PHP_INT_MAX : max(0, $this->limit() - $this->used($companyId));
    }

    /**
     * @throws RuntimeException si la empresa agotó su presupuesto del día o la estimación no cabe
     */
    public function ensureAvailable(?int $companyId, int $estimatedTokens = 0): void
    {
        if ($this->limit() === 0) {
            return;
        }

        $remaining = $this->remaining($companyId);

        if ($remaining <= 0 || $estimatedTokens > $remaining) {
            throw new RuntimeException('La empresa alcanzó el presupuesto diario de uso de la IA ('.number_format($this->limit(), 0, '.', ',').' tokens). Vuelve a intentarlo mañana o pide al administrador que amplíe el límite.');
        }
    }

    public function record(?int $companyId, int $tokens): void
    {
        if ($tokens <= 0) {
            return;
        }

        $key = $this->key($companyId);
        Cache::add($key, 0, now()->endOfDay()->addHour());
        Cache::increment($key, $tokens);
    }

    private function key(?int $companyId): string
    {
        return 'ai-tokens:'.($companyId ?? 0).':'.now()->toDateString();
    }
}
