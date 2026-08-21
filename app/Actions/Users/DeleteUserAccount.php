<?php

namespace App\Actions\Users;

use App\Models\OrganizationType;
use App\Models\User;
use App\Support\Accounts\AccountClosureException;
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
 * The ownership-transfer flow now exists (TeamController::transferOwnership,
 * Fatia 4), so a caller has somewhere to send the operator: this guards
 * explicitly and names it, rather than letting a QueryException be the first
 * thing anyone sees (§35 of the lifecycle brief).
 */
class DeleteUserAccount
{
    public function delete(User $user): void
    {
        if ($user->ownedOrganizations()->where('type', OrganizationType::Institutional)->exists()) {
            throw AccountClosureException::ownsInstitution();
        }

        DB::transaction(function () use ($user): void {
            foreach ($user->ownedOrganizations()->where('type', OrganizationType::Personal)->get() as $organization) {
                $organization->delete();
            }

            $user->delete();
        });
    }
}
