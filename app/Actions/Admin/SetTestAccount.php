<?php

namespace App\Actions\Admin;

use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;

/**
 * Says whether an organization exists to TRY the product, and nothing else.
 *
 * The only writer of `organizations.is_test_account`, and the reason the column
 * is absent from the model's `#[Fillable]`. An account becomes a test account
 * because a named operator said so — never because of its email address, its
 * domain, its name, its plan, its id, or the absence of payments. Every one of
 * those inferences was considered and refused: they all guess a commercial fact
 * from a technical accident, which is the exact mistake the commercial preflight
 * exists to prevent.
 *
 * IT TOUCHES THE ORGANIZATION AND NOTHING ELSE. No subscription is read, locked
 * or written. `plan_id`, `plan_version_id`, `status`, `commercial_condition`,
 * `contracted_price_cents` and `commercial_term_ends_at` are not in this file,
 * and `Entitlements` is not even flushed — because nothing written here can
 * change an access decision. A test account has exactly the modules its plan
 * sells, before and after.
 *
 * WHAT THE MARK IS FOR: the commercial preflight stops a deploy when a
 * subscription in force carries no recorded terms. That question is only worth
 * asking about accounts somebody might owe something to. Marking an account as
 * a test account does not answer the commercial question — it says the question
 * does not apply, which is a different and much smaller claim.
 */
class SetTestAccount
{
    public function __construct(
        protected AuditLog $audit,
        protected CurrentOrganization $currentOrganization,
    ) {}

    /**
     * @param  bool  $isTestAccount  True marks it a test account; false returns it to a real one.
     * @param  string|null  $note  Why, in the operator's own words. Optional, trimmed, never inferred.
     */
    public function set(
        Organization $organization,
        User $operator,
        bool $isTestAccount,
        ?string $note = null,
    ): Organization {
        return DB::transaction(function () use ($organization, $operator, $isTestAccount, $note): Organization {
            $locked = Organization::query()
                ->whereKey($organization->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $previous = (bool) $locked->is_test_account;
            $note = $note === null ? null : (trim($note) === '' ? null : trim($note));

            // `forceFill`, because the column is not fillable — see the model.
            $locked->forceFill(['is_test_account' => $isTestAccount])->save();

            $this->currentOrganization->runFor($organization, fn () => $this->audit->record(
                $isTestAccount ? 'admin.test_account_marked' : 'admin.test_account_cleared',
                $organization,
                $operator,
                summary: $isTestAccount
                    ? 'Marcada como conta de teste.'
                    : 'Deixou de ser conta de teste.',
                properties: [
                    'previous_is_test_account' => $previous,
                    'is_test_account' => $isTestAccount,
                    'note' => $note,
                    // Recorded so the trail proves the subscription did NOT
                    // move — the whole guarantee of this action, stated in the
                    // evidence and not only in a docblock.
                    'organization_type' => $organization->type->value,
                ],
            ));

            return $locked;
        });
    }
}
