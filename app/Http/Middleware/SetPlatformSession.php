<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform routes get their own session cookie, a shorter lifetime and the "platform" guard,
 * so a company session and a platform session can never be mixed up, even on the same host.
 * Must run before StartSession.
 */
class SetPlatformSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (str_starts_with((string) $request->route()?->getName(), 'platform.')) {
            config([
                'session.cookie' => config('platform.session_cookie'),
                'session.lifetime' => config('platform.session_lifetime_minutes'),
            ]);

            Auth::shouldUse('platform');
        }

        return $next($request);
    }
}
