<?php

namespace App\Policies;

use App\Models\Tender;
use App\Models\User;
use App\Policies\Concerns\HandlesCompanyScope;

class TenderPolicy
{
    use HandlesCompanyScope;

    public function viewAny(User $user): bool
    {
        return $user->isRoot() || $user->companies()->exists();
    }

    public function view(User $user, Tender $tender): bool
    {
        return $this->hasCompanyAccess($user, $tender->company_id);
    }

    public function create(User $user): bool
    {
        return $user->isRoot() || $user->companies()->exists();
    }

    public function update(User $user, Tender $tender): bool
    {
        return $this->canOperate($user, $tender);
    }

    public function delete(User $user, Tender $tender): bool
    {
        return $this->isCompanyAdmin($user, $tender->company_id);
    }
}
