<?php

namespace App\Support;

use Illuminate\Http\Request;

/** Host and URL rules of the platform: root host, admin subdomain, company subdomains. */
final class PlatformUrl
{
    public static function isFallbackRequest(?Request $request = null): bool
    {
        return ($request ?? request())->routeIs('platform.path.*');
    }

    public static function route(string $name, mixed $parameters = []): string
    {
        $prefix = self::isFallbackRequest() ? 'platform.path.' : 'platform.';

        return route($prefix.$name, $parameters);
    }

    public static function isAdminHost(Request $request): bool
    {
        return strtolower($request->getHost()) === strtolower((string) config('platform.admin_host'));
    }

    /** Root host, www, localhost: the hosts that are not a company. */
    public static function isCentralHost(Request $request): bool
    {
        return in_array(strtolower($request->getHost()), array_map('strtolower', config('tenancy.central_domains', [])), true);
    }

    public static function pathFallbackEnabled(): bool
    {
        return (bool) config('platform.path_fallback') && app()->environment('local');
    }
}
