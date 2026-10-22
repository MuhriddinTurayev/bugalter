<?php

namespace App\Http\Middleware;

use App\Models\PlatformStaff;
use App\Services\Platform\TwoFactor;
use App\Support\PlatformUrl;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two-factor authentication is mandatory. Until it is enrolled and verified in this session,
 * nothing else in the panel is reachable. A local-only skip exists; it is re-checked here on
 * every request, so it cannot be forced through session data in any other environment.
 */
class PlatformTwoFactor
{
    public const SESSION_VERIFIED = 'platform.2fa_verified';

    public const SESSION_SKIPPED = 'platform.2fa_skipped';

    public function handle(Request $request, Closure $next): Response
    {
        /** @var PlatformStaff $staff */
        $staff = Auth::guard('platform')->user();

        if ($staff->hasTwoFactor()) {
            return $request->session()->get(self::SESSION_VERIFIED) === true
                ? $next($request)
                : redirect(PlatformUrl::route('two-factor.challenge'));
        }

        if (TwoFactor::skipAllowed() && $request->session()->get(self::SESSION_SKIPPED) === true) {
            return $next($request);
        }

        return redirect(PlatformUrl::route('two-factor.enroll'));
    }
}
