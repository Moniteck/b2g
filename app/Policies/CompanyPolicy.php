<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;
use App\Support\Permissions;

class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Company $company): bool
    {
        return $user->canAccessCompany($company->id);
    }

    public function create(User $user): bool
    {
        return $user->isRoot();
    }

    public function update(User $user, Company $company): bool
    {
        return $user->hasPermission(Permissions::MANAGE_SETTINGS, $company->id);
    }

    public function delete(User $user, Company $company): bool
    {
        return $user->isRoot();
    }

    /**
     * Asignar usuarios a la empresa y fijar su rol.
     */
    public function manageMembers(User $user, Company $company): bool
    {
        return $user->hasPermission(Permissions::MANAGE_USERS, $company->id);
    }
}
