<?php

namespace App\Actions\Organizations;

use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Organizations\InvitationException;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;

/**
 * Turns a raw invitation token into a membership (Fatia 3).
 *
 * The safe order the brief asks for, and the reason each step is where it is:
 * find and validate the token BEFORE touching anything; add the membership
 * (via AddOrganizationMember — Fatia 2's action, not reimplemented here)
 * BEFORE marking the invitation accepted, so a crash between the two leaves a
 * still-pending invitation rather than an accepted one with nobody actually
 * added. The audit event is stamped under the ORGANIZATION being joined, via
 * `runFor` — the joiner very likely has no OTHER tenant resolved yet at this
 * point in the request (a brand-new registration, or mid-login).
 */
class AcceptOrganizationInvitation
{
    public function __construct(
        protected AddOrganizationMember $addMember,
        protected AuditLog $audit,
        protected CurrentOrganization $currentOrganization,
    ) {}

    /**
     * @throws InvitationException
     */
    public function findByToken(string $token): OrganizationInvitation
    {
        $invitation = OrganizationInvitation::query()
            ->withoutGlobalScope('organization')
            ->where('token_hash', hash('sha256', $token))
            ->with('organization')
            ->first();

        if ($invitation === null) {
            throw InvitationException::notFound();
        }

        if ($invitation->cancelled_at !== null) {
            throw InvitationException::cancelled();
        }

        if ($invitation->accepted_at !== null) {
            throw InvitationException::alreadyAccepted();
        }

        if ($invitation->expires_at->isPast()) {
            throw InvitationException::expired();
        }

        return $invitation;
    }

    /**
     * @throws InvitationException
     */
    public function accept(OrganizationInvitation $invitation, User $user): void
    {
        if (mb_strtolower($user->email) !== mb_strtolower($invitation->email)) {
            throw InvitationException::emailMismatch();
        }

        // Re-validated under lock: the token could have been cancelled, or
        // raced by a second acceptance attempt, in the moment between
        // findByToken() and the user proving which email they control.
        DB::transaction(function () use ($invitation, $user): void {
            $locked = OrganizationInvitation::query()
                ->withoutGlobalScope('organization')
                ->whereKey($invitation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->cancelled_at !== null) {
                throw InvitationException::cancelled();
            }

            if ($locked->accepted_at !== null) {
                throw InvitationException::alreadyAccepted();
            }

            if ($locked->expires_at->isPast()) {
                throw InvitationException::expired();
            }

            $organization = $invitation->organization;

            $this->addMember->add($organization, $user);

            // The invitation link was only reachable by opening an email sent
            // to this exact address — the same proof email verification
            // exists to establish. Asking again would be the redundant
            // verification the multi-user brief says to avoid (§33).
            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            $locked->forceFill([
                'accepted_at' => now(),
                'accepted_by' => $user->getKey(),
            ])->save();

            $this->currentOrganization->runFor(
                $organization,
                fn () => $this->audit->record(
                    'organization.invitation_accepted',
                    $locked,
                    causer: $user,
                    summary: "{$user->email} juntou-se à organização.",
                ),
            );
        });
    }
}
