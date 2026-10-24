<?php

namespace App\Http\Middleware;

use App\Models\DesktopAgent;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The desktop agent's API: a bearer token per paired computer (only its SHA-256 is stored). Runs after
 * CompanyContext, so the token is looked up in the company's own database and works only on its host.
 */
class AuthenticateDesktopAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->bearerToken();
        $agent = str_starts_with($token, 'bga_') ? DesktopAgent::findByToken($token) : null;

        if ($agent === null) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        if ($agent->last_seen_at === null || $agent->last_seen_at->diffInSeconds(now(), true) >= 15 || $agent->last_ip !== $request->ip()) {
            $agent->forceFill(['last_seen_at' => now(), 'last_ip' => $request->ip()])->save();
        }

        $request->attributes->set('desktop_agent', $agent);

        return $next($request);
    }
}
