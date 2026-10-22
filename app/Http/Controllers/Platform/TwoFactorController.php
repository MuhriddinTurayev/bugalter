<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Middleware\PlatformTwoFactor;
use App\Models\PlatformStaff;
use App\Services\Platform\PlatformAudit;
use App\Services\Platform\TwoFactor;
use App\Support\PlatformUrl;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactor $twoFactor, private readonly PlatformAudit $audit) {}

    public function challenge(Request $request): View|RedirectResponse
    {
        $staff = $this->staff($request);

        if (! $staff->hasTwoFactor()) {
            return redirect(PlatformUrl::route('two-factor.enroll'));
        }

        if ($request->session()->get(PlatformTwoFactor::SESSION_VERIFIED) === true) {
            return redirect(PlatformUrl::route('dashboard'));
        }

        return view('platform.auth.two-factor-challenge');
    }

    public function verify(Request $request): RedirectResponse
    {
        $staff = $this->staff($request);
        $key = 'platform-2fa|'.$staff->id;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)])]);
        }

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:32'],
            'recovery_code' => ['nullable', 'string', 'max:32'],
        ]);

        $ok = ! empty($data['code'])
            ? $this->twoFactor->verify($staff, $data['code'])
            : (! empty($data['recovery_code']) && $this->twoFactor->consumeRecoveryCode($staff, $data['recovery_code']));

        if (! $ok) {
            RateLimiter::hit($key);
            $this->audit->record('staff.two_factor_failed', null, null, [], $staff);

            throw ValidationException::withMessages(['code' => __('platform.two_factor.invalid')]);
        }

        RateLimiter::clear($key);

        if (empty($data['code'])) {
            $this->audit->record('staff.recovery_code_used', null, null, ['remaining' => $this->twoFactor->remainingRecoveryCodes($staff)], $staff);
        }

        $request->session()->regenerate();
        $request->session()->put(PlatformTwoFactor::SESSION_VERIFIED, true);

        return redirect()->intended(PlatformUrl::route('dashboard'));
    }

    public function enroll(Request $request): View|RedirectResponse
    {
        $staff = $this->staff($request);

        if ($staff->hasTwoFactor()) {
            return redirect(PlatformUrl::route('dashboard'));
        }

        // A pending (unconfirmed) secret is kept so a page reload shows the same QR code.
        if ($staff->two_factor_secret === null) {
            $staff->forceFill(['two_factor_secret' => $this->twoFactor->generateSecret()])->save();
        }

        return view('platform.auth.two-factor-enroll', [
            'qr' => $this->twoFactor->qrSvg($staff, $staff->two_factor_secret),
            'secret' => $staff->two_factor_secret,
            'canSkip' => TwoFactor::skipAllowed(),
        ]);
    }

    public function confirm(Request $request): View|RedirectResponse
    {
        $staff = $this->staff($request);

        if ($staff->hasTwoFactor() || $staff->two_factor_secret === null) {
            return redirect(PlatformUrl::route('two-factor.enroll'));
        }

        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);

        if (! $this->twoFactor->verify($staff, $data['code'])) {
            throw ValidationException::withMessages(['code' => __('platform.two_factor.invalid')]);
        }

        $this->twoFactor->confirm($staff);
        $codes = $this->twoFactor->issueRecoveryCodes($staff);
        $this->audit->record('staff.two_factor_enrolled', null, null, [], $staff);

        $request->session()->regenerate();
        $request->session()->put(PlatformTwoFactor::SESSION_VERIFIED, true);
        $request->session()->forget(PlatformTwoFactor::SESSION_SKIPPED);

        return view('platform.auth.recovery-codes', ['codes' => $codes]);
    }

    /** Local development only. Anywhere else this is a hard 403, whatever the config says. */
    public function skip(Request $request): RedirectResponse
    {
        abort_unless(TwoFactor::skipAllowed(), 403);

        $request->session()->put(PlatformTwoFactor::SESSION_SKIPPED, true);
        $this->audit->record('staff.two_factor_skipped', null, null, ['environment' => app()->environment()]);

        return redirect(PlatformUrl::route('dashboard'));
    }

    private function staff(Request $request): PlatformStaff
    {
        /** @var PlatformStaff */
        return $request->user('platform');
    }
}
