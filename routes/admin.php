<?php

use App\Http\Controllers\Admin\AdminAccountController;
use App\Http\Controllers\Admin\AdminImpersonateController;
use App\Http\Controllers\Admin\AdminSettingsController;
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
        // create BEFORE {organization} — otherwise "create" binds as an org ulid.
        Route::get('accounts/create', [AdminAccountController::class, 'create'])->name('accounts.create');
        Route::post('accounts', [AdminAccountController::class, 'store'])->name('accounts.store');
        Route::get('accounts/{organization}', [AdminAccountController::class, 'show'])->name('accounts.show');
        Route::post('accounts/{organization}/verify-email', [AdminAccountController::class, 'verifyEmail'])->name('accounts.verify-email');
        Route::post('accounts/{organization}/reset-password', [AdminAccountController::class, 'resetPassword'])->name('accounts.reset-password');
        Route::post('accounts/{organization}/temporary-password', [AdminAccountController::class, 'generateTemporaryPassword'])->name('accounts.temporary-password');
        Route::post('accounts/{organization}/plan', [AdminAccountController::class, 'changePlan'])->name('accounts.plan');
        Route::post('accounts/{organization}/suspend', [AdminAccountController::class, 'suspend'])->name('accounts.suspend');
        Route::post('accounts/{organization}/reactivate', [AdminAccountController::class, 'reactivate'])->name('accounts.reactivate');
        Route::post('accounts/{organization}/toggle-admin', [AdminAccountController::class, 'toggleAdmin'])->name('accounts.toggle-admin');

        // The person, not the subscription. `suspend`/`reactivate` above answer
        // "does this account still have a product"; these answer "may this
        // person sign in", which is a different question with a different blast
        // radius once an organization has more than one member.
        Route::put('accounts/{organization}/user', [AdminAccountController::class, 'updateUser'])->name('accounts.user.update');
        Route::post('accounts/{organization}/deactivate', [AdminAccountController::class, 'deactivate'])->name('accounts.deactivate');
        Route::post('accounts/{organization}/activate', [AdminAccountController::class, 'activate'])->name('accounts.activate');

        // Exceptional, and refuses far more often than it proceeds. Deactivating
        // is the normal way to remove somebody operationally; this is only for an
        // account that never became anything.
        Route::delete('accounts/{organization}', [AdminAccountController::class, 'destroy'])->name('accounts.destroy');

        // Fatia 2: the minimum needed to build and test an institutional
        // organization with more than one member — attaching an EXISTING user.
        // Not an invitation; that is Fatia 3.
        Route::post('accounts/{organization}/members', [AdminAccountController::class, 'addMember'])->name('accounts.members.add');

        // System email (SMTP) settings.
        Route::get('settings', [AdminSettingsController::class, 'edit'])->name('settings.edit');
        Route::put('settings', [AdminSettingsController::class, 'update'])->name('settings.update');
        Route::post('settings/test', [AdminSettingsController::class, 'test'])->name('settings.test');

        // Start impersonating the org's owner (support).
        Route::post('accounts/{organization}/impersonate', [AdminImpersonateController::class, 'start'])->name('accounts.impersonate');
    });

// Stop impersonation — reached AS the impersonated teacher, so it is only `auth`,
// not `platform-admin`.
Route::middleware('auth')->post('impersonate/stop', [AdminImpersonateController::class, 'stop'])->name('impersonate.stop');
