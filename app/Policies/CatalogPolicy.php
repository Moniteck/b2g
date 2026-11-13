<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Permissions;

/**
 * Catálogos compartidos por todo el grupo (instituciones, contactos,
 * competidores): cualquier usuario con empresa los consulta, administradores y
 * operadores los mantienen y solo la raíz los elimina.
 */
class CatalogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::VIEW);
    }

    public function view(User $user, object $model): bool
    {
        return $user->hasPermission(Permissions::VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::CREATE);
    }

    public function update(User $user, object $model): bool
    {
        return $user->hasPermission(Permissions::UPDATE);
    }

    public function delete(User $user, object $model): bool
    {
        return $user->isRoot();
    }
}
