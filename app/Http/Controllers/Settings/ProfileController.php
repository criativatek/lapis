<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Accounts\CancelPersonalAccountClosure;
use App\Actions\Accounts\RequestPersonalAccountClosure;
use App\Actions\Users\DeleteUserAccount;
use App\Http\Controllers\Concerns\RefusesDuringImpersonation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Support\Accounts\AccountClosureException;
use App\Support\Retention\ClosureStatusPresenter;
use App\Support\Retention\RetentionPolicy;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    use RefusesDuringImpersonation;

    public function __construct(
        protected RequestPersonalAccountClosure $requestClosure,
        protected CancelPersonalAccountClosure $cancelClosure,
        protected ClosureStatusPresenter $closureStatus,
        protected RetentionPolicy $retentionPolicy,
    ) {}

    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            'closure' => $user->isClosureRequested()
                ? $this->closureStatus->personal($user->closure_requested_at, $user->scheduled_deletion_at)
                : null,
            'closureRetentionDays' => $this->retentionPolicy->personalAccountClosureDays(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Request the recoverable closure of the user's own personal account
     * (§5-§6 of the lifecycle brief). Never immediate, never the hard-delete
     * path below — see RequestPersonalAccountClosure.
     */
    public function requestClosure(Request $request): RedirectResponse
    {
        $this->refuseDuringImpersonation($request);

        try {
            $this->requestClosure->request($request->user());
        } catch (AccountClosureException $exception) {
            return back()->withErrors(['account' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Encerramento da conta pedido.')]);

        return to_route('profile.edit');
    }

    /**
     * Reactivate a personal account within its recovery window (§7).
     */
    public function cancelClosure(Request $request): RedirectResponse
    {
        $this->refuseDuringImpersonation($request);

        try {
            $this->cancelClosure->cancel($request->user());
        } catch (AccountClosureException $exception) {
            return back()->withErrors(['account' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Conta reativada.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile — immediate, irreversible, exceptional
     * (§34 of the lifecycle brief; not the door most people use, which is
     * requestClosure() above). Guarded inside DeleteUserAccount itself, so
     * the account stays logged in and untouched when that guard refuses.
     *
     * Deletes a FRESH copy, not $request->user() itself: that object is the
     * same instance Auth::logout() below calls save() on (to cycle the
     * remember token). Eloquent flips exists=false on the instance a
     * successful delete() runs on — reusing it for logout would make that
     * save() an INSERT instead of an UPDATE and resurrect the very row just
     * deleted. Acting on a separate instance keeps the two unrelated.
     */
    public function destroy(ProfileDeleteRequest $request, DeleteUserAccount $deleteUserAccount): RedirectResponse
    {
        $user = $request->user();

        try {
            $deleteUserAccount->delete($user->fresh() ?? $user);
        } catch (AccountClosureException $exception) {
            return back()->withErrors(['account' => $exception->getMessage()]);
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
