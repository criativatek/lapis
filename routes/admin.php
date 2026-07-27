<?php

use App\Http\Controllers\Admin\AdminAccountController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Platform backoffice
|--------------------------------------------------------------------------
|
| The SaaS operator's area. Gated by `platform-admin`, and deliberately WITHOUT
| the `organization` middleware — it spans every organization, so it must not be
| forced into a single tenant.
|
*/

Route::middleware(['auth', 'verified', 'platform-admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', [AdminAccountController::class, 'index'])->name('accounts.index');
        Route::get('accounts/{organization}', [AdminAccountController::class, 'show'])->name('accounts.show');
        Route::post('accounts/{organization}/verify-email', [AdminAccountController::class, 'verifyEmail'])->name('accounts.verify-email');
        Route::post('accounts/{organization}/plan', [AdminAccountController::class, 'changePlan'])->name('accounts.plan');
        Route::post('accounts/{organization}/suspend', [AdminAccountController::class, 'suspend'])->name('accounts.suspend');
        Route::post('accounts/{organization}/reactivate', [AdminAccountController::class, 'reactivate'])->name('accounts.reactivate');
        Route::post('accounts/{organization}/toggle-admin', [AdminAccountController::class, 'toggleAdmin'])->name('accounts.toggle-admin');
    });
