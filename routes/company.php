<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ContractorController;
use App\Http\Controllers\ContractorTransferController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentPdfController;
use App\Http\Controllers\DocumentStatusController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TransactionImportController;
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

// Every application route lives in this one group. In multi-company mode it will also
// carry the tenancy middleware; nothing inside needs to know a company id.
Route::middleware('auth')->group(function () {
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

    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/', [SettingsController::class, 'company'])->name('company');
        Route::put('/', [SettingsController::class, 'updateCompany'])->name('company.update');
        Route::get('/users', [SettingsController::class, 'users'])->name('users');
        Route::get('/employees', [SettingsController::class, 'employees'])->name('employees');
        Route::get('/audit', [SettingsController::class, 'audit'])->name('audit');
    });
});
