<?php

use App\Enums\TenantStatus;
use App\Models\Plan;
use App\Models\PlatformStaff;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Platform\TenantProvisioningService;
use App\Support\PlatformUrl;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

afterEach(fn () => cleanUpTenants());

describe('the root host has no login', function () {
    beforeEach(fn () => config(['app.single_company' => false]));

    it('serves a landing page without any login form', function () {
        get('https://bugalter/')->assertOk()->assertDontSee('name="password"', false)->assertDontSee('type="password"', false);
    });

    it('returns 404 for the login page and every other company route', function (string $path) {
        get('https://bugalter'.$path)->assertNotFound();
        get('https://www.bugalter'.$path)->assertNotFound();
    })->with(['/login', '/contractors', '/documents', '/bank', '/settings', '/invitation/abc']);

    it('does not accept a login post either', function () {
        User::factory()->owner()->create(['email' => 'o@example.test']);

        post('https://bugalter/login', ['login' => 'o@example.test', 'password' => 'password'])->assertNotFound();
        expect(auth()->check())->toBeFalse();
    });

    it('does not serve the platform panel on the root host unless the local path fallback is on', function () {
        get('https://bugalter/platform/login')->assertNotFound();
    });
});

describe('the local path fallback', function () {
    it('is never available outside the local environment', function () {
        config(['platform.path_fallback' => true]);

        expect(PlatformUrl::pathFallbackEnabled())->toBeFalse();

        app()->detectEnvironment(fn () => 'local');
        expect(PlatformUrl::pathFallbackEnabled())->toBeTrue();

        app()->detectEnvironment(fn () => 'production');
        expect(PlatformUrl::pathFallbackEnabled())->toBeFalse();
    });
});

describe('the admin host', function () {
    it('serves only the panel: company pages do not exist there', function (string $path) {
        get(admin($path))->assertNotFound();
    })->with(['/contractors', '/documents', '/bank', '/settings', '/invitation/abc', '/history']);

    it('uses its own session cookie, different from the company one', function () {
        // The company cookie name is Laravel's default, derived from the app name.
        $companyCookie = Str::slug((string) config('app.name')).'-session';

        $names = collect(get(admin('/login'))->headers->getCookies())->map->getName();

        expect($names)->toContain(config('platform.session_cookie'))->and($names)->not->toContain($companyCookie);
        expect(config('platform.session_cookie'))->not->toBe($companyCookie);
    });
});

describe('cross-login matrix', function () {
    it('company users cannot sign in on the admin host', function () {
        User::factory()->owner()->create(['email' => 'owner@company.test', 'phone' => '+998901234567']);

        post(admin('/login'), ['email' => 'owner@company.test', 'password' => 'password'])->assertSessionHasErrors('email');

        expect(auth('platform')->check())->toBeFalse()->and(auth('web')->check())->toBeFalse();
    });

    it('platform staff cannot sign in on a company host (single company mode, central database)', function () {
        PlatformStaff::factory()->create(['email' => 'staff@bugalter.test']);

        post('http://localhost/login', ['login' => 'staff@bugalter.test', 'password' => 'Correct-Horse-9'])->assertSessionHasErrors('login');

        expect(auth('web')->check())->toBeFalse()->and(auth('platform')->check())->toBeFalse();
    });

    it('platform staff cannot sign in on a real company host', function () {
        useSqliteTenancy();
        config(['app.single_company' => false]);
        $plan = Plan::factory()->create();
        app(TenantProvisioningService::class)->provision([
            'name' => 'Acme MChJ', 'stir' => null, 'slug' => 'acme', 'plan_id' => $plan->id,
            'owner_name' => 'Owner', 'owner_email' => 'owner@acme.test', 'owner_phone' => null,
        ]);
        resetRequestContext();

        PlatformStaff::factory()->create(['email' => 'staff@bugalter.test']);

        get(company('acme', '/login'))->assertOk();
        // Refused: sent back to the login page, nobody is signed in on either guard.
        post(company('acme', '/login'), ['login' => 'staff@bugalter.test', 'password' => 'Correct-Horse-9'])->assertRedirect(company('acme', '/login'));
        expect(auth('platform')->check())->toBeFalse();
        expect(auth('web')->check())->toBeFalse();
        resetRequestContext();

        // And the platform panel does not exist on a company host.
        get(company('acme', '/platform/login'))->assertNotFound();
        get(company('acme', '/companies'))->assertNotFound();
    });

    it('a company session does not open the panel and a panel session does not open a company', function () {
        $user = User::factory()->owner()->create();
        $this->actingAs($user);

        get(admin('/companies'))->assertRedirect(admin('/login'));

        $staff = signInStaff();
        // On company routes the default guard is "web" (the platform guard is only made the default
        // on platform routes), so a platform login means nothing there.
        Auth::shouldUse('web');
        app('auth')->guard('web')->forgetUser();

        get('http://localhost/contractors')->assertRedirect('http://localhost/login');
        expect($staff->exists)->toBeTrue();
    });

    it('company users see their own app unaffected by the platform guard', function () {
        signIn();

        get('http://localhost/')->assertOk()->assertSee(__('ui.dashboard.new_document'));
    });
});

describe('unknown and suspended hosts', function () {
    it('returns 404 for a subdomain that is not a company', function () {
        config(['app.single_company' => false]);

        get('https://nobody.bugalter/login')->assertNotFound();
        get('https://evil.example.com/login')->assertNotFound();
    });

    it('blocks a suspended company while keeping its data', function () {
        useSqliteTenancy();
        config(['app.single_company' => false]);
        $plan = Plan::factory()->create();
        $result = app(TenantProvisioningService::class)->provision([
            'name' => 'Acme MChJ', 'stir' => null, 'slug' => 'acme', 'plan_id' => $plan->id,
            'owner_name' => 'Owner', 'owner_email' => 'owner@acme.test', 'owner_phone' => null,
        ]);
        resetRequestContext();

        $result['tenant']->update(['status' => TenantStatus::Suspended]);

        get(company('acme', '/login'))->assertForbidden()->assertSee(__('ui.errors.company_unavailable_title'));
        expect(Tenant::find('acme')->status)->toBe(TenantStatus::Suspended);
    });
});
