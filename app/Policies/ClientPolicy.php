<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Permissions;

/**
 * Los clientes nacen de una adjudicación, por lo que el alta manual no está
 * permitida: solo el servicio de adjudicación crea clientes.
 */
class ClientPolicy extends OperationalPolicy
{
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Importaciones masivas: solo administradores de la empresa activa.
     */
    public function import(User $user): bool
    {
        return $this->allowsInActiveCompany($user, Permissions::DELETE);
    }
}
