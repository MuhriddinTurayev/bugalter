<?php

use App\Http\Controllers\Platform\AuditLogController;
use App\Http\Controllers\Platform\CompanyController;
use App\Http\Controllers\Platform\DashboardController;
use App\Http\Controllers\Platform\KillSwitchController;
use App\Http\Controllers\Platform\LocaleController;
use App\Http\Controllers\Platform\LoginController;
use App\Http\Controllers\Platform\PlanController;
use App\Http\Controllers\Platform\ProfileController;
use App\Http\Controllers\Platform\StaffController;
use App\Http\Controllers\Platform\StaffInvitationController;
use App\Http\Controllers\Platform\SupportAccessController;
use App\Http\Controllers\Platform\TwoFactorController;
use App\Support\PlatformUrl;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Platform admin panel
|--------------------------------------------------------------------------
|
| The same routes are served on the admin subdomain (names "platform.*") and, locally and only
| when enabled, under <root host>/platform (names "platform.path.*"). All of them use the
| separate "platform" guard, session cookie and database tables. See config/platform.php.
|
*/

$platformRoutes = function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:30,1')->name('login.store');
    Route::get('/staff-invitation/{token}', [StaffInvitationController::class, 'show'])->name('invitation.show');
    Route::post('/staff-invitation/{token}', [StaffInvitationController::class, 'accept'])->middleware('throttle:10,1')->name('invitation.accept');

    Route::middleware('platform.auth')->group(function () {
        Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

        // Two-factor: reachable before it is verified, everything below is not.
        Route::get('/two-factor/challenge', [TwoFactorController::class, 'challenge'])->name('two-factor.challenge');
        Route::post('/two-factor/challenge', [TwoFactorController::class, 'verify'])->middleware('throttle:10,1')->name('two-factor.verify');
        Route::get('/two-factor/enroll', [TwoFactorController::class, 'enroll'])->name('two-factor.enroll');
        Route::post('/two-factor/enroll', [TwoFactorController::class, 'confirm'])->middleware('throttle:10,1')->name('two-factor.confirm');
        Route::post('/two-factor/skip', [TwoFactorController::class, 'skip'])->name('two-factor.skip');

        Route::middleware('platform.2fa')->group(function () {
            Route::get('/', DashboardController::class)->name('dashboard');
            Route::get('/locale/{locale}', LocaleController::class)->name('locale');

            // Companies
            Route::get('/companies', [CompanyController::class, 'index'])->name('companies.index');
            Route::middleware('platform.super')->group(function () {
                Route::get('/companies/create', [CompanyController::class, 'create'])->name('companies.create');
                Route::get('/companies/slug-check', [CompanyController::class, 'slugCheck'])->name('companies.slug-check');
                Route::post('/companies', [CompanyController::class, 'store'])->name('companies.store');
                Route::patch('/companies/{tenant}/plan', [CompanyController::class, 'changePlan'])->name('companies.plan');
                Route::post('/companies/{tenant}/suspend', [CompanyController::class, 'suspend'])->name('companies.suspend');
                Route::post('/companies/{tenant}/restore', [CompanyController::class, 'restore'])->name('companies.restore');
                Route::post('/companies/{tenant}/invitation', [CompanyController::class, 'resendInvitation'])->name('companies.invitation');
            });
            Route::get('/companies/{tenant}', [CompanyController::class, 'show'])->name('companies.show');

            // Support access (read-only, reasoned, time-limited, audited)
            Route::post('/companies/{tenant}/support-access', [SupportAccessController::class, 'start'])->name('support.start');
            Route::get('/companies/{tenant}/support', [SupportAccessController::class, 'show'])->name('support.show');
            Route::delete('/support-sessions/{session}', [SupportAccessController::class, 'end'])->name('support.end');

            // Plans: everyone may look, only super admins change them
            Route::get('/plans', [PlanController::class, 'index'])->name('plans.index');
            Route::middleware('platform.super')->group(function () {
                Route::get('/plans/create', [PlanController::class, 'create'])->name('plans.create');
                Route::post('/plans', [PlanController::class, 'store'])->name('plans.store');
                Route::get('/plans/{plan}/edit', [PlanController::class, 'edit'])->name('plans.edit');
                Route::put('/plans/{plan}', [PlanController::class, 'update'])->name('plans.update');

                Route::get('/staff', [StaffController::class, 'index'])->name('staff.index');
                Route::post('/staff', [StaffController::class, 'store'])->name('staff.store');
                Route::patch('/staff/{staff}/toggle', [StaffController::class, 'toggle'])->name('staff.toggle');
                Route::patch('/staff/{staff}/role', [StaffController::class, 'changeRole'])->name('staff.role');

                Route::get('/kill-switch', [KillSwitchController::class, 'show'])->name('kill-switch');
                Route::post('/kill-switch', [KillSwitchController::class, 'update'])->name('kill-switch.update');
            });

            Route::get('/audit-log', AuditLogController::class)->name('audit');

            Route::get('/profile', [ProfileController::class, 'show'])->name('profile');
            Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
            Route::post('/profile/recovery-codes', [ProfileController::class, 'regenerateRecoveryCodes'])->name('profile.recovery-codes');
            Route::post('/profile/two-factor/reset', [ProfileController::class, 'resetTwoFactor'])->name('profile.two-factor.reset');
        });
    });
};

Route::domain(config('platform.admin_host'))->name('platform.')->group($platformRoutes);

if (PlatformUrl::pathFallbackEnabled()) {
    Route::prefix('platform')->middleware('platform.fallback')->name('platform.path.')->group($platformRoutes);
}
