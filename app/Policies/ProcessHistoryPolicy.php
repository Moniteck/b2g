<?php
namespace App\Policies;
use App\Models\ProcessHistory; use App\Models\User;
class ProcessHistoryPolicy { public function viewAny(User $user): bool { return true; } public function view(User $user, ProcessHistory $history): bool { return true; } public function create(User $user): bool { return true; } public function delete(User $user, ProcessHistory $history): bool { return true; } }
