<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AiController;
use App\Http\Controllers\AiSettingsController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\AutopilotController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ContractorController;
use App\Http\Controllers\ContractorTransferController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentPdfController;
use App\Http\Controllers\DocumentStatusController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReportRunController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SigningKeyController;
use App\Http\Controllers\TaxAgentController;
use App\Http\Controllers\TaxController;
use App\Http\Controllers\TaxPaymentController;
use App\Http\Controllers\TaxPortalController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TransactionImportController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\EnsureSubscriptionActive;
use Illuminate\Support\Facades\Route;

// Company routes: the same file serves the single company dev mode and every <slug>.<domain> host.
// CompanyContext (web middleware group) has already chosen the database by the time these run.

Route::get('/locale/{locale}', LocaleController::class)->name('locale');

Route::middleware('guest')->group(function () {
    Route::get('/invitation/{token}', [InvitationController::class, 'show'])->name('invitation.show');
    Route::post('/invitation/{token}', [InvitationController::class, 'accept'])->name('invitation.accept')->middleware('throttle:10,1');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:20,1');
});

// Every application route lives in this one group. Nothing inside needs to know a company id.
// An ended subscription keeps the company readable but blocks every change.
Route::middleware(['auth', EnsureSubscriptionActive::class])->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/cards/{card}', [DashboardController::class, 'card'])
        ->whereIn('card', ['balance', 'weekly', 'trend', 'cashflow', 'payments', 'receivables'])
        ->name('dashboard.card');

    Route::get('/search', SearchController::class)->name('search');
    Route::post('/notifications/read', [NotificationController::class, 'markAllRead'])->name('notifications.read');

    Route::get('/contractors/export', [ContractorTransferController::class, 'export'])->name('contractors.export');
    Route::post('/contractors/import', [ContractorTransferController::class, 'import'])->name('contractors.import');
    Route::patch('/contractors/{contractor}/toggle', [ContractorController::class, 'toggle'])->name('contractors.toggle');
    Route::resource('contractors', ContractorController::class)->except(['show', 'destroy']);

    Route::get('/documents/{document}/pdf', DocumentPdfController::class)->name('documents.pdf');
    Route::patch('/documents/{document}/status', DocumentStatusController::class)->name('documents.status');
    Route::resource('documents', DocumentController::class);

    Route::prefix('bank')->name('bank.')->group(function () {
        Route::get('/', [BankController::class, 'index'])->name('index');
        Route::get('/transactions/create', [TransactionController::class, 'create'])->name('transactions.create');
        Route::post('/transactions', [TransactionController::class, 'store'])->name('transactions.store');

        Route::get('/accounts/create', [AccountController::class, 'create'])->name('accounts.create');
        Route::post('/accounts', [AccountController::class, 'store'])->name('accounts.store');
        Route::get('/accounts/{account}/edit', [AccountController::class, 'edit'])->name('accounts.edit');
        Route::put('/accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');

        Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::patch('/categories/{category}/toggle', [CategoryController::class, 'toggle'])->name('categories.toggle');

        Route::get('/import', [TransactionImportController::class, 'create'])->name('import');
        Route::post('/import/upload', [TransactionImportController::class, 'upload'])->name('import.upload');
        Route::post('/import/confirm', [TransactionImportController::class, 'store'])->name('import.store');
        Route::post('/import/cancel', [TransactionImportController::class, 'cancel'])->name('import.cancel');
    });

    Route::get('/history', HistoryController::class)->name('history');

    Route::get('/reports', ReportController::class)->middleware('can:view-reports')->name('reports');

    // AI accountant
    Route::prefix('ai')->name('ai.')->group(function () {
        Route::get('/', [AiController::class, 'index'])->name('index');
        Route::post('/messages', [AiController::class, 'message'])->middleware('throttle:30,1')->name('message');
        Route::delete('/conversations/{conversation}', [AiController::class, 'destroy'])->name('destroy');
        Route::get('/proposals', [AiController::class, 'proposals'])->name('proposals');
        Route::post('/proposals/classify', [AiController::class, 'classify'])->middleware('throttle:6,1')->name('classify');
        Route::post('/proposals/apply-all', [AiController::class, 'applyAll'])->name('apply-all');
        Route::patch('/proposals/{proposal}', [AiController::class, 'decide'])->name('decide');
    });

    // Taxes: calendar, reports, approval and submission
    Route::prefix('tax')->name('tax.')->group(function () {
        Route::get('/', [TaxController::class, 'index'])->name('index');
        Route::post('/prepare', [TaxController::class, 'prepare'])->name('prepare');
        Route::get('/reports/{report}', [TaxController::class, 'show'])->name('reports.show');
        Route::post('/reports/{report}/recalculate', [TaxController::class, 'recalculate'])->name('reports.recalculate');
        Route::post('/reports/{report}/request', [TaxController::class, 'requestSubmission'])->name('reports.request');
        Route::post('/reports/{report}/approve', [TaxController::class, 'approve'])->name('reports.approve');
        Route::post('/reports/{report}/cancel', [TaxController::class, 'cancel'])->name('reports.cancel');
        Route::post('/reports/{report}/submit', [TaxController::class, 'submitNow'])->name('reports.submit');
        Route::post('/reports/{report}/approve-submit', [TaxController::class, 'approveAndSubmit'])->middleware('throttle:10,1')->name('reports.approve-submit');
        Route::post('/reports/{report}/verify', [TaxController::class, 'verify'])->middleware('throttle:10,1')->name('reports.verify');
        Route::post('/reports/{report}/resolve', [TaxController::class, 'resolve'])->name('reports.resolve');

        // "Hisobotlarni avtomatik tayyorlash": company and month, live progress, review, approval, submission status
        Route::get('/runs/new', [ReportRunController::class, 'create'])->name('runs.create');
        Route::post('/runs', [ReportRunController::class, 'store'])->middleware('throttle:6,1')->name('runs.store');
        Route::get('/runs/{run}', [ReportRunController::class, 'show'])->name('runs.show');
        Route::get('/runs/{run}/status', [ReportRunController::class, 'status'])->name('runs.status');
        Route::get('/runs/{run}/approve', [ReportRunController::class, 'confirm'])->name('runs.confirm');
        Route::post('/runs/{run}/approve', [ReportRunController::class, 'approve'])->middleware('throttle:10,1')->name('runs.approve');

        // The tax cabinet (my.soliq.uz): last visit, what waits for approval, "check now"
        Route::get('/payments', [TaxPaymentController::class, 'index'])->name('payments');
        Route::post('/payments', [TaxPaymentController::class, 'store'])->middleware('throttle:10,1')->name('payments.store');
        Route::post('/payments/{payment}/cancel', [TaxPaymentController::class, 'cancel'])->name('payments.cancel');
        Route::get('/portal', [TaxPortalController::class, 'index'])->name('portal');
        Route::post('/portal/check', [TaxPortalController::class, 'check'])->middleware('throttle:6,1')->name('portal.check');
        Route::post('/reports/{report}/signature', [TaxController::class, 'deviceSignature'])->name('reports.signature');

        // The desktop agent: the accountant's Windows computer, a real browser and the official E-IMZO app
        Route::get('/agent', [TaxAgentController::class, 'index'])->name('agent');
        Route::post('/agent/computers', [TaxAgentController::class, 'pair'])->middleware('throttle:6,1')->name('agent.pair');
        Route::post('/agent/computers/{agent}/revoke', [TaxAgentController::class, 'revoke'])->name('agent.revoke');
        Route::post('/agent/rehearse', [TaxAgentController::class, 'rehearse'])->middleware('throttle:6,1')->name('agent.rehearse');
        Route::post('/agent/check', [TaxAgentController::class, 'check'])->middleware('throttle:6,1')->name('agent.check');
        Route::post('/agent/tasks/{task}/cancel', [TaxAgentController::class, 'cancel'])->name('agent.cancel');
        Route::post('/agent/tasks/{task}/send', [TaxAgentController::class, 'send'])->middleware('throttle:10,1')->name('agent.send');
        Route::post('/agent/tasks/{task}/hold', [TaxAgentController::class, 'hold'])->name('agent.hold');
        Route::post('/agent/tasks/{task}/link', [TaxAgentController::class, 'link'])->middleware('throttle:6,1')->name('agent.link');
    });

    // Payroll
    Route::prefix('payroll')->name('payroll.')->group(function () {
        Route::get('/', [PayrollController::class, 'index'])->name('index');
        Route::post('/', [PayrollController::class, 'store'])->name('store');
        Route::get('/{run}', [PayrollController::class, 'show'])->name('show');
        Route::patch('/{run}/lines/{line}', [PayrollController::class, 'updateLine'])->name('lines.update');
        Route::post('/{run}/approve', [PayrollController::class, 'approve'])->name('approve');
        Route::post('/{run}/reopen', [PayrollController::class, 'reopen'])->name('reopen');
        Route::post('/{run}/pay', [PayrollController::class, 'pay'])->name('pay');
    });

    // Autopilot, mandates and the kill switch (autopilot.manage), signing keys (signing.keys.manage)
    Route::get('/autopilot', [AutopilotController::class, 'show'])->name('autopilot.show');
    Route::put('/autopilot', [AutopilotController::class, 'update'])->name('autopilot.update');
    Route::post('/autopilot/mandates', [AutopilotController::class, 'storeMandate'])->name('autopilot.mandates.store');
    Route::post('/autopilot/mandates/{mandate}/revoke', [AutopilotController::class, 'revokeMandate'])->name('autopilot.mandates.revoke');
    Route::post('/autopilot/kill-switch', [AutopilotController::class, 'killSwitch'])->name('autopilot.kill-switch');

    Route::get('/signing-keys', [SigningKeyController::class, 'index'])->name('signing-keys.index');
    Route::post('/signing-keys', [SigningKeyController::class, 'store'])->middleware('throttle:10,1')->name('signing-keys.store');
    Route::post('/signing-keys/{key}/revoke', [SigningKeyController::class, 'revoke'])->name('signing-keys.revoke');

    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/', [SettingsController::class, 'company'])->name('company');
        Route::put('/', [SettingsController::class, 'updateCompany'])->name('company.update');
        Route::get('/audit', [SettingsController::class, 'audit'])->name('audit');

        Route::get('/users', [UserController::class, 'index'])->name('users');
        Route::post('/users', [UserController::class, 'store'])->middleware('throttle:20,1')->name('users.store');
        Route::patch('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::patch('/users/{user}/toggle', [UserController::class, 'toggle'])->name('users.toggle');
        Route::post('/users/{user}/resend', [UserController::class, 'resend'])->middleware('throttle:10,1')->name('users.resend');
        Route::put('/users/{user}/permissions', [UserController::class, 'permissions'])->name('users.permissions');

        Route::get('/employees', [EmployeeController::class, 'index'])->name('employees');
        Route::get('/employees/create', [EmployeeController::class, 'create'])->name('employees.create');
        Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store');
        Route::get('/employees/{employee}/edit', [EmployeeController::class, 'edit'])->name('employees.edit');
        Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');
        Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy'])->name('employees.destroy');

        Route::get('/ai', [AiSettingsController::class, 'edit'])->name('ai');
        Route::put('/ai', [AiSettingsController::class, 'update'])->name('ai.update');
        Route::post('/ai/test', [AiSettingsController::class, 'test'])->middleware('throttle:6,1')->name('ai.test');
    });
});
