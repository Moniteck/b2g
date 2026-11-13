<?php

namespace App\Policies;

use App\Models\Contract;
use App\Models\User;
use App\Policies\Concerns\HandlesCompanyScope;

class ContractPolicy
{
    use HandlesCompanyScope;

    public function viewAny(User $user): bool
    {
        return $user->isRoot() || $user->companies()->exists();
    }

    public function view(User $user, Contract $contract): bool
    {
        return $this->hasCompanyAccess($user, $contract->company_id);
    }

    public function create(User $user): bool
    {
        return $user->isRoot() || $user->companies()->exists();
    }

    public function update(User $user, Contract $contract): bool
    {
        return $this->canOperate($user, $contract);
    }

    public function delete(User $user, Contract $contract): bool
    {
        return $this->isCompanyAdmin($user, $contract->company_id);
    }

    /**
     * Registrar seguimiento y entregas del contrato.
     */
    public function followUp(User $user, Contract $contract): bool
    {
        return $this->canOperate($user, $contract);
    }

    /**
     * Cerrar el contrato es una decision administrativa de la empresa.
     */
    public function close(User $user, Contract $contract): bool
    {
        return $this->isCompanyAdmin($user, $contract->company_id);
    }
}
