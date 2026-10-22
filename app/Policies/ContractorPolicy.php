<?php

namespace App\Policies;

use App\Models\Contractor;
use App\Models\User;

class ContractorPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Contractor $contractor): bool
    {
        return true;
    }

    /** Deactivate or reactivate. */
    public function toggle(User $user, Contractor $contractor): bool
    {
        return $user->role->isManager();
    }

    public function import(User $user): bool
    {
        return $user->role->isManager();
    }

    public function export(User $user): bool
    {
        return true;
    }
}
