<?php

namespace App\Policies;

use App\Models\Opportunity;
use App\Models\User;
use App\Policies\Concerns\HandlesCompanyScope;

class OpportunityPolicy
{
    use HandlesCompanyScope;

    public function viewAny(User $user): bool
    {
        return $user->isRoot() || $user->companies()->exists();
    }

    public function view(User $user, Opportunity $opportunity): bool
    {
        return $this->hasCompanyAccess($user, $opportunity->company_id);
    }

    public function create(User $user): bool
    {
        return $user->isRoot() || $user->companies()->exists();
    }

    public function update(User $user, Opportunity $opportunity): bool
    {
        return $this->canOperate($user, $opportunity);
    }

    public function delete(User $user, Opportunity $opportunity): bool
    {
        return $this->isCompanyAdmin($user, $opportunity->company_id);
    }

    /**
     * Mover una tarjeta a "Adjudicado" o "No adjudicado" exige registrar el
     * resultado, por lo que se controla de forma explicita.
     */
    public function adjudicate(User $user, Opportunity $opportunity): bool
    {
        return $this->canOperate($user, $opportunity);
    }
}
