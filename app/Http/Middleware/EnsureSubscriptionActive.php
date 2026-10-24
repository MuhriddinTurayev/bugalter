<?php

namespace App\Http\Middleware;

use App\Services\PlanLimits;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A company whose subscription ended can still sign in and read everything, but cannot change data until the
 * platform extends the subscription. Signing out and switching the language always work.
 */
class EnsureSubscriptionActive
{
    public function __construct(private readonly PlanLimits $limits) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe() && $this->limits->isSubscriptionExpired() && ! $request->routeIs('logout', 'locale')) {
            if ($request->expectsJson()) {
                abort(402, __('ui.limits.subscription_expired'));
            }

            return back()->with('error', __('ui.limits.subscription_expired'));
        }

        return $next($request);
    }
}
