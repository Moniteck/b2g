<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\HandlesCompanyScope;

/**
 * Politica base para los recursos operativos del CRM.
 *
 * Sustituye a la politica permisiva anterior: el acceso depende de la empresa,
 * del rol dentro de ella y de la propiedad del registro.
 */
class OperationalPolicy
{
    use HandlesCompanyScope;

    public function viewAny(User $user): bool
    {
        return $user->isRoot() || $user->companies()->exists();
    }

    public function view(User $user, object $model): bool
    {
        return $this->hasCompanyAccess($user, $model->company_id ?? null);
    }

    public function create(User $user): bool
    {
        return $user->isRoot() || $user->companies()->exists();
    }

    public function update(User $user, object $model): bool
    {
        return $this->canOperate($user, $model);
    }

    public function delete(User $user, object $model): bool
    {
        return $this->isCompanyAdmin($user, $model->company_id ?? null)
            && $this->hasCompanyAccess($user, $model->company_id ?? null);
    }
}
