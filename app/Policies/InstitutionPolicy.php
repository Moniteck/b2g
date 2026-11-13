<?php

namespace App\Policies;

use App\Models\Institution;
use App\Models\User;

/**
 * El directorio institucional es un catalogo compartido del grupo: todos lo
 * consultan, pero solo el administrador raiz lo modifica.
 */
class InstitutionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Institution $institution): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isRoot();
    }

    public function update(User $user, Institution $institution): bool
    {
        return $user->isRoot();
    }

    public function delete(User $user, Institution $institution): bool
    {
        return $user->isRoot();
    }
}
