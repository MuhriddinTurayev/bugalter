<?php

use App\Enums\PlatformRole;
use App\Enums\Role;
use App\Http\Middleware\PlatformTwoFactor;
use App\Models\PlatformStaff;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');

/** A signed-in user with the given role. */
function signIn(Role $role = Role::Owner): User
{
    $user = User::factory()->role($role)->create();
    test()->actingAs($user);

    return $user;
}

/** URL on the platform admin host, e.g. admin('/companies'). */
function admin(string $path = ''): string
{
    return 'https://'.config('platform.admin_host').$path;
}

/** URL on a company host, e.g. company('acme', '/login'). */
function company(string $slug, string $path = ''): string
{
    return 'https://'.$slug.'.'.config('platform.domain').$path;
}

/** Platform staff signed in on the platform guard, with two-factor already verified in the session. */
function signInStaff(PlatformRole $role = PlatformRole::SuperAdmin, bool $verified = true): PlatformStaff
{
    $factory = PlatformStaff::factory()->withTwoFactor();
    $staff = ($role === PlatformRole::Support ? $factory->support() : $factory)->create();

    test()->actingAs($staff, 'platform');

    if ($verified) {
        test()->withSession([PlatformTwoFactor::SESSION_VERIFIED => true]);
    }

    return $staff;
}

/**
 * Company databases are SQLite files in tests. They are removed again by cleanUpTenants().
 */
function useSqliteTenancy(): void
{
    config(['tenancy.database.suffix' => '.sqlite']);
}

/** Back to the central context between requests of different hosts in one test. */
function resetRequestContext(): void
{
    if (tenancy()->initialized) {
        tenancy()->end();
    }

    app('auth')->forgetGuards();
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store'); // otherwise session() keeps reading the dropped store
}

function cleanUpTenants(): void
{
    resetRequestContext();

    Tenant::query()->get()->each(function (Tenant $tenant) {
        $tenant->delete();
    });

    foreach (glob(database_path('tenant*.sqlite')) ?: [] as $file) {
        @unlink($file);
    }

    foreach (glob(storage_path('tenant*'), GLOB_ONLYDIR) ?: [] as $dir) {
        File::deleteDirectory($dir);
    }
}
