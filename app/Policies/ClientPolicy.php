<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;
use App\Policies\Concerns\HandlesCompanyScope;

/**
 * Los clientes nacen de una adjudicacion, por lo que el alta manual no esta
 * permitida: solo el servicio de adjudicacion crea clientes.
 */
class ClientPolicy
{
    use HandlesCompanyScope;

    public function viewAny(User $user): bool
    {
        return $user->isRoot() || $user->companies()->exists();
    }

    public function view(User $user, Client $client): bool
    {
        return $this->hasCompanyAccess($user, $client->company_id);
    }

    /**
     * El alta se produce dentro de la adjudicacion, no desde el formulario.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Client $client): bool
    {
        return $this->canOperate($user, $client);
    }

    public function delete(User $user, Client $client): bool
    {
        return $this->isCompanyAdmin($user, $client->company_id);
    }

    /**
     * Importaciones masivas: solo administradores de empresa.
     */
    public function import(User $user): bool
    {
        return $user->isRoot() || $user->companies()->wherePivot('role', 'admin')->exists();
    }
}
