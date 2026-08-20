<?php

namespace App\Http\Controllers;

use App\Actions\Organizations\AcceptOrganizationInvitation;
use App\Models\User;
use App\Support\Organizations\InvitationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public side of an invitation (Fatia 3) — reached from the email link,
 * by someone who may be a guest, logged in as the right person, or logged in
 * as someone else entirely. No `auth` middleware on purpose: a guest reaching
 * this page is the expected, common case, not an error.
 */
class InvitationAcceptanceController extends Controller
{
    public function __construct(protected AcceptOrganizationInvitation $accept) {}

    public function show(Request $request, string $token): Response|RedirectResponse
    {
        try {
            $invitation = $this->accept->findByToken($token);
        } catch (InvitationException $exception) {
            // auth/ prefix, deliberately: the same minimal centered-card shell
            // Login and Register use, appropriate whether this visitor turns
            // out to be a guest or already signed in.
            return Inertia::render('auth/InvitationInvalid', ['message' => $exception->getMessage()]);
        }

        $currentUser = $request->user();

        if ($currentUser instanceof User) {
            if (mb_strtolower($currentUser->email) !== mb_strtolower($invitation->email)) {
                // Never accept on behalf of an email this session did not
                // prove — the only way forward is to end this session and
                // open the link again as a guest.
                return Inertia::render('auth/InvitationMismatch', [
                    'organizationName' => $invitation->organization->name,
                    'invitedEmail' => $invitation->email,
                    'currentEmail' => $currentUser->email,
                ]);
            }

            try {
                $this->accept->accept($invitation, $currentUser);
            } catch (InvitationException $exception) {
                return Inertia::render('auth/InvitationInvalid', ['message' => $exception->getMessage()]);
            }

            $request->session()->put('organization_id', $invitation->organization_id);
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Juntou-se a :organization.', ['organization' => $invitation->organization->name])]);

            return to_route('dashboard');
        }

        // A guest. Remember the token so the listener can resume this the
        // moment they finish logging in or registering — then send them
        // through whichever door actually applies to them.
        $request->session()->put('pending_invitation_token', $token);

        $hasAccount = User::query()->whereRaw('lower(email) = ?', [mb_strtolower($invitation->email)])->exists();

        if ($hasAccount) {
            $request->session()->flash('status', __('Inicie sessão para aceitar o convite de :organization.', ['organization' => $invitation->organization->name]));

            return to_route('login');
        }

        $request->session()->put('invitation_email', $invitation->email);
        $request->session()->flash('status', __('Crie a sua conta para aceitar o convite de :organization.', ['organization' => $invitation->organization->name]));

        return to_route('register');
    }
}
