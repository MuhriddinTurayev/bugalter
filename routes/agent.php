<?php

use App\Http\Controllers\AgentApiController;
use Illuminate\Support\Facades\Route;

// The desktop agent's API (agent/). Token per paired computer, no session, no CSRF; JSON only.
// Registered in bootstrap/app.php behind CompanyContext, so it always runs inside one company's database.

Route::post('/heartbeat', [AgentApiController::class, 'heartbeat'])->name('heartbeat');
Route::post('/tasks/claim', [AgentApiController::class, 'claim'])->name('claim');
Route::post('/tasks/{task}/events', [AgentApiController::class, 'event'])->name('event');
Route::post('/tasks/{task}/preview', [AgentApiController::class, 'preview'])->name('preview');
Route::post('/tasks/{task}/decision', [AgentApiController::class, 'decision'])->name('decision');
Route::post('/tasks/{task}/payment-check', [AgentApiController::class, 'paymentCheck'])->name('payment-check');
Route::post('/tasks/{task}/form-check', [AgentApiController::class, 'formCheck'])->name('form-check');
Route::post('/tasks/{task}/complete', [AgentApiController::class, 'complete'])->name('complete');
Route::post('/tasks/{task}/fail', [AgentApiController::class, 'fail'])->name('fail');
