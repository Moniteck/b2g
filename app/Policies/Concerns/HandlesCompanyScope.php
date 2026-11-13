<?php

namespace App\Policies\Concerns;

use App\Models\User;

/**
 * Reglas comunes de autorizacion para recursos-multiples.
 *
 * El permiso efectivo es la interseccion de:
 *   - acceso del usuario a la empresa del recurso,
 *   - rol dentro de esa empresa,
 *   - propiedad del registro.
 */
trait HandlesCompanyScope
{
    protected function hasCompanyAccess(User $user, ?int $companyId): bool
    {
        if ($companyId === null) {
            // Los registros sin empresa solo son visibles para administradores raiz.
            return $user->isRoot();
        }

        return $user->canAccessCompany($companyId);
    }

    protected function isCompanyAdmin(User $user, ?int $companyId): bool
    {
        if ($user->isRoot()) {
            return true;
        }

        return $user->isCompanyAdmin($companyId);
    }

    /**
     * Un registro pertenece al usuario si declara un responsable.
     */
    protected function owns(User $user, object $model): bool
    {
        foreach (['owner_id', 'created_by', 'uploaded_by', 'user_id', 'manager_id'] as $attribute) {
            if ($model->getAttribute($attribute) !== null) {
                return (int) $model->getAttribute($attribute) === (int) $user->id;
            }
        }

        return false;
    }

    /**
     * Un operador puede operar sobre lo que posee; un administrador de empresa
     * sobre todo lo de su empresa.
     */
    protected function canOperate(User $user, object $model): bool
    {
        if (! $this->hasCompanyAccess($user, $model->company_id ?? null)) {
            return false;
        }

        if ($this->isCompanyAdmin($user, $model->company_id ?? null)) {
            return true;
        }

        return $this->owns($user, $model);
    }
}
