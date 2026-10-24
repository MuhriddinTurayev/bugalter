<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\User;
use App\Portal\RobotPortal;
use App\Portal\SandboxPortal;
use App\Portal\TaxPortal;
use App\Services\CurrentCompany;
use App\Services\Platform\IntegrationSettings;
use App\Submission\Gateway\DesktopGateway;
use App\Submission\Gateway\HttpGateway;
use App\Submission\Gateway\PortalGateway;
use App\Submission\Gateway\SandboxGateway;
use App\Submission\Gateway\TaxAuthorityGateway;
use App\Tax\TaxRuleBook;
use Carbon\Carbon;
use Illuminate\Foundation\Events\LocaleUpdated;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
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
        $this->app->scoped(TaxRuleBook::class);

        // The gateway is chosen in the platform panel; the sandbox is the default.
        $this->app->bind(TaxAuthorityGateway::class, fn ($app) => match ($app->make(IntegrationSettings::class)->gateway()['driver']) {
            'http' => $app->make(HttpGateway::class),
            'portal' => $app->make(PortalGateway::class),
            'desktop' => $app->make(DesktopGateway::class),
            default => $app->make(SandboxGateway::class),
        });

        // The tax cabinet: the portal robot when configured, otherwise a sandbox cabinet with no network.
        $this->app->bind(TaxPortal::class, fn ($app) => $app->make(IntegrationSettings::class)->portal()['driver'] === 'robot'
            ? $app->make(RobotPortal::class)
            : $app->make(SandboxPortal::class));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Central-only tables (plans, tenants, platform staff, audit, tax rules). Company databases migrate
        // database/migrations only, so these never end up inside a company's database.
        $this->loadMigrationsFrom(database_path('migrations/platform'));

        Paginator::defaultView('pagination.app');

        // Carbon's "uz" is Uzbek Cyrillic; the interface is Latin, so "5 daqiqa avval" needs uz_Latn.
        $carbonLocale = fn (string $locale) => Carbon::setLocale($locale === 'uz' ? 'uz_Latn' : $locale);
        $carbonLocale($this->app->getLocale());
        Event::listen(LocaleUpdated::class, fn (LocaleUpdated $event) => $carbonLocale($event->locale));

        Gate::define('view-reports', fn (User $user) => $user->role->canViewReports());
        Gate::define('manage-settings', fn (User $user) => $user->role->canManageSettings());
        Gate::define('manage-users', fn (User $user) => $user->role->canManageUsers());
        Gate::define('view-employees', fn (User $user) => $user->role->canManageSettings());

        // Fine-grained permissions (reports.approve, autopilot.manage, ...): role defaults plus owner grants.
        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, fn ($user) => $user instanceof User && $user->hasPermission($permission));
        }
    }
}
