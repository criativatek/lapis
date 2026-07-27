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
    });
