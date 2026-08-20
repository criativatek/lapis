<?php

namespace App\Listeners;

use App\Actions\Organizations\AcceptOrganizationInvitation;
use App\Models\User;
use App\Support\Organizations\InvitationException;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Session;
use Inertia\Inertia;

/**
 * Resumes an invitation acceptance the person was sent to /login or /register
 * to complete (Fatia 3, §16/§17 of the multi-user brief).
 *
 * Fires on EVERY login in the app — ordinary ones included — and does nothing
 * unless `pending_invitation_token` is actually in session, which only
 * InvitationAcceptanceController ever puts there. Fortify logs a freshly
 * registered user in immediately, firing this same event, so one listener
 * covers both "had an account already" and "just created one" with no
 * duplicated logic and no separate hook into registration. Fortify's own
 * registration transaction (CreateNewUser) has already committed by the time
 * this fires — Auth::login() runs after that transaction's closure returns —
 * so there is nothing left open to wait for.
 */
class ResumePendingInvitationAfterLogin
{
    public function __construct(protected AcceptOrganizationInvitation $accept) {}

    public function handle(Login $event): void
    {
        $token = Session::pull('pending_invitation_token');

        if ($token === null || ! $event->user instanceof User) {
            return;
        }

        $user = $event->user;

        try {
            $invitation = $this->accept->findByToken($token);
        } catch (InvitationException) {
            // The link went stale between "we sent them to register" and "they
            // finished" — nothing to resume, and nothing worth interrupting a
            // successful login/registration over.
            return;
        }

        try {
            $this->accept->accept($invitation, $user);
        } catch (InvitationException) {
            // Most likely an email mismatch: they registered/logged in with a
            // different address than the one invited. Silently dropping the
            // invitation is correct here — never accept on behalf of an email
            // this session did not just prove.
            return;
        }

        Session::put('organization_id', $invitation->organization_id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Juntou-se a :organization.', ['organization' => $invitation->organization->name])]);
    }
}
