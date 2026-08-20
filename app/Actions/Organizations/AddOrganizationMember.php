<?php

namespace App\Actions\Organizations;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Adds an EXISTING user to an organization they do not already belong to.
 *
 * Not an invitation (Fatia 3): the platform admin is vouching for the person
 * directly, in the backoffice, so this is the minimum technical step —
 * attaching a pivot row — with nothing to accept and nobody to email.
 *
 * Deliberately does nothing else. It does not touch `owner_id` — adding a
 * member is not a governance change. It does not touch the user's OTHER
 * memberships — a teacher keeps their personal organization exactly as it
 * was, because belonging to a school and having a workspace of one's own are
 * two different facts, and this fatia exists precisely so a person can hold
 * both at once.
 */
class AddOrganizationMember
{
    /**
     * @return bool true if a membership was created, false if the user already belonged (idempotent, not an error).
     */
    public function add(Organization $organization, User $user): bool
    {
        if ($organization->members()->whereKey($user->getKey())->exists()) {
            return false;
        }

        $organization->members()->attach($user, ['joined_at' => Carbon::now()]);

        return true;
    }
}
