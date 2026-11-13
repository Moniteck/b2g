<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;
use App\Policies\Concerns\HandlesCompanyScope;

class CompanyPolicy
{
    use HandlesCompanyScope;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Company $company): bool
    {
        return $user->isRoot() || $user->canAccessCompany($company->id);
    }

    public function create(User $user): bool
    {
        return $user->isRoot();
    }

    public function update(User $user, Company $company): bool
    {
        return $user->isRoot() || $user->isCompanyAdmin($company->id);
    }

    public function delete(User $user, Company $company): bool
    {
        return $user->isRoot();
    }

    /**
     * Asignar usuarios a la empresa.
     */
    public function manageMembers(User $user, Company $company): bool
    {
        return $user->isRoot() || $user->isCompanyAdmin($company->id);
    }
}
