<?php

namespace App\Policies;

use App\Models\Quote;
use App\Models\User;
use App\Policies\Concerns\HandlesCompanyScope;

class QuotePolicy
{
    use HandlesCompanyScope;

    public function viewAny(User $user): bool
    {
        return $user->isRoot() || $user->companies()->exists();
    }

    public function view(User $user, Quote $quote): bool
    {
        return $this->hasCompanyAccess($user, $quote->company_id);
    }

    public function create(User $user): bool
    {
        return $user->isRoot() || $user->companies()->exists();
    }

    /**
     * Una cotizacion presentada ya no se edita: se sustituye por una revision.
     */
    public function update(User $user, Quote $quote): bool
    {
        if (! $this->canOperate($user, $quote)) {
            return false;
        }

        return $quote->isEditable();
    }

    public function delete(User $user, Quote $quote): bool
    {
        return $this->isCompanyAdmin($user, $quote->company_id)
            && in_array($quote->outcome, ['Pendiente'], true);
    }

    /**
     * Registrar el resultado comercial de la oferta.
     */
    public function adjudicate(User $user, Quote $quote): bool
    {
        return $this->canOperate($user, $quote);
    }
}
