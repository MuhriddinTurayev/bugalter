<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->role->isManager();
    }

    public function update(User $user, Category $category): bool
    {
        return $user->role->isManager();
    }
}
