<?php

namespace App\Actions\Organizations;

use App\Mail\OrganizationInvitationMail;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\Audit\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Invites someone into an institutional organization — or, if they were
 * already invited and have not yet answered, renews that same invitation
 * instead of leaving a second one standing next to it (Fatia 3, §10/§36 of
 * the multi-user brief).
 *
 * ONE ROW PER (organization, email) WHILE PENDING. Not a database constraint
 * — "pending" depends on `expires_at > now()`, which a unique index cannot
 * express — but a rule this action enforces by finding and reusing the
 * existing row rather than inserting a second one. This is also what "resend"
 * means here: a fresh token, a fresh expiry, the old link silently dead,
 * never two live tokens for the same invitation.
 *
 * The raw token exists only in memory, for exactly as long as it takes to
 * hash it and mail it. `token_hash` is what the database ever sees.
 */
class CreateOrRenewOrganizationInvitation
{
    public function __construct(protected AuditLog $audit) {}

    public function invite(Organization $organization, User $inviter, string $email): OrganizationInvitation
    {
        $email = mb_strtolower(trim($email));

        if (mb_strtolower($inviter->email) === $email) {
            throw ValidationException::withMessages(['email' => __('Não pode convidar-se a si próprio.')]);
        }

        $alreadyMember = $organization->members()
            ->whereRaw('lower(users.email) = ?', [$email])
            ->exists();

        if ($alreadyMember) {
            throw ValidationException::withMessages(['email' => __('Este utilizador já pertence à organização.')]);
        }

        return DB::transaction(function () use ($organization, $inviter, $email): OrganizationInvitation {
            $invitation = $organization->invitations()
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->whereNull('cancelled_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            $token = Str::random(64);

            if ($invitation !== null) {
                $invitation->forceFill([
                    'token_hash' => hash('sha256', $token),
                    'invited_by' => $inviter->getKey(),
                    'expires_at' => $this->expiresAt(),
                ])->save();
            } else {
                $invitation = $organization->invitations()->create([
                    'email' => $email,
                    'token_hash' => hash('sha256', $token),
                    'invited_by' => $inviter->getKey(),
                    'expires_at' => $this->expiresAt(),
                ]);
            }

            Mail::to($email)->send(new OrganizationInvitationMail($invitation, $organization, $inviter, $token));

            $this->audit->record(
                'organization.invitation_created',
                $invitation,
                causer: $inviter,
                summary: "Convite enviado para {$email}.",
            );

            return $invitation;
        });
    }

    protected function expiresAt(): Carbon
    {
        return Carbon::now()->addDays((int) config('invitations.expires_in_days'));
    }
}
