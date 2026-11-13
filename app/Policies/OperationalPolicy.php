<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\HandlesCompanyScope;
use App\Support\Permissions;

/**
 * Política base de todo recurso con `company_id`.
 *
 *   ver            admin, operator, viewer
 *   crear/editar   admin, operator
 *   eliminar       admin
 *
 * Las políticas específicas la extienden y solo añaden sus reglas de negocio.
 */
class OperationalPolicy
{
    use HandlesCompanyScope;

    public function viewAny(User $user): bool
    {
        return $this->allowsInActiveCompany($user, Permissions::VIEW);
    }

    public function view(User $user, object $model): bool
    {
        return $this->allowsOn($user, $model, Permissions::VIEW);
    }

    public function create(User $user): bool
    {
        return $this->allowsInActiveCompany($user, Permissions::CREATE);
    }

    public function update(User $user, object $model): bool
    {
        return $this->allowsOn($user, $model, Permissions::UPDATE);
    }

    public function delete(User $user, object $model): bool
    {
        return $this->allowsOn($user, $model, Permissions::DELETE);
    }
}
