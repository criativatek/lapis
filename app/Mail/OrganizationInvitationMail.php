<?php

namespace App\Mail;

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * The invitation email (Fatia 3). Carries no pedagogical data — a school
 * name, who invited, when it expires, and the raw token as a link. The token
 * exists here and in the URL only; the database only ever sees its hash.
 */
class OrganizationInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public OrganizationInvitation $invitation,
        public Organization $organization,
        public User $inviter,
        public string $token,
    ) {}

    public function build(): self
    {
        return $this->subject(__(':organization convida-o para o Lapispro', ['organization' => $this->organization->name]))
            ->view('emails.organization-invitation', [
                'organizationName' => $this->organization->name,
                'inviterName' => $this->inviter->name,
                'acceptUrl' => route('invitations.show', $this->token),
                'expiresAt' => $this->invitation->expires_at,
            ]);
    }
}
