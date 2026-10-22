<?php

namespace App\Providers;

use App\Models\User;
use App\Services\CurrentCompany;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentCompany::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Central-only tables (plans, tenants, platform staff, audit). Company databases migrate
        // database/migrations only, so these never end up inside a company's database.
        $this->loadMigrationsFrom(database_path('migrations/platform'));

        Paginator::defaultView('pagination.app');

        Gate::define('view-reports', fn (User $user) => $user->role->canViewReports());
        Gate::define('manage-settings', fn (User $user) => $user->role->canManageSettings());
        Gate::define('manage-users', fn (User $user) => $user->role->canManageUsers());
        Gate::define('view-employees', fn (User $user) => $user->role->canManageSettings());
    }
}
