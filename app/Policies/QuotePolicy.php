<?php

namespace App\Policies;

use App\Models\Quote;
use App\Models\User;
use App\Support\Permissions;

class QuotePolicy extends OperationalPolicy
{
    /**
     * Una cotización presentada ya no se edita: se sustituye por una revisión.
     */
    public function update(User $user, object $model): bool
    {
        return parent::update($user, $model) && $model->isEditable();
    }

    public function delete(User $user, object $model): bool
    {
        return parent::delete($user, $model) && $model->outcome === 'Pendiente';
    }

    /**
     * Registrar el resultado comercial de la oferta.
     */
    public function adjudicate(User $user, Quote $quote): bool
    {
        return $this->allowsOn($user, $quote, Permissions::ADJUDICATE);
    }

    public function revertOutcome(User $user, Quote $quote): bool
    {
        return $this->allowsOn($user, $quote, Permissions::REVERT_OUTCOME);
    }
}
