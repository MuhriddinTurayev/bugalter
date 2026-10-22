<?php

use App\Support\PlatformUrl;

if (! function_exists('platform_route')) {
    /**
     * URL of a platform admin route. Works on the admin subdomain and, locally, on the
     * path fallback (<root host>/platform), keeping the visitor on the host they came from.
     *
     * @param  mixed  $parameters  route parameters, or a single model/key
     */
    function platform_route(string $name, mixed $parameters = []): string
    {
        return PlatformUrl::route($name, $parameters);
    }
}
