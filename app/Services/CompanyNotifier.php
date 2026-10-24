<?php

namespace App\Services;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Support\Collection;

/** In-app notifications to the people of the current company. */
class CompanyNotifier
{
    /** @param  array<string, scalar>  $params */
    public function owners(string $key, array $params = [], ?string $url = null): void
    {
        $this->send(User::query()->where('role', Role::Owner)->where('is_active', true)->get(), $key, $params, $url);
    }

    /** @param  array<string, scalar>  $params */
    public function withPermission(Permission $permission, string $key, array $params = [], ?string $url = null): void
    {
        $users = User::query()->where('is_active', true)->with('grantedPermissions')->get()
            ->filter(fn (User $user) => $user->hasPermission($permission));

        $this->send($users, $key, $params, $url);
    }

    /** @param  array<string, scalar>  $params */
    public function managers(string $key, array $params = [], ?string $url = null): void
    {
        $this->send(User::query()->whereIn('role', [Role::Owner, Role::ChiefAccountant])->where('is_active', true)->get(), $key, $params, $url);
    }

    /** @param  Collection<int, User>  $users */
    private function send(Collection $users, string $key, array $params, ?string $url): void
    {
        foreach ($users->unique('id') as $user) {
            $user->notify(new AppNotification($key, $params, $url));
        }
    }
}
