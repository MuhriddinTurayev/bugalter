<?php

namespace App\Http\Middleware;

use App\Support\PlatformUrl;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The <root host>/platform fallback exists only locally, only when enabled, and only on a root host. */
class PlatformPathFallbackOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(PlatformUrl::pathFallbackEnabled() && PlatformUrl::isCentralHost($request), 404);

        return $next($request);
    }
}
