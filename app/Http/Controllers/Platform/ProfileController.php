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
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function __construct(private readonly TwoFactor $twoFactor, private readonly PlatformAudit $audit) {}

    public function show(Request $request): View
    {
        /** @var PlatformStaff $staff */
        $staff = $request->user('platform');

        return view('platform.profile', [
            'staff' => $staff,
            'remaining' => $this->twoFactor->remainingRecoveryCodes($staff),
            'codes' => session('recovery_codes'),
        ]);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        /** @var PlatformStaff $staff */
        $staff = $request->user('platform');

        $data = $request->validate([
            'current_password' => ['required', 'current_password:platform'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(12)->mixedCase()->numbers()],
        ], [], [
            'current_password' => __('platform.profile.current_password'),
            'password' => __('platform.profile.new_password'),
        ]);

        $staff->update(['password' => $data['password']]);
        $this->audit->record('staff.password_changed', null, null, [], $staff);

        // Other sessions of this account are signed out; this one stays.
        $request->session()->regenerate();

        return back()->with('status', __('platform.profile.password_changed'));
    }

    public function regenerateRecoveryCodes(Request $request): RedirectResponse
    {
        /** @var PlatformStaff $staff */
        $staff = $request->user('platform');

        $this->requirePassword($request, $staff);

        $codes = $this->twoFactor->issueRecoveryCodes($staff);
        $this->audit->record('staff.recovery_codes_regenerated', null, null, [], $staff);

        return back()->with('recovery_codes', $codes);
    }

    /** Starts enrolment again (for a new device). Needs the password and a valid current code. */
    public function resetTwoFactor(Request $request): RedirectResponse
    {
        /** @var PlatformStaff $staff */
        $staff = $request->user('platform');

        $this->requirePassword($request, $staff);

        $request->validate(['code' => ['required', 'string', 'max:12']], [], ['code' => __('platform.two_factor.code')]);

        if (! $this->twoFactor->verify($staff, (string) $request->input('code'))) {
            throw ValidationException::withMessages(['code' => __('platform.two_factor.invalid')]);
        }

        $this->twoFactor->reset($staff);
        $this->audit->record('staff.two_factor_reset', null, null, [], $staff);
        $request->session()->forget(PlatformTwoFactor::SESSION_VERIFIED);

        return redirect(PlatformUrl::route('two-factor.enroll'));
    }

    private function requirePassword(Request $request, PlatformStaff $staff): void
    {
        if (! Hash::check((string) $request->input('current_password'), (string) $staff->password)) {
            throw ValidationException::withMessages(['current_password' => __('auth.password')]);
        }
    }
}
