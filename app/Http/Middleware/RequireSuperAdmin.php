<?php

namespace App\Http\Middleware;

use App\Models\PlatformStaff;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequireSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var PlatformStaff|null $staff */
        $staff = Auth::guard('platform')->user();

        abort_unless($staff?->isSuperAdmin(), 403);

        return $next($request);
    }
}
