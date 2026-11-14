<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Permissions;

/**
 * Las metas las consulta toda la empresa y las fija su administrador.
 */
class KpiGoalPolicy extends OperationalPolicy
{
    public function create(User $user): bool
    {
        return $this->allowsInActiveCompany($user, Permissions::MANAGE_KPIS);
    }

    public function update(User $user, object $model): bool
    {
        return $this->allowsOn($user, $model, Permissions::MANAGE_KPIS);
    }

    public function delete(User $user, object $model): bool
    {
        return $this->allowsOn($user, $model, Permissions::MANAGE_KPIS);
    }
}
