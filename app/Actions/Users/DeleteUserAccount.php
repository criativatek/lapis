<?php

namespace App\Actions\Users;

use App\Models\OrganizationType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Closes an account and removes the workspaces that only existed to hold it.
 *
 * A personal organization is the teacher's own workspace and has no life without
 * them, so it goes with the account. An institutional organization does not: it
 * may hold other teachers' classes and their students' records. Deleting the
 * owner's account must never take a school down with it, so organizations.owner_id
 * is ON DELETE RESTRICT and the database refuses that delete outright.
 *
 * ponytail: no ownership-transfer flow yet — nothing creates institutional
 * organizations before the institutional phase, so the FK is the whole guard.
 * When that phase lands, this action needs a "transfer ownership first" path
 * instead of relying on the constraint to fail.
 */
class DeleteUserAccount
{
    public function delete(User $user): void
    {
        DB::transaction(function () use ($user): void {
            foreach ($user->ownedOrganizations()->where('type', OrganizationType::Personal)->get() as $organization) {
                $organization->delete();
            }

            $user->delete();
        });
    }
}
