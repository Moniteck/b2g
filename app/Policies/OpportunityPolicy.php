<?php

namespace App\Policies;

use App\Models\Opportunity;
use App\Models\User;
use App\Support\Permissions;

/**
 * Pipeline (kanban):
 *
 *   ver                       admin, operator, viewer
 *   crear, editar, mover      admin, operator (también participantes)
 *   registrar resultado       admin, operator (Permissions::ADJUDICATE)
 *   revertir resultado        admin
 *   eliminar                  admin, y solo si la oportunidad no tiene resultado
 */
class OpportunityPolicy extends OperationalPolicy
{
    /** Mover la tarjeta entre Prospecto, Oportunidad y Oferta Presentada. */
    public function move(User $user, Opportunity $opportunity): bool
    {
        return $this->update($user, $opportunity);
    }

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

    public function delete(User $user, object $model): bool
    {
        return parent::delete($user, $model) && ! ($model instanceof Opportunity && $model->hasOutcome());
    }
}
