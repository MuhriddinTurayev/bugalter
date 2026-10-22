<?php

use App\Enums\PlatformRole;
use App\Http\Middleware\PlatformTwoFactor;
use App\Models\PlatformAuditLog;
use App\Models\PlatformStaff;
use App\Services\Platform\TwoFactor;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

it('shows the platform login page on the admin host', function () {
    get(admin('/login'))->assertOk()->assertSee(__('platform.login.title'))->assertSee('name="password"', false);
});

it('redirects guests from every panel page to the login page', function (string $path) {
    get(admin($path))->assertRedirect(admin('/login'));
})->with(['/', '/companies', '/plans', '/audit-log', '/profile', '/staff', '/kill-switch']);

it('signs staff in with email and password and logs it', function () {
    $staff = PlatformStaff::factory()->withTwoFactor()->create(['email' => 'boss@bugalter.test']);

    post(admin('/login'), ['email' => 'BOSS@bugalter.test', 'password' => 'Correct-Horse-9'])->assertRedirect();

    expect(auth('platform')->id())->toBe($staff->id)
        ->and($staff->fresh()->last_login_at)->not->toBeNull()
        ->and(PlatformAuditLog::where('action', 'staff.login')->where('staff_id', $staff->id)->exists())->toBeTrue();
});

it('rejects wrong credentials, unknown users and disabled staff, and logs failures', function () {
    PlatformStaff::factory()->create(['email' => 'a@bugalter.test']);
    PlatformStaff::factory()->inactive()->create(['email' => 'off@bugalter.test']);

    post(admin('/login'), ['email' => 'a@bugalter.test', 'password' => 'wrong'])->assertSessionHasErrors('email');
    post(admin('/login'), ['email' => 'nobody@bugalter.test', 'password' => 'Correct-Horse-9'])->assertSessionHasErrors('email');
    post(admin('/login'), ['email' => 'off@bugalter.test', 'password' => 'Correct-Horse-9'])->assertSessionHasErrors('email');

    expect(auth('platform')->check())->toBeFalse()
        ->and(PlatformAuditLog::where('action', 'staff.login_failed')->count())->toBe(3);
});

it('throttles repeated failed logins', function () {
    RateLimiter::clear('platform-login|a@bugalter.test|127.0.0.1');
    PlatformStaff::factory()->create(['email' => 'a@bugalter.test']);

    foreach (range(1, 5) as $_) {
        post(admin('/login'), ['email' => 'a@bugalter.test', 'password' => 'wrong']);
    }

    post(admin('/login'), ['email' => 'a@bugalter.test', 'password' => 'Correct-Horse-9'])->assertSessionHasErrors('email');
    expect(auth('platform')->check())->toBeFalse();
});

it('signs out an inactive member that is still holding a session', function () {
    $staff = signInStaff();
    $staff->update(['is_active' => false]);

    get(admin('/'))->assertRedirect(admin('/login'));
});

describe('two-factor authentication is mandatory', function () {
    it('sends staff without 2FA to enrolment before anything else', function (string $path) {
        $staff = PlatformStaff::factory()->create();
        $this->actingAs($staff, 'platform');

        get(admin($path))->assertRedirect(admin('/two-factor/enroll'));
    })->with(['/', '/companies', '/plans', '/audit-log']);

    it('asks enrolled staff for a code until it is verified in the session', function () {
        $staff = signInStaff(verified: false);

        get(admin('/'))->assertRedirect(admin('/two-factor/challenge'));
        get(admin('/companies'))->assertRedirect(admin('/two-factor/challenge'));

        expect($staff->hasTwoFactor())->toBeTrue();
    });

    it('enrols with a QR code, confirms with a real code and issues hashed recovery codes', function () {
        $staff = PlatformStaff::factory()->create();
        $this->actingAs($staff, 'platform');

        get(admin('/two-factor/enroll'))->assertOk()->assertSee('<svg', false)->assertSee($staff->fresh()->two_factor_secret);

        $secret = $staff->fresh()->two_factor_secret;
        post(admin('/two-factor/enroll'), ['code' => '000000'])->assertSessionHasErrors('code');
        expect($staff->fresh()->hasTwoFactor())->toBeFalse();

        $response = post(admin('/two-factor/enroll'), ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertOk();

        $staff->refresh();
        expect($staff->hasTwoFactor())->toBeTrue()
            ->and($staff->two_factor_recovery_codes)->toHaveCount(TwoFactor::RECOVERY_CODE_COUNT);

        // Only hashes are stored; the plain codes are on the page exactly once.
        $plain = $response->viewData('codes');
        expect($plain)->toHaveCount(8)
            ->and($staff->two_factor_recovery_codes)->not->toContain($plain[0])
            ->and($staff->two_factor_recovery_codes)->toContain(hash('sha256', $plain[0]));

        get(admin('/'))->assertOk();
    });

    it('verifies a TOTP code at the challenge and refuses to accept the same code twice', function () {
        $staff = signInStaff(verified: false);
        $secret = $staff->two_factor_secret;
        $code = (new Google2FA)->getCurrentOtp($secret);

        post(admin('/two-factor/challenge'), ['code' => '123456'])->assertSessionHasErrors('code');
        post(admin('/two-factor/challenge'), ['code' => $code])->assertRedirect();
        get(admin('/'))->assertOk();

        // A fresh session (new login) cannot replay the code that was already used.
        $this->flushSession();
        $this->withSession([]);
        post(admin('/two-factor/challenge'), ['code' => $code])->assertSessionHasErrors('code');
    });

    it('accepts a recovery code once only', function () {
        $staff = signInStaff(verified: false);
        $staff->forceFill(['two_factor_recovery_codes' => [hash('sha256', 'abcde-fghij')]])->save();

        post(admin('/two-factor/challenge'), ['recovery_code' => 'ABCDE-fghij'])->assertRedirect();
        expect($staff->fresh()->two_factor_recovery_codes)->toBe([]);

        $this->flushSession();
        post(admin('/two-factor/challenge'), ['recovery_code' => 'abcde-fghij'])->assertSessionHasErrors('code');
    });

    it('does not let a session flag alone skip 2FA for an enrolled member', function () {
        signInStaff(verified: false);
        $this->withSession([PlatformTwoFactor::SESSION_SKIPPED => true]);

        get(admin('/'))->assertRedirect(admin('/two-factor/challenge'));
    });
});

describe('the local-only 2FA skip', function () {
    beforeEach(function () {
        // Spoofing the environment makes Laravel stop treating this as a test run, so CSRF would apply.
        $this->withoutMiddleware([
            PreventRequestForgery::class,
            ValidateCsrfToken::class,
            VerifyCsrfToken::class,
        ]);
        config(['platform.two_factor_optional' => true]);
        $this->staff = PlatformStaff::factory()->create();
        $this->actingAs($this->staff, 'platform');
    });

    it('works in the local environment when explicitly enabled, and shows a red warning banner', function () {
        app()->detectEnvironment(fn () => 'local');

        get(admin('/two-factor/enroll'))->assertOk()->assertSee(__('platform.two_factor.skip'));
        post(admin('/two-factor/skip'))->assertRedirect(route('platform.dashboard'));

        get(admin('/'))->assertOk()->assertSee(__('platform.two_factor.local_banner'));
        expect(PlatformAuditLog::where('action', 'staff.two_factor_skipped')->exists())->toBeTrue();
    });

    it('does NOT work in production, even with the option enabled', function () {
        app()->detectEnvironment(fn () => 'production');

        get(admin('/two-factor/enroll'))->assertOk()->assertDontSee(__('platform.two_factor.skip'))->assertDontSee(__('platform.two_factor.local_banner'));
        post(admin('/two-factor/skip'))->assertForbidden();

        // …and a forged session flag does not unlock the panel either.
        $this->withSession([PlatformTwoFactor::SESSION_SKIPPED => true]);
        get(admin('/'))->assertRedirect(admin('/two-factor/enroll'));
        expect(PlatformAuditLog::where('action', 'staff.two_factor_skipped')->exists())->toBeFalse();
    });

    it('does NOT work in any non-local environment (testing, staging)', function (string $environment) {
        app()->detectEnvironment(fn () => $environment);

        post(admin('/two-factor/skip'))->assertForbidden();
        get(admin('/'))->assertRedirect(admin('/two-factor/enroll'));
    })->with(['testing', 'staging', 'production']);

    it('does not work in the local environment unless the option is enabled', function () {
        app()->detectEnvironment(fn () => 'local');
        config(['platform.two_factor_optional' => false]);

        post(admin('/two-factor/skip'))->assertForbidden();
    });

    it('knows the rule in one place', function () {
        app()->detectEnvironment(fn () => 'local');
        expect(TwoFactor::skipAllowed())->toBeTrue();

        app()->detectEnvironment(fn () => 'production');
        expect(TwoFactor::skipAllowed())->toBeFalse();
    });
});

it('lets staff manage their own password and recovery codes', function () {
    $staff = signInStaff();

    $this->put(admin('/profile/password'), ['current_password' => 'wrong', 'password' => 'New-Password-2026', 'password_confirmation' => 'New-Password-2026'])
        ->assertSessionHasErrors('current_password');
    $this->put(admin('/profile/password'), ['current_password' => 'Correct-Horse-9', 'password' => 'weak', 'password_confirmation' => 'weak'])
        ->assertSessionHasErrors('password');
    $this->put(admin('/profile/password'), ['current_password' => 'Correct-Horse-9', 'password' => 'New-Password-2026', 'password_confirmation' => 'New-Password-2026'])
        ->assertSessionHasNoErrors();

    expect(Hash::check('New-Password-2026', $staff->fresh()->password))->toBeTrue();

    post(admin('/profile/recovery-codes'), ['current_password' => 'New-Password-2026'])->assertSessionHas('recovery_codes');
    expect($staff->fresh()->two_factor_recovery_codes)->toHaveCount(8);

    get(admin('/profile'))->assertOk()->assertSee(__('platform.profile.mandatory'));
});

it('keeps roles apart', function () {
    expect(PlatformRole::SuperAdmin->label())->not->toBe(PlatformRole::Support->label());
});
