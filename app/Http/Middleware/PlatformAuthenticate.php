<?php

namespace App\Http\Middleware;

use App\Models\PlatformStaff;
use App\Support\PlatformUrl;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Staff must be signed in with the platform guard and still be active. */
class PlatformAuthenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var PlatformStaff|null $staff */
        $staff = Auth::guard('platform')->user();

        if ($staff === null) {
            return redirect()->guest(PlatformUrl::route('login'));
        }

        if (! $staff->is_active) {
            Auth::guard('platform')->logout();
            $request->session()->invalidate();

            return redirect(PlatformUrl::route('login'))->withErrors(['email' => __('platform.login.disabled')]);
        }

        return $next($request);
    }
}
