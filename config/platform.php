<?php

$domain = env('PLATFORM_DOMAIN', 'bugalter');

return [

    /*
    |--------------------------------------------------------------------------
    | Platform Hosts
    |--------------------------------------------------------------------------
    |
    | The root host (e.g. "bugalter") has no login: it is a landing page. The platform
    | admin panel lives on admin.<domain>; each company lives on <slug>.<domain>.
    |
    */

    'domain' => $domain,

    'admin_host' => env('PLATFORM_ADMIN_HOST', 'admin.'.$domain),

    'scheme' => env('PLATFORM_SCHEME', parse_url((string) env('APP_URL', 'https://'.$domain), PHP_URL_SCHEME) ?: 'https'),

    /*
    | Local fallback: also serve the panel at <root host>/platform, for machines where
    | the admin subdomain does not resolve yet. Never enabled outside the local environment.
    */
    'path_fallback' => (bool) env('PLATFORM_PATH_FALLBACK', false),

    /*
    | Local development only (APP_ENV=local): extra base domains every new company also answers on,
    | e.g. PLATFORM_LOCAL_DOMAINS=localhost gives demo1.localhost. Browsers resolve *.localhost to this
    | machine without a hosts file, so it works with `php artisan serve` and no DNS or certificate setup.
    */
    'local_domains' => env('APP_ENV') === 'local'
        ? array_values(array_filter(array_map('trim', explode(',', (string) env('PLATFORM_LOCAL_DOMAINS', '')))))
        : [],

    /*
    | Subdomains that can never be a company slug.
    */
    'reserved_slugs' => [
        'admin', 'www', 'api', 'app', 'mail', 'smtp', 'ftp', 'platform', 'root', 'support', 'status', 'static',
        'assets', 'cdn', 'dev', 'test', 'staging', 'login', 'billing', 'help', 'docs', 'blog', 'ns1', 'ns2',
    ],

    /*
    |--------------------------------------------------------------------------
    | Platform Session
    |--------------------------------------------------------------------------
    */

    'session_cookie' => env('PLATFORM_SESSION_COOKIE', 'bugalter_platform_session'),

    'session_lifetime_minutes' => (int) env('PLATFORM_SESSION_LIFETIME', 60),

    /*
    |--------------------------------------------------------------------------
    | Two-Factor Authentication
    |--------------------------------------------------------------------------
    |
    | TOTP is mandatory for platform staff. This switch lets enrollment be skipped, and it
    | only has an effect when APP_ENV=local. In any other environment it is ignored.
    |
    */

    'two_factor_optional' => (bool) env('PLATFORM_ADMIN_2FA_OPTIONAL', false),

    'two_factor_issuer' => env('PLATFORM_2FA_ISSUER', 'Bugalter Platform'),

    /*
    |--------------------------------------------------------------------------
    | Support Access
    |--------------------------------------------------------------------------
    */

    'support_access_minutes' => (int) env('PLATFORM_SUPPORT_ACCESS_MINUTES', 30),

    'support_reason_min_length' => 10,

    /*
    | Days a staff or company-owner invitation link stays valid.
    */
    'invitation_days' => (int) env('PLATFORM_INVITATION_DAYS', 7),

];
