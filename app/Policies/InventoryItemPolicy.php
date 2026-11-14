<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Permissions;

/**
 * Inventario Blessing y calculadora de oferta.
 *
 *   ver inventario                         admin, operator, viewer
 *   ver el costo, calculadora, selección   admin, operator
 *   sincronizar con Blessing               admin
 *
 * El inventario solo lo escribe la sincronización: nadie lo crea, edita ni borra a mano.
 */
class InventoryItemPolicy extends OperationalPolicy
{
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, object $model): bool
    {
        return false;
    }

    public function delete(User $user, object $model): bool
    {
        return false;
    }

    public function sync(User $user): bool
    {
        return $this->allowsInActiveCompany($user, Permissions::MANAGE_SETTINGS);
    }

    public function viewCost(User $user): bool
    {
        return $this->allowsInActiveCompany($user, Permissions::UPDATE);
    }

    /** Calculadora de oferta y «Llevar a una cotización». */
    public function price(User $user): bool
    {
        return $this->allowsInActiveCompany($user, Permissions::CREATE);
    }
}
