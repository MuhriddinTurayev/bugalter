<?php

use App\Http\Middleware\AuthenticateDesktopAgent;
use App\Http\Middleware\CompanyContext;
use App\Http\Middleware\PlatformAuthenticate;
use App\Http\Middleware\PlatformPathFallbackOnly;
use App\Http\Middleware\PlatformTwoFactor;
use App\Http\Middleware\RequireSuperAdmin;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetPlatformSession;
use App\Support\PlatformUrl;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // The desktop agent's API: the company comes from the host (CompanyContext), the computer from its token.
        then: fn () => Route::middleware([CompanyContext::class, 'throttle:120,1', AuthenticateDesktopAgent::class, SubstituteBindings::class])
            ->prefix('api/agent')
            ->name('agent-api.')
            ->group(base_path('routes/agent.php')),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Order matters: the platform session settings and the company (tenancy) context must be
        // in place before the session starts and before any user is loaded.
        $middleware->web(prepend: [SetPlatformSession::class, CompanyContext::class], append: [SetLocale::class]);

        $middleware->alias([
            'platform.auth' => PlatformAuthenticate::class,
            'platform.2fa' => PlatformTwoFactor::class,
            'platform.super' => RequireSuperAdmin::class,
            'platform.fallback' => PlatformPathFallbackOnly::class,
        ]);

        $middleware->redirectGuestsTo(fn (Request $request) => $request->routeIs('platform.*')
            ? PlatformUrl::route('login')
            : route('login'));
        $middleware->redirectUsersTo('/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
