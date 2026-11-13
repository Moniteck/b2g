<?php

namespace App\Policies\Concerns;

use App\Models\User;
use App\Support\Permissions;

/**
 * Reglas comunes de autorización por empresa y rol.
 *
 * El permiso efectivo sale de la matriz `App\Support\Permissions` evaluada con
 * el rol del usuario en la empresa DEL REGISTRO (o en la empresa activa cuando
 * todavía no hay registro: `viewAny`, `create`).
 */
trait HandlesCompanyScope
{
    protected function hasCompanyAccess(User $user, ?int $companyId): bool
    {
        if ($companyId === null) {
            // Los registros sin empresa solo son visibles para la raíz.
            return $user->isRoot();
        }

        return $user->canAccessCompany($companyId);
    }

    protected function isCompanyAdmin(User $user, ?int $companyId): bool
    {
        return $user->isRoot() || $user->isCompanyAdmin($companyId);
    }

    /**
     * Permiso en la empresa activa (acciones sin registro).
     */
    protected function allowsInActiveCompany(User $user, string $permission): bool
    {
        return $user->hasPermission($permission);
    }

    /**
     * Permiso sobre un registro concreto, según el rol en SU empresa.
     */
    protected function allowsOn(User $user, object $model, string $permission): bool
    {
        $companyId = $model->company_id ?? null;

        if ($companyId === null) {
            return $user->isRoot();
        }

        return $user->hasPermission($permission, (int) $companyId);
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
     * Puede modificar el registro: administradores y operadores de su empresa.
     */
    protected function canOperate(User $user, object $model): bool
    {
        return $this->allowsOn($user, $model, Permissions::UPDATE);
    }
}
