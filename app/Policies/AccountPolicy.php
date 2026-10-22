<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;

class AccountPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->role->isManager();
    }

    public function update(User $user, Account $account): bool
    {
        return $user->role->isManager();
    }
}
