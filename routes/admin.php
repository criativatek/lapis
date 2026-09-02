<?php

use App\Http\Controllers\Admin\AdminAccountController;
use App\Http\Controllers\Admin\AdminAiController;
use App\Http\Controllers\Admin\AdminCommercialController;
use App\Http\Controllers\Admin\AdminImpersonateController;
use App\Http\Controllers\Admin\AdminSettingsController;
use App\Http\Controllers\Admin\AdminSupportController;
use App\Http\Controllers\Admin\AdminVoucherController;
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

        // «Conta de teste». Deliberately here and not under `commercial`: it is
        // a fact about the ORGANIZATION, not a condition of its subscription,
        // and it grants nothing. The commercial preflight is its only reader.
        Route::post('accounts/{organization}/test-account', [AdminAccountController::class, 'setTestAccount'])->name('accounts.test-account');

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

        // Admin > Comercial. Read-first: the two writes it carries record FACTS
        // about an account (money received, the condition it was sold under) and
        // never grant it anything — changing a plan stays in `accounts.plan`
        // above, where it always was.
        //
        // `export` and `payments` BEFORE `{organization}`, for the same reason
        // `accounts/create` comes before its own wildcard: otherwise "export"
        // binds as an organization ulid and 404s.
        Route::get('commercial', [AdminCommercialController::class, 'index'])->name('commercial.index');
        Route::get('commercial/export', [AdminCommercialController::class, 'export'])->name('commercial.export');

        // Vouchers — emitir e desactivar; nunca editar nem apagar (o modelo é
        // imutável depois de emitido). BEFORE `commercial/{organization}`, or
        // "vouchers" binds as an organization ulid and 404s.
        Route::get('commercial/vouchers', [AdminVoucherController::class, 'index'])->name('commercial.vouchers.index');
        Route::post('commercial/vouchers', [AdminVoucherController::class, 'store'])->name('commercial.vouchers.store');
        Route::post('commercial/vouchers/{voucher}/disable', [AdminVoucherController::class, 'disable'])->name('commercial.vouchers.disable');
        Route::post('commercial/payments/{payment}/confirm', [AdminCommercialController::class, 'confirmTransfer'])->name('commercial.payments.confirm');
        Route::post('commercial/payments/{payment}/refund', [AdminCommercialController::class, 'refundPayment'])->name('commercial.payments.refund');
        Route::post('commercial/payments/{payment}/void', [AdminCommercialController::class, 'voidPayment'])->name('commercial.payments.void');
        Route::get('commercial/{organization}', [AdminCommercialController::class, 'show'])->name('commercial.show');
        Route::post('commercial/{organization}/condition', [AdminCommercialController::class, 'setCommercialCondition'])->name('commercial.condition');
        Route::post('commercial/{organization}/payments', [AdminCommercialController::class, 'storePayment'])->name('commercial.payments.store');

        // Admin > Suporte. A fronteira de acesso é o middleware desta secção e
        // só ele: `SupportRequestPolicy` responde por quem é titular de um
        // pedido, e dar-lhe um ramo para o operador seria uma segunda porta
        // para a mesma sala.
        Route::get('support', [AdminSupportController::class, 'index'])->name('support.index');
        Route::get('support/{support}', [AdminSupportController::class, 'show'])->name('support.show');
        Route::post('support/{support}/reply', [AdminSupportController::class, 'reply'])->name('support.reply');
        Route::post('support/{support}/status', [AdminSupportController::class, 'changeStatus'])->name('support.status');
        Route::post('support/{support}/classify', [AdminSupportController::class, 'classify'])->name('support.classify');
        Route::post('support/{support}/severity', [AdminSupportController::class, 'setSeverity'])->name('support.severity');
        Route::post('support/{support}/assign', [AdminSupportController::class, 'assign'])->name('support.assign');
        // Exportar para o rastreador externo. Throttled: cada clique abre um
        // issue de verdade num sistema que não é nosso.
        Route::post('support/{support}/export', [AdminSupportController::class, 'export'])
            ->middleware('throttle:10,1')
            ->name('support.export');
        Route::post('support/{support}/hold', [AdminSupportController::class, 'applyHold'])->name('support.hold.apply');
        Route::delete('support/{support}/hold', [AdminSupportController::class, 'releaseHold'])->name('support.hold.release');
        // Reenviar um aviso que não chegou. Throttled: reconstrói e envia um
        // email de verdade a cada clique.
        Route::post('support/{support}/resend', [AdminSupportController::class, 'resendNotification'])
            ->middleware('throttle:10,1')
            ->name('support.resend');

        // System email (SMTP) settings.
        Route::get('settings', [AdminSettingsController::class, 'edit'])->name('settings.edit');
        Route::put('settings', [AdminSettingsController::class, 'update'])->name('settings.update');
        Route::post('settings/test', [AdminSettingsController::class, 'test'])->name('settings.test');

        // Inteligência Artificial — the engine, its ceilings, and its credential.
        //
        // THE CREDENTIAL HAS ITS OWN VERBS, and there is deliberately no GET
        // that returns it. Storing one is a POST to its own endpoint, with its
        // own audit event and its own no-flash rule; removing one is a DELETE.
        // «Substituir credencial» is an action; «mostrar chave» is not a route
        // that exists (§4 of the AI Core brief).
        Route::get('ai', [AdminAiController::class, 'edit'])->name('ai.edit');
        Route::put('ai', [AdminAiController::class, 'update'])->name('ai.update');
        Route::post('ai/credential', [AdminAiController::class, 'storeCredential'])->name('ai.credential.store');
        Route::delete('ai/credential', [AdminAiController::class, 'destroyCredential'])->name('ai.credential.destroy');
        // Spends real tokens on the operator's own account, so it is throttled.
        // An idle click is cheap; a stuck one, or a held-down button, is not.
        Route::post('ai/test', [AdminAiController::class, 'test'])
            ->middleware('throttle:6,1')
            ->name('ai.test');

        // The capability probe sends a full instruction and asks for a full
        // answer, so one click costs roughly what a real síntese costs —
        // materially more than «OK». Throttled harder for that reason alone:
        // the ceiling is about spend, not about abuse, and this route is
        // already behind `platform-admin`.
        Route::post('ai/probe', [AdminAiController::class, 'probe'])
            ->middleware('throttle:3,1')
            ->name('ai.probe');

        // Start impersonating the org's owner (support).
        Route::post('accounts/{organization}/impersonate', [AdminImpersonateController::class, 'start'])->name('accounts.impersonate');
    });

// Stop impersonation — reached AS the impersonated teacher, so it is only `auth`,
// not `platform-admin`.
Route::middleware('auth')->post('impersonate/stop', [AdminImpersonateController::class, 'stop'])->name('impersonate.stop');
