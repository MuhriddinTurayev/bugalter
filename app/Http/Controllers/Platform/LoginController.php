<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Middleware\PlatformTwoFactor;
use App\Http\Requests\Platform\LoginRequest;
use App\Services\Platform\PlatformAudit;
use App\Support\PlatformUrl;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    public function create(): View|RedirectResponse
    {
        if (Auth::guard('platform')->check()) {
            return redirect(PlatformUrl::route('dashboard'));
        }

        return view('platform.auth.login');
    }

    public function store(LoginRequest $request, PlatformAudit $audit): RedirectResponse
    {
        $staff = $request->authenticate();

        $request->session()->regenerate();
        $request->session()->forget([PlatformTwoFactor::SESSION_VERIFIED, PlatformTwoFactor::SESSION_SKIPPED]);

        $staff->forceFill(['last_login_at' => now()])->save();
        $audit->record('staff.login', null, null, [], $staff);

        // The two-factor middleware sends the user to enrolment or the code challenge as needed.
        return redirect()->intended(PlatformUrl::route('dashboard'));
    }

    public function destroy(Request $request, PlatformAudit $audit): RedirectResponse
    {
        $audit->record('staff.logout');

        Auth::guard('platform')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect(PlatformUrl::route('login'));
    }
}
