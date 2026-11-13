<?php

namespace App\Policies;

use App\Models\User;

class KpiGoalPolicy
{
    public function viewAny(User $user): bool { return true; }
    public function view(User $user, object $goal): bool { return true; }
    public function create(User $user): bool { return $user->isAdmin(); }
    public function update(User $user, object $goal): bool { return $user->isAdmin(); }
    public function delete(User $user, object $goal): bool { return $user->isAdmin(); }
}
