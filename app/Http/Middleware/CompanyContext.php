<?php

namespace App\Http\Middleware;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Support\PlatformUrl;
use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decides which company (database) a web request belongs to, from its host.
 *
 *  - platform routes: untouched (they only use the central database);
 *  - root host / localhost: no company. A landing page, or the single company dev mode;
 *  - <slug>.<domain>: that company's database becomes the default connection;
 *  - anything else: 404.
 *
 * It runs before the session and authentication start, so users are always read from the
 * right database.
 */
class CompanyContext
{
    public function handle(Request $request, Closure $next): Response
    {
        if (str_starts_with((string) $request->route()?->getName(), 'platform.')) {
            return $next($request);
        }

        // The admin host serves the platform panel only, never a company.
        abort_if(PlatformUrl::isAdminHost($request), 404);

        if (PlatformUrl::isCentralHost($request)) {
            if (config('app.single_company')) {
                return $next($request);
            }

            // The root host has no login form and no company data.
            abort_unless($request->is('/'), 404);

            return response()->view('landing');
        }

        return app(InitializeTenancyByDomain::class)->handle($request, function (Request $request) use ($next) {
            /** @var Tenant $tenant */
            $tenant = tenant();

            if ($tenant->status !== TenantStatus::Active) {
                return response()->view('errors.company-unavailable', ['tenant' => $tenant], $tenant->status === TenantStatus::Suspended ? 403 : 503);
            }

            $this->touchActivity($tenant);

            return $next($request);
        });
    }

    /** Remembers when the company was last used, at most once every five minutes. */
    private function touchActivity(Tenant $tenant): void
    {
        if ($tenant->last_activity_at === null || $tenant->last_activity_at->diffInMinutes(now(), true) >= 5) {
            $tenant->forceFill(['last_activity_at' => now()])->saveQuietly();
        }
    }
}
