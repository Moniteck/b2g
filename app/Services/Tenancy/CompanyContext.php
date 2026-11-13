<?php

namespace App\Services\Tenancy;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Resuelve que empresas puede ver y operar el actor actual.
 *
 * Reglas:
 *  - Un administrador raiz opera sobre todas las empresas del grupo.
 *  - Un operador opera solo sobre las empresas que tiene asignadas.
 *  - Si no hay contexto inicializado (consola, cola, sistema) no se restringe,
 *    porque las operaciones de sistema reciben la empresa de forma explicita.
 */
class CompanyContext
{
    /** @var array<int>|null Null significa "sin restriccion de empresa". */
    protected ?array $allowed = null;

    protected ?int $current = null;

    protected ?int $userId = null;

    /**
     * @param  array<int>  $companyIds
     */
    public function initialize(?User $user, ?array $companyIds = null, ?int $currentCompanyId = null): void
    {
        if ($user === null) {
            $this->allowed = $companyIds ?? null;
            $this->current = $currentCompanyId;
            $this->userId = null;

            return;
        }

        $this->userId = $user->id;
        $this->allowed = $companyIds ?? $this->resolveAllowed($user);
        $this->current = $currentCompanyId !== null && in_array($currentCompanyId, $this->allowed, true)
            ? $currentCompanyId
            : ($this->allowed[0] ?? null);
    }

    /**
     * @return array<int>
     */
    public function resolveAllowed(User $user): array
    {
        if ($user->isRoot()) {
            return Company::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return $user->companies()->pluck('companies.id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @return array<int>
     */
    public function allowedCompanyIds(): array
    {
        return $this->allowed ?? [];
    }

    public function isUnrestricted(): bool
    {
        return $this->allowed === null;
    }

    public function currentCompanyId(): ?int
    {
        return $this->current;
    }

    public function setCurrentCompanyId(?int $companyId): void
    {
        if ($companyId === null) {
            $this->current = null;

            return;
        }

        if ($this->allowed === null || in_array($companyId, $this->allowed, true)) {
            $this->current = $companyId;
        }
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    public function canAccess(?int $companyId): bool
    {
        if ($this->allowed === null) {
            return true;
        }

        return $companyId !== null && in_array($companyId, $this->allowed, true);
    }

    /**
     * Restringe la operacion a una empresa concreta durante una tarea en segundo plano.
     */
    public function runFor(?int $companyId, callable $callback): mixed
    {
        $previousAllowed = $this->allowed;
        $previousCurrent = $this->current;

        $this->allowed = $companyId === null ? null : [$companyId];
        $this->current = $companyId;

        try {
            return $callback();
        } finally {
            $this->allowed = $previousAllowed;
            $this->current = $previousCurrent;
        }
    }

    public function forget(): void
    {
        $this->allowed = null;
        $this->current = null;
        $this->userId = null;
    }
}
