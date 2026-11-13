<?php

namespace App\Policies;

use App\Models\Contract;
use App\Models\User;
use App\Support\Permissions;

class ContractPolicy extends OperationalPolicy
{
    /**
     * Registrar seguimiento y entregas del contrato.
     */
    public function followUp(User $user, Contract $contract): bool
    {
        return $this->allowsOn($user, $contract, Permissions::UPDATE);
    }

    /**
     * Cerrar el contrato es una decisión administrativa de la empresa.
     */
    public function close(User $user, Contract $contract): bool
    {
        return $this->isCompanyAdmin($user, $contract->company_id);
    }
}
