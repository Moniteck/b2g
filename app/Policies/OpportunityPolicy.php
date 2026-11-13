<?php

namespace App\Policies;

use App\Models\Opportunity;
use App\Models\User;
use App\Support\Permissions;

class OpportunityPolicy extends OperationalPolicy
{
    /**
     * Mover una tarjeta a Adjudicado o No Adjudicado registra el resultado.
     */
    public function adjudicate(User $user, Opportunity $opportunity): bool
    {
        return $this->allowsOn($user, $opportunity, Permissions::ADJUDICATE);
    }

    public function revertOutcome(User $user, Opportunity $opportunity): bool
    {
        return $this->allowsOn($user, $opportunity, Permissions::REVERT_OUTCOME);
    }
}
