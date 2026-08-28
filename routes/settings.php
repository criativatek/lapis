<?php

use App\Http\Controllers\Settings\CheckoutController;
use App\Http\Controllers\Settings\PlanController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SchoolIdentityController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Recoverable closure (§5-§7) — distinct from profile.destroy above, which
    // stays immediate and exceptional (§34). No {user} parameter on either
    // route: both always act on the authenticated user, never a target
    // resolved from the URL, so there is no cross-account surface to guard.
    Route::post('settings/account-closure', [ProfileController::class, 'requestClosure'])->name('account.closure.request');
    Route::delete('settings/account-closure', [ProfileController::class, 'cancelClosure'])->name('account.closure.cancel');

    // A voluntary, self-service Pro trial (§Trial). No {organization} parameter,
    // same reasoning as account-closure above: always the current organization,
    // never one resolved from the URL.
    Route::get('settings/plan', [PlanController::class, 'edit'])->name('settings.plan.edit');
    Route::post('settings/plan/trial', [PlanController::class, 'activateTrial'])->name('settings.plan.activate-trial');

    /*
     * Checkout por transferência bancária. Sem parâmetro de plano na rota: só o
     * Pro tem preço, e deixar o plano vir do URL abriria a porta a subscrever o
     * Institucional pelo preço do Pro.
     */
    Route::get('settings/plan/checkout', [CheckoutController::class, 'create'])->name('settings.checkout.create');
    Route::post('settings/plan/checkout', [CheckoutController::class, 'store'])->name('settings.checkout.store');
    Route::get('settings/plan/checkout/{payment}', [CheckoutController::class, 'show'])->name('settings.checkout.show');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');

    /*
     * The school as it appears on a document.
     *
     * Gated by `module:reports`, because that is what it is FOR: the identity
     * exists so that Relatórios — a Base module — can head a document without
     * asking the teacher to type their school's name into every one. Reading
     * and writing are told apart by the policy, not by the plan.
     */
    Route::middleware('module:reports')->group(function () {
        Route::get('settings/school-identity', [SchoolIdentityController::class, 'edit'])
            ->name('settings.school-identity.edit');
        Route::put('settings/school-identity', [SchoolIdentityController::class, 'update'])
            ->name('settings.school-identity.update');
        Route::post('settings/school-identity/logo', [SchoolIdentityController::class, 'storeLogo'])
            ->name('settings.school-identity.logo.store');
        Route::delete('settings/school-identity/logo', [SchoolIdentityController::class, 'destroyLogo'])
            ->name('settings.school-identity.logo.destroy');
        // The file itself, streamed from the private disk after authorization.
        Route::get('settings/school-identity/logo', [SchoolIdentityController::class, 'logo'])
            ->name('settings.school-identity.logo');
    });
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
