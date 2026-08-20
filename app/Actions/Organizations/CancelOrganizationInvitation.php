<?php

namespace App\Actions\Organizations;

use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\Audit\AuditLog;

/**
 * Cancels a pending invitation (Fatia 3, §35 of the multi-user brief).
 *
 * Sets `cancelled_at` rather than deleting the row — the same reasoning
 * AuditEvent's immutability follows: a cancelled invitation is itself a fact
 * worth keeping ("who was invited and then wasn't"), and AcceptOrganizationInvitation
 * already refuses a cancelled token on its own, so nothing downstream needs
 * the row gone to be safe.
 */
class CancelOrganizationInvitation
{
    public function __construct(protected AuditLog $audit) {}

    public function cancel(OrganizationInvitation $invitation, User $causer): void
    {
        if ($invitation->cancelled_at !== null || $invitation->accepted_at !== null) {
            return;
        }

        $invitation->forceFill(['cancelled_at' => now()])->save();

        $this->audit->record(
            'organization.invitation_cancelled',
            $invitation,
            causer: $causer,
            summary: "Convite para {$invitation->email} cancelado.",
        );
    }
}
